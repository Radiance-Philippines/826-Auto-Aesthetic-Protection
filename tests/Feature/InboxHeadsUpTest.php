<?php

namespace Tests\Feature;

use App\Filament\Pages\Inbox;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\Guest;
use App\Models\MetaSetting;
use App\Models\User;
use App\Support\Reservations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The second Inbox channel: operations' heads-ups about a booking's ready
 * date or unit, one thread per booking, answered with internal notes.
 */
class InboxHeadsUpTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private Branch $cainta;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->cainta = Branch::factory()->create(['name' => 'Cainta', 'facebook_page_id' => '100', 'facebook_page_token' => 'page-token', 'facebook_connected_at' => now()]);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo', 'facebook_page_id' => '200', 'facebook_page_token' => 'page-token', 'facebook_connected_at' => now()]);
        MetaSetting::instance()->forceFill(['app_id' => '555666777888999', 'app_secret' => 'meta-secret', 'enabled' => true])->save();
    }

    private function booking(?Branch $branch = null): Booking
    {
        return Booking::factory()->create([
            'branch_id' => ($branch ?? $this->cainta)->id,
            'guest_id' => Guest::factory()->create()->id,
            'ready_on' => '2026-11-20',
            'drop_off_on' => '2026-11-19',
            'vehicle_model' => 'Toyota Vios',
            'plate_number' => 'ABC1234',
        ]);
    }

    private function changeReadyDate(Booking $booking, User $by, string $date = '2026-11-24'): void
    {
        $this->actingAs($by);
        Reservations::changeReadyDate($booking, CarbonImmutable::parse($date));
    }

    public function test_a_ready_date_change_by_operations_opens_a_headsup_thread_for_the_branch(): void
    {
        $booking = $this->booking();
        $operations = $this->staffUser('operations', $this->cainta);

        $this->changeReadyDate($booking, $operations);

        $thread = Conversation::sole();
        $this->assertSame('headsup', $thread->channel);
        $this->assertSame($booking->id, $thread->booking_id);
        $this->assertSame($this->cainta->id, $thread->branch_id);
        $this->assertSame($booking->guest_id, $thread->guest_id);
        $this->assertStringContainsString('ABC1234', $thread->customer_name);
        $this->assertSame(1, $thread->unread_count);
        $this->assertFalse($thread->is_sample);

        $message = $thread->messages()->sole();
        $this->assertSame('in', $message->direction);
        $this->assertSame($operations->id, $message->sent_by);
        $this->assertSame("Heads-up: {$operations->name} changed the ready date: Fri, Nov 20 → Tue, Nov 24", $message->body);
    }

    public function test_a_unit_change_adds_to_the_same_thread_and_reopens_it(): void
    {
        $booking = $this->booking();
        $manager = $this->staffUser('branch_manager', $this->cainta);
        $this->changeReadyDate($booking, $manager);
        Conversation::sole()->forceFill(['status' => 'done', 'unread_count' => 0])->save();

        Livewire::test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->fillForm(['vehicle_model' => 'Toyota Fortuner', 'vehicle_type' => 'suv'])
            ->call('save')
            ->assertHasNoFormErrors();

        $thread = Conversation::sole();
        $this->assertSame('open', $thread->status);
        $this->assertSame(1, $thread->unread_count);
        $this->assertSame(2, $thread->messages()->count());
        $this->assertStringContainsString('Fortuner', $thread->customer_name);
        $this->assertStringContainsString('the unit', $thread->last_message_preview);
    }

    public function test_one_thread_per_booking_and_a_second_booking_gets_its_own(): void
    {
        $operations = $this->staffUser('operations', $this->cainta);
        $first = $this->booking();
        $this->changeReadyDate($first, $operations, '2026-11-24');
        $this->changeReadyDate($first, $operations, '2026-11-25');
        $this->changeReadyDate($this->booking(), $operations);

        $this->assertSame(2, Conversation::count());
        $this->assertSame(2, Conversation::where('booking_id', $first->id)->sole()->messages()->count());
        $this->assertSame(2, Conversation::where('booking_id', $first->id)->sole()->unread_count);
    }

    public function test_sales_and_other_roles_changing_things_open_no_thread(): void
    {
        $this->changeReadyDate($this->booking(), $this->staffUser('sales_admin', $this->cainta));
        $this->changeReadyDate($this->booking(), User::factory()->owner()->create());

        $this->assertSame(0, Conversation::count());
    }

    public function test_the_bell_and_email_still_happen(): void
    {
        $sales = $this->staffUser('sales_admin', $this->cainta);

        $this->changeReadyDate($this->booking(), $this->staffUser('operations', $this->cainta));

        $this->assertSame(1, $sales->notifications()->count());
    }

    public function test_sales_opens_the_thread_answers_internally_and_the_change_maker_gets_a_bell(): void
    {
        $operations = $this->staffUser('operations', $this->cainta);
        $sales = $this->staffUser('sales_admin', $this->cainta);
        $this->changeReadyDate($this->booking(), $operations);
        $thread = Conversation::sole();

        $this->actingAs($sales);
        Livewire::test(Inbox::class)
            ->assertSee('Heads-up')
            ->call('open', $thread->id)
            ->assertSee('Internal note to operations')
            ->set('reply', 'Noted, I will tell the customer.')
            ->call('send')
            ->assertHasNoErrors()
            ->assertNotNotified('Saved here only')
            ->call('toggleDone');

        $reply = $thread->messages()->where('direction', 'out')->sole();
        $this->assertSame($sales->id, $reply->sent_by);
        $this->assertNull($reply->external_id);
        $this->assertFalse($reply->failed);
        $this->assertSame(0, $thread->fresh()->unread_count);
        $this->assertTrue($thread->fresh()->isDone());
        Http::assertNothingSent();

        $this->assertSame(1, $operations->notifications()->count());
        $this->assertStringContainsString('Noted, I will tell the customer.', $operations->notifications()->first()->data['body']);
        $this->assertSame(0, $sales->notifications()->where('data->title', 'like', 'Sales replied%')->count());
    }

    public function test_the_inbox_channel_filter_splits_messenger_and_headsup(): void
    {
        $this->changeReadyDate($this->booking(), $this->staffUser('operations', $this->cainta));
        Conversation::create(['branch_id' => $this->cainta->id, 'external_id' => 'psid-1', 'customer_name' => 'Maria Chat', 'last_message_at' => now(), 'unread_count' => 1]);
        $sales = $this->staffUser('sales_admin', $this->cainta);

        $this->actingAs($sales);
        $page = Livewire::test(Inbox::class)->assertSee('Maria Chat')->assertSee('ABC1234');
        $page->set('channel', 'headsup')->assertSee('ABC1234')->assertDontSee('Maria Chat');
        $page->set('channel', 'messenger')->assertSee('Maria Chat')->assertDontSee('ABC1234');
        Livewire::withQueryParams(['channel' => 'nonsense'])->test(Inbox::class)->assertSet('channel', 'all');
    }

    public function test_another_branch_never_sees_or_answers_a_headsup_thread(): void
    {
        $this->changeReadyDate($this->booking(), $this->staffUser('operations', $this->cainta));
        $thread = Conversation::sole();
        $outsider = $this->staffUser('sales_admin', $this->sanMateo);
        $noInbox = $this->staffUser('operations', $this->cainta);

        $this->assertSame(0, Conversation::visibleTo($outsider)->count());
        $this->assertSame(1, Conversation::visibleTo(User::factory()->owner()->create())->count());

        $this->actingAs($outsider);
        Livewire::test(Inbox::class)->assertDontSee('ABC1234');
        Livewire::test(Inbox::class)->call('open', $thread->id)->assertStatus(404);
        Livewire::test(Inbox::class, ['conversation' => $thread->id])->assertStatus(404);

        $this->getJson("/api/staff/inbox/{$thread->id}", $this->bearer($outsider))->assertNotFound();
        $this->postJson("/api/staff/inbox/{$thread->id}/reply", ['body' => 'hi'], $this->bearer($outsider))->assertNotFound();
        $this->postJson("/api/staff/inbox/{$thread->id}/done", ['done' => true], $this->bearer($outsider))->assertNotFound();
        $this->assertStringNotContainsString('ABC1234', $this->getJson('/api/staff/inbox', $this->bearer($outsider))->getContent());

        // Operations has no inbox area by default
        $this->actingAs($noInbox)->get('/admin/inbox')->assertForbidden();
    }

    public function test_a_headsup_reply_never_calls_meta_even_when_everything_is_connected(): void
    {
        config(['app.demo' => false]);
        $this->changeReadyDate($this->booking(), $this->staffUser('operations', $this->cainta));
        $thread = Conversation::sole();
        $sales = $this->staffUser('sales_admin', $this->cainta);

        $this->postJson("/api/staff/inbox/{$thread->id}/reply", ['body' => 'Okay po'], $this->bearer($sales))
            ->assertCreated()
            ->assertJsonPath('message.internal', true)
            ->assertJsonPath('conversation.internal', true);
        // Opening it (mark read) is not sent to Meta either
        $this->getJson("/api/staff/inbox/{$thread->id}", $this->bearer($sales))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_staff_api_shapes_for_headsup_and_messenger(): void
    {
        $booking = $this->booking();
        $this->changeReadyDate($booking, $this->staffUser('operations', $this->cainta));
        $thread = Conversation::sole();
        $messenger = Conversation::create(['branch_id' => $this->cainta->id, 'external_id' => 'psid-9', 'customer_name' => 'Maria', 'last_message_at' => now()]);
        $sales = $this->staffUser('sales_admin', $this->cainta);

        $list = $this->getJson('/api/staff/inbox', $this->bearer($sales))->assertOk()->assertJsonPath('channel', 'all');
        $rows = collect($list->json('conversations'));
        $this->assertSame(
            ['channel' => 'headsup', 'internal' => true, 'booking' => ['id' => $booking->id, 'reference' => $booking->reference]],
            collect($rows->firstWhere('id', $thread->id))->only(['channel', 'internal', 'booking'])->all(),
        );
        $this->assertArrayNotHasKey('channel', $rows->firstWhere('id', $messenger->id));

        $only = $this->getJson('/api/staff/inbox?channel=headsup', $this->bearer($sales))->assertJsonPath('channel', 'headsup');
        $this->assertSame([$thread->id], collect($only->json('conversations'))->pluck('id')->all());
        $this->assertSame([$messenger->id], collect($this->getJson('/api/staff/inbox?channel=messenger', $this->bearer($sales))->json('conversations'))->pluck('id')->all());

        $this->getJson("/api/staff/inbox/{$thread->id}", $this->bearer($sales))
            ->assertOk()
            ->assertJsonPath('conversation.channel', 'headsup')
            ->assertJsonPath('conversation.pastReplyWindow', false)
            ->assertJsonPath('messages.0.internal', true);

        $this->postJson("/api/staff/inbox/{$thread->id}/done", ['done' => true], $this->bearer($sales))->assertOk()->assertJsonPath('done', true)->assertJsonPath('internal', true);
    }
}
