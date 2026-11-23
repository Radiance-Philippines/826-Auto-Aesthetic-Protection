<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Guest;
use App\Models\MetaSetting;
use App\Models\ReferralRedemption;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The staff app's Clients and Inbox tabs: who sees which customers and
 * chats (a branch's are private to it), and what the actions do.
 */
class StaffClientsInboxTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const GRAPH = 'https://graph.facebook.com/v26.0';

    private Branch $cainta;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->cainta = Branch::factory()->create(['name' => 'Cainta', 'facebook_page_id' => '100', 'facebook_page_token' => 'page-token', 'facebook_connected_at' => now(), 'map_url' => 'https://maps.app.goo.gl/cainta']);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo', 'facebook_page_id' => '200', 'facebook_page_token' => 'page-token', 'facebook_connected_at' => now()]);
        MetaSetting::instance()->forceFill(['app_id' => '555666777888999', 'app_secret' => 'meta-secret', 'enabled' => true])->save();
    }

    private function customer(Branch $branch, array $state = []): Guest
    {
        return Guest::factory()->walkIn()->create(['branch_id' => $branch->id, ...$state]);
    }

    private function chat(Branch $branch, string $name, int $unread = 0, array $state = []): Conversation
    {
        $conversation = Conversation::create([
            'branch_id' => $branch->id,
            'external_id' => 'psid-'.$name,
            'customer_name' => $name,
            'last_message_preview' => "{$name} says hi",
            'last_message_at' => now()->subMinutes(5),
            'last_inbound_at' => now()->subMinutes(5),
            'unread_count' => $unread,
            ...$state,
        ]);
        $conversation->messages()->create(['direction' => 'in', 'body' => "{$name} says hi", 'external_id' => 'm-'.$name, 'sent_at' => now()->subMinutes(5)]);

        return $conversation;
    }

    // Clients

    public function test_clients_are_the_branch_s_customers_and_searchable_by_name_phone_and_plate(): void
    {
        $operations = $this->staffUser('operations', $this->cainta);
        $maria = $this->customer($this->cainta, ['name' => 'Maria Santos', 'phone' => '0917 123 4567']);
        Vehicle::factory()->create(['guest_id' => $maria->id, 'plate_number' => 'XYZ9876']);
        $jun = $this->customer($this->cainta, ['name' => 'Jun Reyes']);
        // Booked here, home branch elsewhere: visible too
        $visitor = $this->customer($this->sanMateo, ['name' => 'Visitor Lopez']);
        Booking::factory()->create(['branch_id' => $this->cainta->id, 'guest_id' => $visitor->id, 'status' => 'exited', 'drop_off_on' => '2026-11-10']);
        // Never came to Cainta
        $this->customer($this->sanMateo, ['name' => 'Far Away']);

        $response = $this->example('customers', 'GET /api/staff/customers?q=&page=1', $this->getJson('/api/staff/customers', $this->bearer($operations)))->assertOk();

        $this->assertSame(['Jun Reyes', 'Maria Santos', 'Visitor Lopez'], collect($response->json('data'))->pluck('name')->all());
        $response->assertJsonPath('total', 3)->assertJsonPath('page', 1)->assertJsonPath('hasMore', false)
            ->assertJsonPath('data.2.lastVisitOn', '2026-11-10')
            ->assertJsonPath('data.2.bookingsCount', 1)
            ->assertJsonPath('data.1.unitsCount', 1)
            ->assertJsonPath('data.1.phone', '0917 123 4567');
        $this->assertStringNotContainsString('Far Away', $response->getContent());

        $names = fn (string $q) => collect($this->getJson('/api/staff/customers?q='.urlencode($q), $this->bearer($operations))->json('data'))->pluck('name')->all();
        $this->assertSame(['Maria Santos'], $names('maria'));
        $this->assertSame(['Maria Santos'], $names('917 123'));
        $this->assertSame(['Maria Santos'], $names('+63 917 123 4567'));
        $this->assertSame(['Maria Santos'], $names('xyz 9876'));
        $this->assertSame([], $names('Far Away'));
        $this->assertSame($jun->id, $this->getJson('/api/staff/customers?q=jun', $this->bearer($operations))->json('data.0.id'));
    }

    public function test_clients_are_paged(): void
    {
        $operations = $this->staffUser('operations', $this->cainta);
        Guest::factory()->walkIn()->count(23)->create(['branch_id' => $this->cainta->id]);

        $this->getJson('/api/staff/customers', $this->bearer($operations))->assertJsonCount(20, 'data')->assertJsonPath('hasMore', true)->assertJsonPath('total', 23);
        $this->getJson('/api/staff/customers?page=2', $this->bearer($operations))->assertJsonCount(3, 'data')->assertJsonPath('hasMore', false);
    }

    public function test_a_customer_profile_has_units_bookings_points_and_notes(): void
    {
        ReferralSetting::instance()->update(['enabled' => true, 'discount_type' => 'fixed', 'discount_value' => 500, 'owner_points' => 100]);
        $sales = $this->staffUser('sales_admin', $this->cainta);
        $maria = $this->customer($this->cainta, ['name' => 'Maria Santos', 'referral_code' => 'MARIA-7K3Q', 'notes' => 'Prefers Saturdays']);
        $vios = Vehicle::factory()->create(['guest_id' => $maria->id, 'year' => 2021, 'model' => 'Toyota Vios', 'type' => 'sedan', 'plate_number' => 'ABC1234']);
        $here = Booking::factory()->priced(2500)->create(['branch_id' => $this->cainta->id, 'guest_id' => $maria->id, 'vehicle_id' => $vios->id, 'status' => 'exited', 'drop_off_on' => '2026-11-10', 'name' => 'Maria Santos']);
        $here->items()->create(['service_name' => 'Exterior detailing']);
        $elsewhere = Booking::factory()->priced(9999)->create(['branch_id' => $this->sanMateo->id, 'guest_id' => $maria->id, 'vehicle_id' => $vios->id, 'status' => 'exited']);
        ReferralRedemption::create(['code_guest_id' => $maria->id, 'used_by_guest_id' => $this->customer($this->cainta)->id, 'booking_id' => Booking::factory()->create(['branch_id' => $this->cainta->id])->id, 'points_awarded' => 100, 'awarded_at' => now()]);
        $this->chat($this->cainta, 'Maria on Messenger', 0, ['guest_id' => $maria->id]);

        $response = $this->example('customer', 'GET /api/staff/customers/{id}', $this->getJson("/api/staff/customers/{$maria->id}", $this->bearer($sales)))->assertOk();

        $response->assertJsonPath('name', 'Maria Santos')
            ->assertJsonPath('referralCode', 'MARIA-7K3Q')
            ->assertJsonPath('pointsBalance', 100)
            ->assertJsonPath('notes', 'Prefers Saturdays')
            ->assertJsonPath('units.0.plate', 'ABC1234')
            ->assertJsonPath('units.0.historyCount', 1)
            ->assertJsonPath('bookings.0.id', $here->id)
            ->assertJsonPath('bookings.0.total', 2500)
            ->assertJsonPath('can.edit', true)
            ->assertJsonPath('messenger.branchId', $this->cainta->id);
        // The other branch's booking is not shown here
        $this->assertSame([$here->id], collect($response->json('bookings'))->pluck('id')->all());
        $this->assertStringNotContainsString('9999', $response->getContent());
    }

    public function test_the_detailer_sees_a_profile_without_prices_and_cannot_edit(): void
    {
        $operations = $this->staffUser('operations', $this->cainta);
        $maria = $this->customer($this->cainta);
        Booking::factory()->priced(2500)->create(['branch_id' => $this->cainta->id, 'guest_id' => $maria->id, 'status' => 'exited']);

        $response = $this->getJson("/api/staff/customers/{$maria->id}", $this->bearer($operations))->assertOk();

        $response->assertJsonPath('bookings.0.total', null)->assertJsonPath('can.edit', false)->assertJsonPath('messenger', null);
        $this->patchJson("/api/staff/customers/{$maria->id}", ['notes' => 'x'], $this->bearer($operations))->assertForbidden();
    }

    public function test_another_branch_s_customer_is_a_404_and_the_area_is_a_403(): void
    {
        $manager = $this->staffUser('branch_manager', $this->cainta);
        $far = $this->customer($this->sanMateo, ['notes' => 'secret note']);
        $headers = $this->bearer($manager);

        $this->getJson("/api/staff/customers/{$far->id}", $headers)->assertNotFound();
        $this->patchJson("/api/staff/customers/{$far->id}", ['notes' => 'hijack'], $headers)->assertNotFound();
        $this->assertSame('secret note', $far->fresh()->notes);

        $nothing = $this->staffUser('branch_manager', $this->cainta, ['permissions' => ['bookings.view']]);
        $this->getJson('/api/staff/customers', $this->bearer($nothing))->assertForbidden();
        $this->getJson('/api/staff/customers/'.$this->customer($this->cainta)->id, $this->bearer($nothing))->assertForbidden();

        // A super admin sees every customer
        $this->getJson("/api/staff/customers/{$far->id}", $this->bearer(User::factory()->owner()->create()))->assertOk();
    }

    public function test_notes_and_phone_are_edited_with_customers_write_and_checked(): void
    {
        $sales = $this->staffUser('sales_admin', $this->cainta);
        $maria = $this->customer($this->cainta, ['phone' => '0917 123 4567']);
        $headers = $this->bearer($sales);

        $response = $this->example('customer-update', 'PATCH /api/staff/customers/{id} {phone?, notes?}', $this->patchJson("/api/staff/customers/{$maria->id}", ['phone' => '0918 765 4321', 'notes' => 'Call before 5pm'], $headers))->assertOk();

        $response->assertJsonPath('phone', '0918 765 4321')->assertJsonPath('notes', 'Call before 5pm');
        $this->assertSame('9187654321', $maria->fresh()->phone_key);

        $this->patchJson("/api/staff/customers/{$maria->id}", ['phone' => 'abc'], $headers)->assertUnprocessable();
        $this->patchJson("/api/staff/customers/{$maria->id}", ['phone' => '12345'], $headers)->assertUnprocessable();
        $this->patchJson("/api/staff/customers/{$maria->id}", ['notes' => str_repeat('a', 3001)], $headers)->assertUnprocessable();
        // Only what is sent changes; a blank phone clears it
        $this->patchJson("/api/staff/customers/{$maria->id}", ['phone' => null], $headers)->assertOk()->assertJsonPath('phone', null)->assertJsonPath('notes', 'Call before 5pm');
    }

    // Inbox

    public function test_the_inbox_lists_only_the_user_s_branches_with_tabs_and_counts(): void
    {
        $mine = $this->chat($this->cainta, 'Maria', 2);
        $this->chat($this->cainta, 'Jun', 0);
        $this->chat($this->sanMateo, 'Secret Customer', 5);
        $staff = $this->staffUser('sales_admin', $this->cainta);

        $response = $this->example('inbox', 'GET /api/staff/inbox?branch=&filter=open&q=', $this->getJson('/api/staff/inbox', $this->bearer($staff)))->assertOk();

        // One branch: its own tab only, no "All"; a tab's count is the chats with something unread, as on the website
        $response->assertJsonPath('showsAll', false)
            ->assertJsonPath('tabs', [['key' => (string) $this->cainta->id, 'label' => 'Cainta', 'unread' => 1]])
            ->assertJsonPath('branch', (string) $this->cainta->id)
            ->assertJsonPath('filter', 'open')
            ->assertJsonPath('totalUnread', 1)
            ->assertJsonPath('notice', null);
        $this->assertEqualsCanonicalizing(['Maria', 'Jun'], collect($response->json('conversations'))->pluck('name')->all());
        $this->assertStringNotContainsString('Secret Customer', $response->getContent());
        $this->assertSame(
            ['id' => $mine->id, 'name' => 'Maria', 'avatar' => null, 'branch' => ['id' => $this->cainta->id, 'name' => 'Cainta'], 'preview' => 'Maria says hi', 'lastMessageAt' => $mine->fresh()->last_message_at->toIso8601String(), 'unread' => 2, 'done' => false, 'sample' => false, 'linkedCustomer' => false],
            collect($response->json('conversations'))->firstWhere('id', $mine->id),
        );

        // Asking for the other branch's tab falls back to its own
        $this->getJson("/api/staff/inbox?branch={$this->sanMateo->id}", $this->bearer($staff))->assertJsonPath('branch', (string) $this->cainta->id);
    }

    public function test_two_branches_get_an_all_tab_that_only_covers_their_own_and_a_super_admin_sees_every_branch(): void
    {
        $third = Branch::factory()->create(['name' => 'Antipolo']);
        $this->chat($this->cainta, 'A', 1);
        $this->chat($this->sanMateo, 'B', 2);
        $this->chat($third, 'C', 4);
        $two = $this->staffUser('branch_manager', $this->cainta);
        $two->branches()->attach($this->sanMateo);

        $response = $this->getJson('/api/staff/inbox?branch=all', $this->bearer($two))->assertOk();
        $response->assertJsonPath('showsAll', true)->assertJsonPath('tabs.0', ['key' => 'all', 'label' => 'All', 'unread' => 2]);
        $this->assertEqualsCanonicalizing(['A', 'B'], collect($response->json('conversations'))->pluck('name')->all());
        $this->assertCount(3, $response->json('tabs'));

        $owner = $this->getJson('/api/staff/inbox?branch=all', $this->bearer(User::factory()->owner()->create()))->assertOk();
        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], collect($owner->json('conversations'))->pluck('name')->all());
        $owner->assertJsonPath('tabs.0.unread', 3)->assertJsonCount(4, 'tabs');
    }

    public function test_the_inbox_filters_and_searches(): void
    {
        $this->chat($this->cainta, 'Open One', 1);
        $this->chat($this->cainta, 'Done One', 0, ['status' => 'done']);
        $staff = $this->staffUser('sales_admin', $this->cainta);
        $names = fn (string $query) => collect($this->getJson("/api/staff/inbox?{$query}", $this->bearer($staff))->json('conversations'))->pluck('name')->all();

        $this->assertSame(['Open One'], $names('filter=open'));
        $this->assertSame(['Open One'], $names('filter=unread'));
        $this->assertSame(['Done One'], $names('filter=done'));
        $this->assertEqualsCanonicalizing(['Open One', 'Done One'], $names('filter=all'));
        $this->assertSame(['Done One'], $names('filter=all&q=done'));
        // An unknown filter is the to-do list
        $this->assertSame(['Open One'], $names('filter=nonsense'));
    }

    public function test_roles_without_the_inbox_or_a_branch_are_refused(): void
    {
        $chat = $this->chat($this->cainta, 'Maria');
        $operations = $this->staffUser('operations', $this->cainta);
        $noBranch = User::factory()->salesAdmin()->create();

        foreach ([$operations, $noBranch] as $user) {
            $headers = $this->bearer($user);
            $this->getJson('/api/staff/inbox', $headers)->assertForbidden();
            $this->getJson("/api/staff/inbox/{$chat->id}", $headers)->assertForbidden();
            $this->postJson("/api/staff/inbox/{$chat->id}/reply", ['body' => 'hi'], $headers)->assertForbidden();
            $this->postJson("/api/staff/inbox/{$chat->id}/done", ['done' => true], $headers)->assertForbidden();
            $this->getJson('/api/staff/inbox/quick-replies', $headers)->assertForbidden();
        }

        $this->assertSame(0, $chat->messages()->where('direction', 'out')->count());
    }

    public function test_another_branch_s_chat_is_a_404_for_every_action(): void
    {
        Http::fake();
        $theirs = $this->chat($this->sanMateo, 'Secret Customer', 3);
        $headers = $this->bearer($this->staffUser('sales_admin', $this->cainta));

        $this->getJson("/api/staff/inbox/{$theirs->id}", $headers)->assertNotFound();
        $this->postJson("/api/staff/inbox/{$theirs->id}/reply", ['body' => 'hijack'], $headers)->assertNotFound();
        $this->postJson("/api/staff/inbox/{$theirs->id}/done", ['done' => true], $headers)->assertNotFound();
        $this->getJson("/api/staff/inbox/quick-replies?conversation={$theirs->id}", $headers)->assertNotFound();

        $theirs->refresh();
        $this->assertSame([3, 'open'], [$theirs->unread_count, $theirs->status]);
        $this->assertSame(0, ConversationMessage::where('direction', 'out')->count());
        Http::assertNothingSent();

        // A super admin opens it
        $this->getJson("/api/staff/inbox/{$theirs->id}", $this->bearer(User::factory()->owner()->create()))->assertOk();
    }

    public function test_opening_a_thread_returns_the_messages_oldest_first_and_marks_it_read(): void
    {
        $chat = $this->chat($this->cainta, 'Maria', 2, ['last_inbound_at' => now()->subHours(30)]);
        $chat->messages()->create(['direction' => 'out', 'body' => 'Hello po!', 'sent_by' => ($staff = $this->staffUser('sales_admin', $this->cainta))->id, 'sent_at' => now()->subMinutes(3)]);
        $chat->messages()->create(['direction' => 'in', 'body' => null, 'attachments' => [['type' => 'image', 'url' => 'https://cdn.example/p.jpg', 'name' => null]], 'external_id' => 'm-img', 'sent_at' => now()->subMinute()]);
        $guest = $this->customer($this->cainta, ['name' => 'Maria Santos']);
        $chat->update(['guest_id' => $guest->id]);

        $response = $this->example('inbox-thread', 'GET /api/staff/inbox/{id}?before=&limit=50', $this->getJson("/api/staff/inbox/{$chat->id}", $this->bearer($staff)))->assertOk();

        $this->assertSame(['Maria says hi', 'Hello po!', null], collect($response->json('messages'))->pluck('body')->all());
        $response->assertJsonPath('messages.1.direction', 'out')
            ->assertJsonPath('messages.1.senderName', $staff->name)
            ->assertJsonPath('messages.2.attachments.0.type', 'image')
            ->assertJsonPath('conversation.unread', 0)
            ->assertJsonPath('conversation.pastReplyWindow', true)
            ->assertJsonPath('conversation.windowHours', 24)
            ->assertJsonPath('conversation.savesLocallyOnly', false)
            ->assertJsonPath('conversation.customer', ['id' => $guest->id, 'name' => 'Maria Santos'])
            ->assertJsonPath('hasOlder', false);
        $this->assertSame(0, $chat->fresh()->unread_count);

        // Paging back: limit 1 gives the newest, "before" the one earlier
        $page = $this->getJson("/api/staff/inbox/{$chat->id}?limit=1", $this->bearer($staff));
        $page->assertJsonCount(1, 'messages')->assertJsonPath('hasOlder', true);
        $older = $this->getJson("/api/staff/inbox/{$chat->id}?limit=1&before={$page->json('messages.0.id')}", $this->bearer($staff));
        $this->assertSame('Hello po!', $older->json('messages.0.body'));
    }

    public function test_a_reply_goes_out_through_meta_and_is_kept(): void
    {
        Http::fake([self::GRAPH.'/100/messages*' => Http::response(['recipient_id' => 'psid-Maria', 'message_id' => 'm_out_1'])]);
        $chat = $this->chat($this->cainta, 'Maria', 1);
        $staff = $this->staffUser('sales_admin', $this->cainta);

        $response = $this->example('inbox-reply', 'POST /api/staff/inbox/{id}/reply {body}', $this->postJson("/api/staff/inbox/{$chat->id}/reply", ['body' => 'Opo, may slot pa po kami.'], $this->bearer($staff)))->assertCreated();

        $response->assertJsonPath('message.direction', 'out')->assertJsonPath('message.body', 'Opo, may slot pa po kami.')->assertJsonPath('message.senderName', $staff->name)->assertJsonPath('savedOnly', false)->assertJsonPath('conversation.preview', 'You: Opo, may slot pa po kami.')->assertJsonPath('conversation.unread', 0);
        $message = ConversationMessage::where('direction', 'out')->sole();
        $this->assertSame(['m_out_1', $staff->id, false], [$message->external_id, $message->sent_by, $message->failed]);
        Http::assertSentCount(1);
    }

    public function test_a_demo_or_sample_chat_is_saved_locally_and_nothing_is_sent(): void
    {
        Http::fake();
        $staff = $this->staffUser('sales_admin', $this->cainta);
        $sample = $this->chat($this->cainta, 'Sample Customer', 0, ['is_sample' => true]);

        // Sample chats are only visible while the demo samples are on
        $this->getJson("/api/staff/inbox/{$sample->id}", $this->bearer($staff))->assertNotFound();

        config(['app.demo' => true]);
        MetaSetting::instance()->forceFill(['enabled' => false])->save();
        $response = $this->postJson("/api/staff/inbox/{$sample->id}/reply", ['body' => 'Just a demo'], $this->bearer($staff))->assertCreated();

        $response->assertJsonPath('savedOnly', true);
        $this->assertSame(1, $sample->messages()->where('direction', 'out')->count());
        Http::assertNothingSent();

        config(['app.demo' => true]);
        $real = $this->chat($this->cainta, 'Real Customer');
        $this->postJson("/api/staff/inbox/{$real->id}/reply", ['body' => 'Demo site: still local'], $this->bearer($staff))->assertCreated()->assertJsonPath('savedOnly', true);
        Http::assertNothingSent();
    }

    public function test_a_failed_send_says_why(): void
    {
        Http::fake([self::GRAPH.'/*' => Http::response(['error' => ['code' => 10, 'message' => 'This message is sent outside of allowed window.', 'fbtrace_id' => 'AbC123']], 400)]);
        $chat = $this->chat($this->cainta, 'Maria');
        $headers = $this->bearer($this->staffUser('sales_admin', $this->cainta));

        $response = $this->postJson("/api/staff/inbox/{$chat->id}/reply", ['body' => 'Hello'], $headers)->assertUnprocessable();

        $this->assertStringContainsString('outside of allowed window', $response->json('message'));
        $this->assertTrue((bool) ConversationMessage::where('direction', 'out')->sole()->failed);
        $this->postJson("/api/staff/inbox/{$chat->id}/reply", ['body' => ''], $headers)->assertUnprocessable()->assertJsonPath('errors.body.0', 'Write a message first.');
        $this->postJson("/api/staff/inbox/{$chat->id}/reply", ['body' => str_repeat('a', 2001)], $headers)->assertUnprocessable();
    }

    public function test_mark_done_and_open_again(): void
    {
        $chat = $this->chat($this->cainta, 'Maria');
        $headers = $this->bearer($this->staffUser('sales_admin', $this->cainta));

        $this->example('inbox-done', 'POST /api/staff/inbox/{id}/done {done}', $this->postJson("/api/staff/inbox/{$chat->id}/done", ['done' => true], $headers))->assertOk()->assertJsonPath('done', true);
        $this->assertSame('done', $chat->fresh()->status);
        $this->postJson("/api/staff/inbox/{$chat->id}/done", ['done' => false], $headers)->assertOk()->assertJsonPath('done', false);
        $this->postJson("/api/staff/inbox/{$chat->id}/done", [], $headers)->assertUnprocessable();
    }

    public function test_quick_replies_only_show_what_the_branch_has_data_for(): void
    {
        $chat = $this->chat($this->cainta, 'Maria Santos');
        $headers = $this->bearer($this->staffUser('sales_admin', $this->cainta));

        $response = $this->example('inbox-quick-replies', 'GET /api/staff/inbox/quick-replies?conversation={id}', $this->getJson("/api/staff/inbox/quick-replies?conversation={$chat->id}", $headers))->assertOk();

        // The price list has no text yet: no chip. The location needs a map link: Cainta has one.
        $this->assertSame(['slot_confirmed', 'location'], collect($response->json('replies'))->pluck('key')->all());
        $response->assertJsonPath('replies.0.label', 'Slot confirmed')
            ->assertJsonPath('replies.0.text', 'Hi Maria, your slot at 826 Cainta is confirmed. See you!')
            ->assertJsonPath('replies.1.label', 'Share location')
            ->assertJsonPath('replies.1.text', 'Here is where to find 826 Cainta: https://maps.app.goo.gl/cainta');
        $this->assertStringNotContainsString('price', strtolower($response->getContent()));

        // San Mateo has no map link: no location chip
        $far = $this->staffUser('sales_admin', $this->sanMateo);
        $other = $this->chat($this->sanMateo, 'Jun');
        $this->assertSame(['slot_confirmed'], collect($this->getJson("/api/staff/inbox/quick-replies?conversation={$other->id}", $this->bearer($far))->json('replies'))->pluck('key')->all());
        // No chat picked: the user's branch; nameless greeting
        $this->assertSame('Hi, your slot at 826 San Mateo is confirmed. See you!', $this->getJson('/api/staff/inbox/quick-replies', $this->bearer($far))->json('replies.0.text'));
    }
}
