<?php

namespace Tests\Feature;

use App\Filament\Pages\Inbox;
use App\Filament\Pages\MetaSettings;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Guest;
use App\Models\MetaSetting;
use App\Models\User;
use App\Support\Inbox\InboxException;
use App\Support\Inbox\SampleInbox;
use App\Support\InboxAccess;
use App\Support\Meta\MetaClient;
use App\Support\Meta\PageConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin inbox: Messenger chats from each branch's Facebook Page.
 */
class InboxTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'meta-app-secret-5e4d3c2b1a';

    private const PAGE_TOKEN = 'page-token-xyz';

    private const GRAPH = 'https://graph.facebook.com/v26.0';

    private Branch $cainta;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        MetaSetting::instance()->forceFill(['app_id' => '555666777888999', 'app_secret' => self::APP_SECRET, 'enabled' => true])->save();
        $this->cainta = $this->connectedBranch('Cainta', '100200300400500');
        $this->sanMateo = $this->connectedBranch('San Mateo', '100200300400501');
    }

    private function connectedBranch(string $name, string $pageId): Branch
    {
        return Branch::factory()->create([
            'name' => $name,
            'facebook_page_id' => $pageId,
            'facebook_page_name' => "826 {$name}",
            'facebook_page_token' => self::PAGE_TOKEN,
            'facebook_connected_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function messageEvent(Branch $branch, string $psid, array $message, bool $echo = false, int $timestampMs = 1793520000000): array
    {
        $page = $branch->facebook_page_id;

        return [
            'object' => 'page',
            'entry' => [[
                'id' => $page,
                'time' => $timestampMs,
                'messaging' => [[
                    'sender' => ['id' => $echo ? $page : $psid],
                    'recipient' => ['id' => $echo ? $psid : $page],
                    'timestamp' => $timestampMs,
                    'message' => [...$message, ...($echo ? ['is_echo' => true] : [])],
                ]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload, ?string $secret = null): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? self::APP_SECRET),
        ], $body);
    }

    private function fakeProfile(string $psid = 'psid-1'): void
    {
        Http::fake([self::GRAPH."/{$psid}*" => Http::response(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'profile_pic' => 'https://platform-lookaside.fbsbx.com/juan.jpg', 'id' => $psid])]);
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function staffOf(Branch $branch): User
    {
        $staff = User::factory()->create();
        $staff->branches()->attach($branch);

        return $staff;
    }

    private function chat(Branch $branch, string $psid, int $unread = 1, array $attributes = []): Conversation
    {
        $conversation = Conversation::create([
            'branch_id' => $branch->id,
            'external_id' => $psid,
            'customer_name' => "Customer {$psid}",
            'unread_count' => $unread,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            ...$attributes,
        ]);
        ConversationMessage::create(['conversation_id' => $conversation->id, 'direction' => 'in', 'body' => 'Hello po', 'sent_at' => now()]);

        return $conversation;
    }

    // Webhook

    public function test_meta_can_verify_the_webhook_url_with_the_verify_token(): void
    {
        $token = MetaSetting::instance()->webhook_verify_token;
        $this->assertSame(40, strlen($token));

        $this->get("/webhooks/meta?hub.mode=subscribe&hub.verify_token={$token}&hub.challenge=1158201444")
            ->assertOk()
            ->assertContent('1158201444');

        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1158201444')->assertForbidden();
        $this->get("/webhooks/meta?hub.mode=unsubscribe&hub.verify_token={$token}&hub.challenge=1")->assertForbidden();
    }

    public function test_a_webhook_with_a_bad_signature_or_no_app_secret_is_refused(): void
    {
        $payload = $this->messageEvent($this->cainta, 'psid-1', ['mid' => 'm_1', 'text' => 'Hi']);

        $this->postWebhook($payload, secret: 'not-the-secret')->assertUnauthorized();
        $this->call('POST', '/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload))->assertUnauthorized();
        $this->assertSame(0, Conversation::count());

        MetaSetting::instance()->forceFill(['app_secret' => null])->save();
        $this->postWebhook($payload)->assertUnauthorized();
    }

    public function test_a_signed_message_is_stored_under_the_branch_of_the_page_with_the_customers_name(): void
    {
        $this->fakeProfile();

        $this->postWebhook($this->messageEvent($this->sanMateo, 'psid-1', [
            'mid' => 'm_1',
            'text' => 'May slot pa po?',
            'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/photo.jpg']]],
        ]))->assertOk();

        $conversation = Conversation::sole();
        $this->assertSame($this->sanMateo->id, $conversation->branch_id);
        $this->assertSame(['messenger', 'psid-1', 'Juan Dela Cruz', 1, 'open'], [$conversation->channel, $conversation->external_id, $conversation->customer_name, $conversation->unread_count, $conversation->status]);
        $this->assertSame('https://platform-lookaside.fbsbx.com/juan.jpg', $conversation->avatar);
        $this->assertSame(1793520000, $conversation->last_inbound_at->getTimestamp());

        $message = ConversationMessage::sole();
        $this->assertSame(['in', 'May slot pa po?', [['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/photo.jpg', 'name' => null]]], [$message->direction, $message->body, $message->attachments]);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.self::PAGE_TOKEN)
            && str_contains($request->url(), 'appsecret_proof='.MetaClient::appSecretProof(self::PAGE_TOKEN, self::APP_SECRET)));
    }

    public function test_the_same_message_delivered_twice_is_stored_once(): void
    {
        $this->fakeProfile();
        $payload = $this->messageEvent($this->cainta, 'psid-1', ['mid' => 'm_dup', 'text' => 'Hi']);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame(1, Conversation::sole()->unread_count);
    }

    public function test_pages_of_no_branch_and_a_switched_off_app_are_ignored(): void
    {
        $other = new Branch(['facebook_page_id' => '999']);
        $this->postWebhook($this->messageEvent($other, 'psid-1', ['mid' => 'm_1', 'text' => 'Hi']))->assertOk();
        $this->assertSame(0, Conversation::count());

        MetaSetting::instance()->forceFill(['enabled' => false])->save();
        $this->postWebhook($this->messageEvent($this->cainta, 'psid-1', ['mid' => 'm_2', 'text' => 'Hi']))->assertOk();
        $this->assertSame(0, Conversation::count());
    }

    public function test_unread_counts_up_and_a_reply_from_meta_business_suite_clears_it(): void
    {
        $this->fakeProfile();

        $this->postWebhook($this->messageEvent($this->cainta, 'psid-1', ['mid' => 'm_1', 'text' => 'Hi'], timestampMs: 1793520000000));
        $this->postWebhook($this->messageEvent($this->cainta, 'psid-1', ['mid' => 'm_2', 'text' => 'Hello?'], timestampMs: 1793520060000));
        $this->assertSame(2, Conversation::sole()->unread_count);

        $this->postWebhook($this->messageEvent($this->cainta, 'psid-1', ['mid' => 'm_3', 'text' => 'Hi po!'], echo: true, timestampMs: 1793520120000));

        $conversation = Conversation::sole()->fresh();
        $this->assertSame(0, $conversation->unread_count);
        $this->assertSame('You: Hi po!', $conversation->last_message_preview);
        $this->assertSame('Meta inbox', ConversationMessage::where('external_id', 'm_3')->sole()->sender_name);
    }

    public function test_a_new_message_in_a_done_chat_reopens_it(): void
    {
        $this->chat($this->cainta, 'psid-1', 0, ['status' => 'done']);

        $this->postWebhook($this->messageEvent($this->cainta, 'psid-1', ['mid' => 'm_9', 'text' => 'Isa pa po']))->assertOk();

        $this->assertSame('open', Conversation::sole()->status);
    }

    public function test_a_new_message_notifies_the_branch_staff_and_owners_only_once_in_a_while(): void
    {
        $owner = $this->owner();
        $mine = $this->staffOf($this->cainta);
        $theirs = $this->staffOf($this->sanMateo);
        $this->fakeProfile();

        $this->postWebhook($this->messageEvent($this->cainta, 'psid-1', ['mid' => 'm_1', 'text' => 'Hi', 'attachments' => []], timestampMs: now()->getTimestampMs()));
        $this->postWebhook($this->messageEvent($this->cainta, 'psid-1', ['mid' => 'm_2', 'text' => 'Hi again'], timestampMs: now()->getTimestampMs()));

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $mine->notifications()->count());
        $this->assertSame(0, $theirs->notifications()->count());
        $this->assertStringContainsString('Juan Dela Cruz', $mine->notifications()->first()->data['title']);
    }

    // Staff scoping

    public function test_staff_see_only_their_branch_chats_and_owners_see_all(): void
    {
        $mine = $this->chat($this->cainta, 'psid-mine');
        $theirs = $this->chat($this->sanMateo, 'psid-theirs');

        $this->actingAs($this->staffOf($this->cainta));
        Livewire::test(Inbox::class)
            ->assertSee('Customer psid-mine')
            ->assertDontSee('Customer psid-theirs')
            ->assertDontSee('San Mateo')
            ->call('open', $theirs->id)
            ->assertNotFound();

        $this->actingAs($this->owner());
        Livewire::test(Inbox::class)->assertSee('Customer psid-mine')->assertSee('Customer psid-theirs')->assertSee('San Mateo');

        $this->assertSame([$mine->id], Conversation::visibleTo($this->staffOf($this->cainta))->pluck('id')->all());
    }

    public function test_the_unread_badge_counts_only_chats_the_user_may_see(): void
    {
        $this->chat($this->cainta, 'a');
        $this->chat($this->cainta, 'b');
        $this->chat($this->sanMateo, 'c');

        $this->actingAs($this->staffOf($this->cainta));
        $this->assertSame('2', Inbox::getNavigationBadge());

        $this->actingAs($this->owner());
        $this->assertSame('3', Inbox::getNavigationBadge());
    }

    public function test_only_people_with_a_branch_or_owners_open_the_inbox(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(Inbox::canAccess());
        $this->assertFalse(InboxAccess::allows(auth()->user()));

        $this->actingAs($this->staffOf($this->cainta));
        $this->assertTrue(Inbox::canAccess());

        $this->actingAs($this->owner());
        $this->assertTrue(Inbox::canAccess());
        $this->assertTrue(MetaSettings::canAccess());
    }

    public function test_the_tabs_filter_by_branch_and_the_filters_by_state(): void
    {
        $this->chat($this->cainta, 'cainta-open');
        $this->chat($this->sanMateo, 'sm-open', 0);
        $this->chat($this->sanMateo, 'sm-done', 0, ['status' => 'done']);

        $this->actingAs($this->owner());
        Livewire::test(Inbox::class)
            ->assertSee('Customer cainta-open')->assertSee('Customer sm-open')->assertDontSee('Customer sm-done')
            ->set('branch', (string) $this->sanMateo->id)
            ->assertDontSee('Customer cainta-open')->assertSee('Customer sm-open')
            ->set('filter', 'done')->assertSee('Customer sm-done')->assertDontSee('Customer sm-open')
            ->set('filter', 'unread')->assertDontSee('Customer sm-open')
            ->set('branch', 'all')->set('filter', 'all')->set('search', 'sm-')->assertSee('Customer sm-done')->assertDontSee('Customer cainta-open');
    }

    public function test_opening_marks_read_and_the_chat_can_be_marked_done_and_assigned_and_linked(): void
    {
        Http::fake([self::GRAPH.'/*' => Http::response(['recipient_id' => 'x'])]);
        $conversation = $this->chat($this->cainta, 'psid-1', 3);
        $staff = $this->staffOf($this->cainta);
        $guest = Guest::factory()->walkIn()->create(['name' => 'Maria Santos', 'branch_id' => $this->cainta->id]);
        $this->actingAs($this->owner());

        Livewire::test(Inbox::class, ['conversation' => $conversation->id])
            ->call('toggleDone')
            ->callAction('assign', ['assigned_to' => $staff->id])
            ->callAction('linkCustomer', ['guest_id' => $guest->id]);

        $conversation->refresh();
        $this->assertSame([0, 'done', $staff->id, $guest->id], [$conversation->unread_count, $conversation->status, $conversation->assigned_to, $conversation->guest_id]);

        Livewire::test(Inbox::class, ['conversation' => $conversation->id])->call('toggleDone');
        $this->assertSame('open', $conversation->fresh()->status);
    }

    public function test_a_staff_member_cannot_link_a_customer_of_another_branch_or_assign_a_stranger(): void
    {
        $conversation = $this->chat($this->cainta, 'psid-1');
        $stranger = $this->staffOf($this->sanMateo);
        $foreign = Guest::factory()->walkIn()->create(['branch_id' => $this->sanMateo->id]);

        $this->actingAs($this->staffOf($this->cainta));
        Livewire::test(Inbox::class, ['conversation' => $conversation->id])->callAction('assign', ['assigned_to' => $stranger->id])->assertHasActionErrors();
        Livewire::test(Inbox::class, ['conversation' => $conversation->id])->callAction('linkCustomer', ['guest_id' => $foreign->id])->assertHasActionErrors();

        $conversation->refresh();
        $this->assertNull($conversation->assigned_to);
        $this->assertNull($conversation->guest_id);
    }

    public function test_the_customer_page_links_to_the_linked_chat(): void
    {
        $guest = Guest::factory()->walkIn()->create();
        $conversation = $this->chat($this->cainta, 'psid-1', 0, ['guest_id' => $guest->id]);

        $this->actingAs($this->owner());
        Livewire::test(ViewCustomer::class, ['record' => $guest->getKey()])
            ->assertActionVisible('messenger');

        $this->assertStringContainsString("conversation={$conversation->id}", Inbox::getUrl(['conversation' => $conversation->id]));
    }

    // Branch privacy: a branch's chats are private to that branch

    public function test_staff_of_one_branch_cannot_list_view_search_open_or_count_another_branchs_chats(): void
    {
        $this->chat($this->cainta, 'mine-1');
        $theirs = $this->chat($this->sanMateo, 'secret-1', 5, ['customer_name' => 'Zed Secretman']);
        ConversationMessage::create(['conversation_id' => $theirs->id, 'direction' => 'in', 'body' => 'private words', 'attachments' => [['type' => 'image', 'url' => 'https://example.com/private.jpg']], 'sent_at' => now()]);
        $staff = $this->staffOf($this->cainta);
        $this->actingAs($staff);

        $this->assertFalse($staff->can('view', $theirs));
        $this->assertFalse($staff->can('update', $theirs));
        $this->assertTrue($staff->can('view', Conversation::where('external_id', 'mine-1')->first()));
        $this->assertSame(1, Conversation::visibleTo($staff)->count());
        $this->assertSame('1', Inbox::getNavigationBadge());

        Livewire::test(Inbox::class)
            ->set('filter', 'all')->set('search', 'Secretman')->assertDontSee('Zed Secretman')
            ->set('search', 'private words')->assertDontSee('Zed Secretman')->assertDontSee('private.jpg')
            ->set('search', '')->set('branch', (string) $this->sanMateo->id)
            ->assertSet('branch', (string) $this->cainta->id)
            ->assertDontSee('Zed Secretman');

        // Opened by URL
        Livewire::withQueryParams(['conversation' => $theirs->id])->test(Inbox::class)->assertNotFound();
    }

    public function test_a_forged_conversation_id_cannot_be_replied_to_marked_done_assigned_or_linked(): void
    {
        Http::fake();
        $theirs = $this->chat($this->sanMateo, 'secret-1', 5);
        $own = $this->staffOf($this->sanMateo);
        $guest = Guest::factory()->walkIn()->create(['branch_id' => $this->cainta->id]);
        $this->actingAs($this->staffOf($this->cainta));

        foreach (['send', 'toggleDone'] as $method) {
            Livewire::test(Inbox::class)->set('conversation', $theirs->id)->set('reply', 'hijack')->call($method)->assertNotFound();
        }

        foreach ([['assign', ['assigned_to' => $own->id]], ['linkCustomer', ['guest_id' => $guest->id]]] as [$action, $data]) {
            Livewire::test(Inbox::class)->set('conversation', $theirs->id)->callAction($action, $data);
        }

        $this->assertSame([5, 'open', null, null], [$theirs->fresh()->unread_count, $theirs->fresh()->status, $theirs->fresh()->assigned_to, $theirs->fresh()->guest_id]);
        $this->assertSame(0, ConversationMessage::where('direction', 'out')->count());
        Http::assertNothingSent();
    }

    public function test_a_user_with_two_branches_sees_both_and_nothing_else_and_gets_an_all_tab(): void
    {
        $third = $this->connectedBranch('Antipolo', '100200300400502');
        $this->chat($this->cainta, 'in-cainta');
        $this->chat($this->sanMateo, 'in-sanmateo');
        $this->chat($third, 'in-antipolo');
        $both = $this->staffOf($this->cainta);
        $both->branches()->attach($this->sanMateo);
        $this->actingAs($both);

        $this->assertSame(2, Conversation::visibleTo($both)->count());
        $this->assertSame('2', Inbox::getNavigationBadge());

        Livewire::test(Inbox::class)
            ->assertSet('branch', 'all')
            ->assertSee('in-cainta')->assertSee('in-sanmateo')->assertDontSee('in-antipolo')
            ->assertSee('All')->assertDontSee('Antipolo');
    }

    public function test_single_branch_staff_have_no_all_tab_and_the_super_admin_sees_every_branch(): void
    {
        $this->chat($this->cainta, 'in-cainta');
        $this->chat($this->sanMateo, 'in-sanmateo');

        $this->actingAs($this->staffOf($this->cainta));
        $page = Livewire::test(Inbox::class);
        $this->assertFalse($page->instance()->showsAllTab());
        $page->assertSet('branch', (string) $this->cainta->id)->assertSee('in-cainta')->assertDontSee('in-sanmateo');

        $this->actingAs($this->owner());
        $owner = Livewire::test(Inbox::class);
        $this->assertTrue($owner->instance()->showsAllTab());
        $owner->assertSet('branch', 'all')->assertSee('in-cainta')->assertSee('in-sanmateo');
        $this->assertSame(2, Conversation::visibleTo(auth()->user())->count());
    }

    public function test_staff_without_a_branch_see_no_chats_at_all(): void
    {
        $this->chat($this->cainta, 'in-cainta');
        $nobody = User::factory()->create();

        $this->assertSame(0, Conversation::visibleTo($nobody)->count());
        $this->assertFalse($nobody->can('viewAny', Conversation::class));
    }

    public function test_the_assign_list_only_offers_people_of_the_chats_branch(): void
    {
        $conversation = $this->chat($this->cainta, 'psid-1');
        $mine = $this->staffOf($this->cainta);
        $other = $this->staffOf($this->sanMateo);
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(Inbox::class, ['conversation' => $conversation->id])->callAction('assign', ['assigned_to' => $other->id])->assertHasActionErrors();
        Livewire::test(Inbox::class, ['conversation' => $conversation->id])->callAction('assign', ['assigned_to' => $owner->id])->assertHasActionErrors();
        $this->assertNull($conversation->fresh()->assigned_to);

        Livewire::test(Inbox::class, ['conversation' => $conversation->id])->callAction('assign', ['assigned_to' => $mine->id]);
        $this->assertSame($mine->id, $conversation->fresh()->assigned_to);
    }

    public function test_notifications_go_only_to_that_branchs_staff_and_the_super_admin(): void
    {
        $owner = $this->owner();
        $caintaStaff = $this->staffOf($this->cainta);
        $sanMateoStaff = $this->staffOf($this->sanMateo);
        $both = $this->staffOf($this->cainta);
        $both->branches()->attach($this->sanMateo);
        $this->fakeProfile();

        $this->postWebhook($this->messageEvent($this->sanMateo, 'psid-1', ['mid' => 'm_1', 'text' => 'Hi'], timestampMs: now()->getTimestampMs()))->assertOk();

        $this->assertSame([1, 0, 1, 1], [$owner->notifications()->count(), $caintaStaff->notifications()->count(), $sanMateoStaff->notifications()->count(), $both->notifications()->count()]);
    }

    public function test_the_customer_page_only_links_to_a_chat_the_user_may_open(): void
    {
        $guest = Guest::factory()->walkIn()->create(['branch_id' => $this->cainta->id]);
        $this->chat($this->sanMateo, 'psid-other', 0, ['guest_id' => $guest->id]);

        $this->actingAs($this->staffOf($this->cainta));
        Livewire::test(ViewCustomer::class, ['record' => $guest->getKey()])
            ->assertActionHidden('messenger');

        $this->actingAs($this->owner());
        Livewire::test(ViewCustomer::class, ['record' => $guest->getKey()])
            ->assertActionVisible('messenger');
    }

    // Sending

    public function test_a_reply_goes_out_through_the_branch_page_and_the_echo_is_not_stored_twice(): void
    {
        Http::fake([self::GRAPH.'/100200300400500/messages*' => Http::response(['recipient_id' => 'psid-1', 'message_id' => 'm_out_1'])]);
        $conversation = $this->chat($this->cainta, 'psid-1');
        $staff = $this->staffOf($this->cainta);
        $this->actingAs($staff);

        Livewire::test(Inbox::class, ['conversation' => $conversation->id])
            ->set('reply', 'Opo, may slot pa po kami.')
            ->call('send')
            ->assertSet('reply', '');

        $message = ConversationMessage::where('direction', 'out')->sole();
        $this->assertSame(['m_out_1', 'Opo, may slot pa po kami.', $staff->id, false], [$message->external_id, $message->body, $message->sent_by, $message->failed]);
        $this->assertSame('You: Opo, may slot pa po kami.', $conversation->fresh()->last_message_preview);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.self::PAGE_TOKEN)
            && $request['recipient']['id'] === 'psid-1'
            && $request['message']['text'] === 'Opo, may slot pa po kami.'
            && $request['messaging_type'] === 'RESPONSE');

        // Meta's echo of the same reply
        $this->postWebhook($this->messageEvent($this->cainta, 'psid-1', ['mid' => 'm_out_1', 'text' => 'Opo, may slot pa po kami.'], echo: true))->assertOk();
        $this->assertSame(1, ConversationMessage::where('direction', 'out')->count());
    }

    public function test_a_failed_send_is_kept_in_the_thread_with_the_reason_and_shown(): void
    {
        Http::fake([self::GRAPH.'/*' => Http::response(['error' => ['code' => 10, 'message' => 'This message is sent outside of allowed window.', 'fbtrace_id' => 'AbC123']], 400)]);
        $conversation = $this->chat($this->cainta, 'psid-1', 0, ['last_inbound_at' => now()->subDays(3)]);
        $this->actingAs($this->owner());

        Livewire::test(Inbox::class, ['conversation' => $conversation->id])
            ->assertSee('over 24 hours')
            ->set('reply', 'Hello')
            ->call('send')
            ->assertNotified('Message not sent')
            ->assertSet('reply', 'Hello');

        $message = ConversationMessage::where('direction', 'out')->sole();
        $this->assertTrue($message->failed);
        $this->assertStringContainsString('Meta error 10', $message->error);
        $this->assertNull($message->external_id);
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $message->error);
    }

    public function test_a_branch_without_a_connected_page_cannot_send(): void
    {
        $branch = Branch::factory()->create();
        $conversation = $this->chat($branch, 'psid-1');
        $this->actingAs($this->owner());

        Livewire::test(Inbox::class, ['conversation' => $conversation->id])
            ->set('reply', 'Hello')
            ->call('send')
            ->assertNotified('Message not sent');

        $this->assertSame(0, ConversationMessage::where('direction', 'out')->count());
        Http::assertNothingSent();
    }

    public function test_a_demo_site_saves_the_reply_here_and_sends_nothing(): void
    {
        config(['app.demo' => true]);
        $conversation = $this->chat($this->cainta, 'psid-1');
        $this->actingAs($this->owner());

        Livewire::test(Inbox::class, ['conversation' => $conversation->id])
            ->set('reply', 'Demo reply')
            ->call('send')
            ->assertNotified('Saved here only');

        $message = ConversationMessage::where('direction', 'out')->sole();
        $this->assertSame(['Demo reply', null], [$message->body, $message->external_id]);
        Http::assertNothingSent();
    }

    public function test_sample_chats_only_show_on_a_demo_or_local_site_without_meta_and_never_send(): void
    {
        MetaSetting::instance()->forceFill(['enabled' => false])->save();
        $this->actingAs($this->owner());

        $this->assertFalse(SampleInbox::active());
        SampleInbox::ensureSeeded();
        $this->assertSame(0, Conversation::count());

        config(['app.demo' => true]);
        Livewire::test(Inbox::class)->assertSee('Sample')->assertSee('sample chats');
        $this->assertSame(6, Conversation::where('is_sample', true)->count());

        // Seeded once
        SampleInbox::ensureSeeded();
        $this->assertSame(6, Conversation::count());

        $sample = Conversation::where('is_sample', true)->firstOrFail();
        config(['app.demo' => false]);
        // Not demo any more, and Meta still off: not local either (testing), so hidden
        $this->assertSame(0, Conversation::visibleTo(auth()->user())->count());

        // A sample chat never sends, even with Meta ready outside a demo
        MetaSetting::instance()->forceFill(['enabled' => true])->save();
        app(\App\Support\Inbox\Inbox::class)->reply($sample, 'Hi', auth()->user());
        Http::assertNothingSent();
        $this->assertSame(0, Conversation::visibleTo(auth()->user())->count());
    }

    // Secrets

    public function test_the_page_token_is_encrypted_at_rest_and_never_exposed(): void
    {
        $branch = $this->cainta->fresh();

        $this->assertSame(self::PAGE_TOKEN, $branch->facebook_page_token);
        $this->assertStringNotContainsString(self::PAGE_TOKEN, (string) DB::table('branches')->where('id', $branch->id)->value('facebook_page_token'));
        $this->assertArrayNotHasKey('facebook_page_token', $branch->toArray());
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $branch->toJson());
        $this->assertStringNotContainsString(self::PAGE_TOKEN, json_encode($branch->attributesToArray()));
        $this->assertStringNotContainsString(self::APP_SECRET, (string) DB::table('meta_settings')->value('app_secret'));
        $this->assertArrayNotHasKey('app_secret', MetaSetting::instance()->toArray());
    }

    public function test_the_branch_form_never_puts_the_token_in_its_state_or_page(): void
    {
        $this->actingAs($this->owner());

        $component = Livewire::test(EditBranch::class, ['record' => $this->cainta->getKey()])
            ->assertDontSee(self::PAGE_TOKEN)
            ->assertSee('A token is saved (hidden)');

        $this->assertNull($component->get('data.facebook_page_token'));
        $this->assertStringNotContainsString(self::PAGE_TOKEN, json_encode($component->get('data')));

        // Saving with the token left blank keeps the saved one; typing a new one replaces it
        $component->call('save')->assertHasNoFormErrors();
        $this->assertSame(self::PAGE_TOKEN, $this->cainta->fresh()->facebook_page_token);
        $this->assertNotNull($this->cainta->fresh()->facebook_connected_at);

        $component->fillForm(['facebook_page_token' => 'brand-new-token'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('brand-new-token', $this->cainta->fresh()->facebook_page_token);
        $this->assertNull($this->cainta->fresh()->facebook_connected_at, 'a new token has to be checked again');
    }

    public function test_the_meta_page_is_owner_only_keeps_the_secret_write_only_and_shows_the_verify_token_once(): void
    {
        $this->actingAs($this->staffOf($this->cainta));
        $this->assertFalse(MetaSettings::canAccess());

        $this->actingAs($this->owner());
        $settings = MetaSetting::instance();
        $settings->forceFill(['verify_token_shown_at' => null])->save();
        $token = $settings->webhook_verify_token;

        $first = Livewire::test(MetaSettings::class)
            ->assertSee($token)
            ->assertSee('Saved ••••')
            ->assertDontSee(self::APP_SECRET)
            ->assertSee(route('webhooks.meta'));
        $this->assertNull($first->get('data.app_secret'));

        Livewire::test(MetaSettings::class)->assertDontSee($token)->assertSee('Hidden');

        $second = Livewire::test(MetaSettings::class)
            ->fillForm(['app_id' => '123456', 'enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(self::APP_SECRET, MetaSetting::instance()->app_secret);
        $this->assertSame('123456', MetaSetting::instance()->app_id);
        $second->callAction('regenerateToken')->assertNotified('New verify token made');
        $this->assertNotSame($token, MetaSetting::instance()->webhook_verify_token);
    }

    // Connect check

    public function test_connect_check_confirms_the_token_belongs_to_the_page_id_and_subscribes(): void
    {
        $branch = Branch::factory()->create(['facebook_page_id' => '777']);
        Http::fake([
            self::GRAPH.'/me*' => Http::response(['id' => '777', 'name' => '826 Antipolo']),
            self::GRAPH.'/777/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $warning = PageConnection::check($branch, '777', 'typed-token');

        $branch->refresh();
        $this->assertNull($warning);
        $this->assertSame(['826 Antipolo', 'typed-token', true], [$branch->facebook_page_name, $branch->facebook_page_token, $branch->facebook_connected_at !== null]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/777/subscribed_apps') && $request['subscribed_fields'] === 'messages,message_echoes');
    }

    public function test_connect_check_refuses_a_token_of_another_page_a_taken_page_and_missing_input(): void
    {
        $branch = Branch::factory()->create();
        Http::fake([self::GRAPH.'/me*' => Http::response(['id' => '111', 'name' => 'Other page'])]);

        foreach ([['222', 'tok'], ['', 'tok'], ['222', null], ['100200300400500', 'tok']] as [$pageId, $token]) {
            try {
                PageConnection::check($branch, $pageId, $token);
                $this->fail('Expected an InboxException');
            } catch (InboxException) {
                $this->assertNull($branch->fresh()->facebook_connected_at);
            }
        }
    }

    public function test_changing_the_page_id_needs_a_new_connect_check_and_the_page_is_unique(): void
    {
        $this->cainta->update(['facebook_page_id' => '424242']);
        $this->assertNull($this->cainta->fresh()->facebook_connected_at);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->sanMateo->update(['facebook_page_id' => '424242']);
    }
}
