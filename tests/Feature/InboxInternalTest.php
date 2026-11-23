<?php

namespace Tests\Feature;

use App\Filament\Pages\Inbox;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\MetaSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\StaffApi;
use Tests\Support\UsesPanels;
use Tests\TestCase;

/**
 * The third Inbox channel: a branch team's own threads, not tied to a
 * customer, never sent to Meta or a customer.
 */
class InboxInternalTest extends TestCase
{
    use RefreshDatabase, StaffApi, UsesPanels;

    private Branch $cainta;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['app.demo' => false]);
        $this->cainta = Branch::factory()->create(['name' => 'Cainta', 'facebook_page_id' => '100', 'facebook_page_token' => 'page-token', 'facebook_connected_at' => now()]);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo', 'facebook_page_id' => '200', 'facebook_page_token' => 'page-token', 'facebook_connected_at' => now()]);
        MetaSetting::instance()->forceFill(['app_id' => '555666777888999', 'app_secret' => 'meta-secret', 'enabled' => true])->save();
    }

    private function start(User $by, ?Branch $branch = null, string $subject = 'Compressor is down', string $body = 'Who has the spare key?', ?string $booking = null): Conversation
    {
        $this->actingAs($by);
        Livewire::test(Inbox::class)
            ->callAction('startThread', ['branch_id' => ($branch ?? $this->cainta)->id, 'subject' => $subject, 'body' => $body, 'booking_reference' => $booking])
            ->assertHasNoFormErrors();

        return Conversation::query()->latest('id')->firstOrFail();
    }

    public function test_staff_start_a_thread_and_teammates_reply(): void
    {
        $sales = $this->staffUser('sales_admin', $this->cainta);
        $manager = $this->staffUser('branch_manager', $this->cainta);

        $thread = $this->start($sales);

        $this->assertSame('internal', $thread->channel);
        $this->assertSame('Compressor is down', $thread->customer_name);
        $this->assertSame($this->cainta->id, $thread->branch_id);
        $this->assertNull($thread->guest_id);
        $this->assertNull($thread->booking_id);
        $this->assertFalse($thread->is_sample);
        $this->assertStringStartsWith('internal-', $thread->external_id);
        $this->assertSame("{$sales->name}: Who has the spare key?", $thread->last_message_preview);
        $this->assertSame($sales->id, $thread->messages()->sole()->sent_by);

        $this->actingAs($manager);
        Livewire::test(Inbox::class)
            ->set('channel', 'internal')
            ->assertSee('Compressor is down')
            ->call('open', $thread->id)
            ->assertSee('Team thread')
            ->set('reply', 'I do, in the office.')
            ->call('send')
            ->assertHasNoErrors()
            ->assertNotNotified('Saved here only');

        $thread->refresh();
        $this->assertSame(2, $thread->messages()->count());
        $this->assertSame($manager->id, $thread->last_sent_by);
        $this->assertSame("{$manager->name}: I do, in the office.", $thread->last_message_preview);
        Http::assertNothingSent();
    }

    public function test_another_branch_cannot_see_open_reply_or_hear_about_it(): void
    {
        $thread = $this->start($this->staffUser('sales_admin', $this->cainta));
        $outsider = $this->staffUser('sales_admin', $this->sanMateo);

        $this->assertSame(0, Conversation::visibleTo($outsider)->count());
        $this->assertSame(0, $outsider->notifications()->count());

        $this->actingAs($outsider);
        Livewire::test(Inbox::class)->assertDontSee('Compressor is down');
        Livewire::test(Inbox::class)->set('channel', 'internal')->assertDontSee('Compressor is down');
        Livewire::test(Inbox::class)->call('open', $thread->id)->assertStatus(404);
        Livewire::test(Inbox::class, ['conversation' => $thread->id])->assertStatus(404);

        $this->getJson("/api/staff/inbox/{$thread->id}", $this->bearer($outsider))->assertNotFound();
        $this->postJson("/api/staff/inbox/{$thread->id}/reply", ['body' => 'hi'], $this->bearer($outsider))->assertNotFound();
        $this->postJson("/api/staff/inbox/{$thread->id}/done", ['done' => true], $this->bearer($outsider))->assertNotFound();
        $this->assertStringNotContainsString('Compressor', $this->getJson('/api/staff/inbox', $this->bearer($outsider))->getContent());
        $this->assertSame(0, $this->getJson('/api/staff/inbox', $this->bearer($outsider))->json('totalUnread'));
    }

    public function test_nobody_can_start_a_thread_in_a_branch_that_is_not_theirs(): void
    {
        $sales = $this->staffUser('sales_admin', $this->cainta);

        $this->actingAs($sales);
        Livewire::test(Inbox::class)
            ->callAction('startThread', ['branch_id' => $this->sanMateo->id, 'subject' => 'Hello', 'body' => 'Hi there']);

        $this->assertSame(0, Conversation::count());

        $this->postJson('/api/staff/inbox', ['branch' => $this->sanMateo->id, 'subject' => 'Hello', 'body' => 'Hi there'], $this->bearer($sales))->assertUnprocessable();
        $this->assertSame(0, Conversation::count());
    }

    public function test_the_super_admin_sees_every_branch_and_can_post_in_any(): void
    {
        $thread = $this->start($this->staffUser('sales_admin', $this->cainta));
        $other = $this->start($this->staffUser('sales_admin', $this->sanMateo), $this->sanMateo, 'Cleaning rota');
        $owner = User::factory()->owner()->create();

        $this->assertSame(2, Conversation::visibleTo($owner)->count());

        $this->actingAs($owner);
        Livewire::test(Inbox::class)
            ->set('channel', 'internal')
            ->assertSee('Compressor is down')
            ->assertSee('Cleaning rota')
            ->call('open', $thread->id)
            ->set('reply', 'Owner here, ordering the part.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(2, $thread->messages()->count());
        $owner->refresh();
        Livewire::test(Inbox::class)
            ->callAction('startThread', ['branch_id' => $this->sanMateo->id, 'subject' => 'From the owner', 'body' => 'A note for San Mateo']);
        $this->assertSame(1, Conversation::where('customer_name', 'From the owner')->where('branch_id', $this->sanMateo->id)->count());
        $this->assertSame($other->branch_id, $this->sanMateo->id);
    }

    public function test_someone_without_inbox_access_cannot_use_it(): void
    {
        $thread = $this->start($this->staffUser('sales_admin', $this->cainta));
        $operations = $this->staffUser('operations', $this->cainta);

        $this->actingAs($operations)->get($this->panel('/inbox'))->assertForbidden();
        $this->getJson('/api/staff/inbox', $this->bearer($operations))->assertForbidden();
        $this->getJson("/api/staff/inbox/{$thread->id}", $this->bearer($operations))->assertForbidden();
        $this->postJson('/api/staff/inbox', ['branch' => $this->cainta->id, 'subject' => 'Hi', 'body' => 'There'], $this->bearer($operations))->assertForbidden();
        $this->assertSame(0, $operations->notifications()->count());
    }

    public function test_nothing_is_ever_sent_to_meta_or_a_customer(): void
    {
        $sales = $this->staffUser('sales_admin', $this->cainta);
        $thread = $this->start($sales);
        $manager = $this->staffUser('branch_manager', $this->cainta);

        $this->postJson("/api/staff/inbox/{$thread->id}/reply", ['body' => 'On it'], $this->bearer($manager))->assertCreated();
        $this->getJson("/api/staff/inbox/{$thread->id}", $this->bearer($sales))->assertOk();
        $this->actingAs($manager);
        Livewire::test(Inbox::class)->call('open', $thread->id)->set('reply', 'And more')->call('send');

        Http::assertNothingSent();
        $this->assertFalse($thread->messages()->where('failed', true)->exists());
        $this->assertSame(0, $thread->messages()->whereNotNull('external_id')->count());
    }

    public function test_a_thread_can_mention_a_booking_of_that_branch_only(): void
    {
        $sales = $this->staffUser('sales_admin', $this->cainta);
        $booking = Booking::factory()->create(['branch_id' => $this->cainta->id]);
        $elsewhere = Booking::factory()->create(['branch_id' => $this->sanMateo->id]);

        $thread = $this->start($sales, booking: $booking->reference);
        $this->assertSame($booking->id, $thread->linked_booking_id);
        $this->assertNull($thread->booking_id);

        // Many threads may mention the same booking
        $this->start($sales, subject: 'Same booking again', booking: $booking->reference);
        $this->assertSame(2, Conversation::where('linked_booking_id', $booking->id)->count());

        $this->postJson('/api/staff/inbox', ['branch' => $this->cainta->id, 'subject' => 'x', 'body' => 'y', 'booking' => $elsewhere->reference], $this->bearer($sales))
            ->assertUnprocessable()
            ->assertJsonPath('message', "There is no booking {$elsewhere->reference} at this branch.");
        $this->assertSame(2, Conversation::count());
    }

    public function test_teammates_get_a_bell_but_not_the_author_or_other_branches(): void
    {
        $author = $this->staffUser('sales_admin', $this->cainta);
        $mate = $this->staffUser('branch_manager', $this->cainta);
        $outsider = $this->staffUser('sales_admin', $this->sanMateo);
        $noInbox = $this->staffUser('operations', $this->cainta);
        $thread = $this->start($author);

        $this->assertSame(0, $author->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());
        $this->assertSame(0, $noInbox->notifications()->count());
        $this->assertSame(1, $mate->notifications()->count());
        $bell = $mate->notifications()->sole()->data;
        $this->assertSame('New team thread: Compressor is down', $bell['title']);
        $this->assertStringContainsString("{$author->name}: Who has the spare key?", $bell['body']);

        $this->postJson("/api/staff/inbox/{$thread->id}/reply", ['body' => 'Got it'], $this->bearer($mate))->assertCreated();

        $this->assertSame(1, $author->notifications()->count());
        $this->assertSame('Team reply: Compressor is down', $author->notifications()->sole()->data['title']);
        $this->assertSame(1, $mate->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());

        // The same author again within minutes does not ring twice
        $this->postJson("/api/staff/inbox/{$thread->id}/reply", ['body' => 'One more thing'], $this->bearer($mate))->assertCreated();
        $this->assertSame(1, $author->notifications()->count());
    }

    public function test_live_events_carry_ids_only(): void
    {
        config(['flow.url' => 'https://flow.test', 'flow.app_token' => 'app-token', 'flow.app_token_file' => null]);
        Http::fake(['https://flow.test/*' => Http::response(['taken' => 1])]);

        $this->start($this->staffUser('sales_admin', $this->cainta), body: 'Secret words about the safe code');

        $events = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '.Events/PublishBatch'))
            ->flatMap(fn (array $pair) => $pair[0]->data()['events']);

        $this->assertTrue($events->contains(fn (array $event) => $event['topic'] === "826.inbox.branch.{$this->cainta->id}" && $event['type'] === 'message.created'));
        $this->assertFalse($events->contains(fn (array $event) => $event['topic'] === "826.inbox.branch.{$this->sanMateo->id}"));

        foreach ($events as $event) {
            $body = base64_decode($event['payload']);
            $this->assertStringNotContainsString('Secret words', $body);
            $this->assertStringNotContainsString('Compressor', $body);
        }

        $message = $events->firstWhere('type', 'message.created');
        $this->assertSame(['conversation', 'message', 'branch', 'direction'], array_keys(json_decode(base64_decode($message['payload']), true)));
    }

    public function test_the_channel_filter_and_unread_counts(): void
    {
        $author = $this->staffUser('sales_admin', $this->cainta);
        $mate = $this->staffUser('branch_manager', $this->cainta);
        $thread = $this->start($author);
        Conversation::create(['branch_id' => $this->cainta->id, 'external_id' => 'psid-1', 'customer_name' => 'Maria Chat', 'last_message_at' => now(), 'unread_count' => 1]);

        $this->actingAs($mate);
        $page = Livewire::test(Inbox::class)->assertSee('Maria Chat')->assertSee('Compressor is down');
        $page->set('channel', 'internal')->assertSee('Compressor is down')->assertDontSee('Maria Chat');
        $page->set('channel', 'messenger')->assertSee('Maria Chat')->assertDontSee('Compressor is down');
        $page->set('channel', 'headsup')->assertDontSee('Compressor is down')->assertDontSee('Maria Chat');
        $this->assertSame('2', Inbox::getNavigationBadge());
        $this->assertSame(['all', 'messenger', 'headsup', 'internal'], array_keys($page->instance()->channels()));

        // The author's own post is not unread for the author
        $this->actingAs($author);
        $this->assertSame('1', Inbox::getNavigationBadge());
        $this->assertSame([$thread->id => 1], $this->unreadOf([$thread->id], $mate));
        $this->assertSame(0, $this->getJson('/api/staff/inbox?channel=internal', $this->bearer($author))->json('conversations.0.unread'));
        $this->assertSame(1, $this->getJson('/api/staff/inbox?channel=internal', $this->bearer($mate))->json('conversations.0.unread'));
        $this->assertSame([], $this->getJson('/api/staff/inbox?channel=internal&filter=unread', $this->bearer($author))->json('conversations'));
        Livewire::test(Inbox::class)->call('open', $thread->id);
        $this->assertSame(1, $thread->fresh()->unread_count);

        // A teammate opening it clears it
        $this->actingAs($mate);
        Livewire::test(Inbox::class)->call('open', $thread->id);
        $this->assertSame(0, $thread->fresh()->unread_count);
        $this->assertSame('1', Inbox::getNavigationBadge());
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function unreadOf(array $ids, User $user): array
    {
        return Conversation::whereKey($ids)->get()->mapWithKeys(fn (Conversation $c) => [$c->id => $c->unreadFor($user)])->all();
    }

    public function test_mark_done_and_reopen_and_a_reply_reopens(): void
    {
        $author = $this->staffUser('sales_admin', $this->cainta);
        $mate = $this->staffUser('branch_manager', $this->cainta);
        $thread = $this->start($author);

        $this->actingAs($mate);
        $page = Livewire::test(Inbox::class)->call('open', $thread->id)->call('toggleDone')->call('close');
        $this->assertTrue($thread->fresh()->isDone());
        $page->set('channel', 'internal')->assertDontSee('Compressor is down');
        $page->set('filter', 'done')->assertSee('Compressor is down')->call('open', $thread->id)->call('toggleDone');
        $this->assertFalse($thread->fresh()->isDone());

        $this->postJson("/api/staff/inbox/{$thread->id}/done", ['done' => true], $this->bearer($author))->assertOk()->assertJsonPath('done', true)->assertJsonPath('channel', 'internal');
        $this->postJson("/api/staff/inbox/{$thread->id}/reply", ['body' => 'Not finished'], $this->bearer($mate))->assertCreated();
        $this->assertFalse($thread->fresh()->isDone());
    }

    public function test_heads_up_and_messenger_threads_are_untouched(): void
    {
        $sales = $this->staffUser('sales_admin', $this->cainta);
        $messenger = Conversation::create(['branch_id' => $this->cainta->id, 'external_id' => 'psid-9', 'customer_name' => 'Maria', 'last_message_at' => now(), 'unread_count' => 2]);

        $list = $this->getJson('/api/staff/inbox', $this->bearer($sales))->assertOk();
        $row = collect($list->json('conversations'))->firstWhere('id', $messenger->id);
        $this->assertArrayNotHasKey('channel', $row);
        $this->assertSame(2, $row['unread']);
        $this->assertSame(1, $list->json('totalUnread'));

        $headsUp = Conversation::create(['branch_id' => $this->cainta->id, 'channel' => 'headsup', 'external_id' => 'booking-1', 'customer_name' => 'ABC', 'last_message_at' => now()]);
        $this->assertSame('headsup', collect($this->getJson('/api/staff/inbox?channel=headsup', $this->bearer($sales))->json('conversations'))->firstWhere('id', $headsUp->id)['channel']);
    }

    public function test_the_start_endpoint_and_the_team_thread_shape(): void
    {
        $sales = $this->staffUser('sales_admin', $this->cainta);
        $mate = $this->staffUser('branch_manager', $this->cainta, ['name' => 'Mia Manager']);

        $started = $this->postJson('/api/staff/inbox', ['branch' => $this->cainta->id, 'subject' => '  Late   delivery ', 'body' => 'Wax arrives Friday'], $this->bearer($sales))
            ->assertCreated()
            ->assertJsonPath('conversation.name', 'Late delivery')
            ->assertJsonPath('conversation.channel', 'internal')
            ->assertJsonPath('conversation.internal', true)
            ->assertJsonPath('conversation.booking', null)
            ->assertJsonPath('conversation.unread', 0)
            ->assertJsonPath('message.direction', 'out')
            ->assertJsonPath('message.internal', true);
        $id = $started->json('conversation.id');

        $this->postJson('/api/staff/inbox', ['branch' => $this->cainta->id, 'subject' => '', 'body' => ''], $this->bearer($sales))->assertUnprocessable()->assertJsonValidationErrors(['subject', 'body']);

        $this->postJson("/api/staff/inbox/{$id}/reply", ['body' => 'Thanks'], $this->bearer($mate))
            ->assertCreated()
            ->assertJsonPath('message.direction', 'out')
            ->assertJsonPath('message.senderName', 'Mia Manager');

        $thread = $this->getJson("/api/staff/inbox/{$id}", $this->bearer($sales))
            ->assertOk()
            ->assertJsonPath('conversation.channel', 'internal')
            ->assertJsonPath('conversation.pastReplyWindow', false)
            ->assertJsonPath('messages.0.direction', 'out')
            ->assertJsonPath('messages.1.direction', 'in')
            ->assertJsonPath('messages.1.internal', true);
        $this->assertSame(0, $thread->json('conversation.unread'));

        // No canned customer replies in a team thread
        $this->getJson("/api/staff/inbox/quick-replies?conversation={$id}", $this->bearer($sales))->assertOk()->assertJsonPath('replies', []);

        // The legacy 0.1.1 app (no X-Team-App) works the same way
        $this->getJson('/api/staff/inbox', ['Authorization' => $this->bearer($sales)['Authorization']])->assertOk();
    }
}
