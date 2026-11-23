<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Guest;
use App\Models\GuestToken;
use App\Models\ReferralRedemption;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Support\Live\FlowLive;
use App\Support\Live\LiveEvents;
use App\Support\Live\Ticket;
use App\Support\Live\Topics;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Live updates over Radiance Flow: who may read which topic, the ticket that
 * says so, and the events published after a change commits.
 */
class LiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ticket-secret-for-tests';

    private Branch $cainta;

    private Branch $makati;

    private mixed $flowAnswer = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'flow.url' => 'https://flow.test',
            'flow.app_token' => 'app-token',
            'flow.app_token_file' => null,
            'flow.ticket_secret' => self::SECRET,
            'flow.ticket_secret_file' => null,
            'flow.ws_url' => null,
        ]);

        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->makati = Branch::factory()->create(['name' => 'Makati']);
        $this->fakeFlow(Http::response(['taken' => 1]));
        Http::fake(['https://flow.test/*' => fn (Request $request) => is_callable($this->flowAnswer) ? ($this->flowAnswer)($request) : $this->flowAnswer]);
    }

    /**
     * What Flow answers from now on: a response, a sequence of them, or a closure.
     */
    private function fakeFlow(mixed $answer): void
    {
        $this->flowAnswer = $answer;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function published(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '.Events/PublishBatch'))
            ->flatMap(fn (array $pair) => $pair[0]->data()['events'])
            ->map(fn (array $event) => [...$event, 'data' => json_decode(base64_decode($event['payload']), true)])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function topicsOf(string $type): array
    {
        return collect($this->published())->where('type', $type)->pluck('topic')->unique()->sort()->values()->all();
    }

    /**
     * @return array{t: list<string>, e: int, sub: string}
     */
    private function openTicket(string $ticket): array
    {
        [$body, $signature] = explode('.', $ticket);

        $this->assertSame(Ticket::encode(hash_hmac('sha256', $body, self::SECRET, true)), $signature);

        return json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    }

    public function test_a_ticket_is_signed_the_way_flow_documents_it(): void
    {
        // Computed apart from this code: b64url(JSON) "." b64url(HMAC-SHA256("secret", body)), no padding
        $this->assertSame(
            'eyJ0IjpbIjgyNi5xdWV1ZS5icmFuY2guMSJdLCJlIjoxNzYzOTAwMDAwLCJzdWIiOiJ1c2VyOjEifQ.X1tJmXYx079-V0GMPwnYd8LC8WrcFIcpxvHZb0X5kkY',
            Ticket::sign(['826.queue.branch.1'], 'user:1', 1763900000, 'secret'),
        );
    }

    public function test_the_team_gets_the_topics_of_their_own_branches_only(): void
    {
        $detailer = User::factory()->operations()->create();
        $detailer->branches()->sync([$this->cainta->id]);

        $response = $this->actingAs($detailer)->getJson('/live/ticket')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $claims = $this->openTicket($response->json('ticket'));

        $this->assertEqualsCanonicalizing([
            "826.notify.user.{$detailer->id}",
            "826.queue.branch.{$this->cainta->id}",
            "826.bookings.branch.{$this->cainta->id}",
        ], $claims['t']);
        $this->assertSame($claims['t'], $response->json('topics'));
        $this->assertSame("user:{$detailer->id}", $claims['sub']);
        $this->assertNotContains("826.queue.branch.{$this->makati->id}", $claims['t']);
        $this->assertNotContains("826.inbox.branch.{$this->cainta->id}", $claims['t']);
    }

    public function test_the_inbox_topic_goes_to_people_who_may_open_the_inbox(): void
    {
        $sales = User::factory()->salesAdmin()->create();
        $sales->branches()->sync([$this->makati->id]);

        $topics = $this->openTicket($this->actingAs($sales)->getJson('/live/ticket')->json('ticket'))['t'];

        $this->assertContains("826.inbox.branch.{$this->makati->id}", $topics);
        $this->assertNotContains("826.inbox.branch.{$this->cainta->id}", $topics);
    }

    public function test_a_super_admin_gets_every_branch(): void
    {
        $owner = User::factory()->owner()->create();

        $topics = $this->openTicket($this->actingAs($owner)->getJson('/live/ticket')->json('ticket'))['t'];

        foreach ([$this->cainta, $this->makati] as $branch) {
            $this->assertContains("826.queue.branch.{$branch->id}", $topics);
            $this->assertContains("826.bookings.branch.{$branch->id}", $topics);
            $this->assertContains("826.inbox.branch.{$branch->id}", $topics);
        }
    }

    public function test_a_customer_gets_only_their_own_topic(): void
    {
        $customer = Guest::factory()->create();
        Guest::factory()->create();

        $response = $this->actingAs($customer, 'guest')->getJson('/live/ticket')->assertOk();
        $claims = $this->openTicket($response->json('ticket'));

        $this->assertSame(["826.customer.{$customer->id}"], $claims['t']);
        $this->assertSame("guest:{$customer->id}", $claims['sub']);
    }

    public function test_the_app_s_bearer_token_gets_a_customer_ticket_too(): void
    {
        $customer = Guest::factory()->create();
        $plain = GuestToken::issue($customer, 'phone');

        $claims = $this->openTicket($this->withToken($plain)->getJson('/live/ticket')->assertOk()->json('ticket'));

        $this->assertSame(["826.customer.{$customer->id}"], $claims['t']);
    }

    public function test_the_ticket_is_short_lived_and_names_the_socket(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['flow.ws_url' => 'wss://live.example/ws']);

        $response = $this->actingAs(Guest::factory()->create(), 'guest')->getJson('/live/ticket')->assertOk();

        $this->assertSame(now()->timestamp + 300, $this->openTicket($response->json('ticket'))['e']);
        $this->assertSame(300, $response->json('expiresIn'));
        $this->assertSame('wss://live.example/ws', $response->json('url'));
    }

    public function test_the_socket_address_comes_from_the_flow_address(): void
    {
        $this->assertSame('wss://live.radianceph.com:8443/ws', (function () {
            config(['flow.url' => 'https://live.radianceph.com:8443']);

            return FlowLive::socketUrl();
        })());
    }

    public function test_nobody_signed_in_gets_no_ticket(): void
    {
        $this->getJson('/live/ticket')->assertUnauthorized();
    }

    public function test_without_the_ticket_secret_the_answer_is_503(): void
    {
        config(['flow.ticket_secret' => null]);

        $this->actingAs(Guest::factory()->create(), 'guest')->getJson('/live/ticket')->assertStatus(503);
    }

    public function test_the_ticket_is_rate_limited(): void
    {
        $customer = Guest::factory()->create();

        foreach (range(1, 20) as $try) {
            $this->actingAs($customer, 'guest')->getJson('/live/ticket')->assertOk();
        }

        $this->actingAs($customer, 'guest')->getJson('/live/ticket')->assertTooManyRequests();
    }

    public function test_secrets_are_read_from_their_files(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'flow');
        file_put_contents($file, "from-a-file\n");
        config(['flow.app_token' => null, 'flow.app_token_file' => $file]);

        $this->assertSame('from-a-file', FlowLive::secret('app_token'));

        config(['flow.app_token_file' => '/no/such/file']);

        $this->assertFalse(FlowLive::enabled());
        unlink($file);
    }

    public function test_a_new_booking_is_published_to_its_branch_and_its_customer(): void
    {
        $customer = Guest::factory()->create();
        $booking = Booking::factory()->create(['branch_id' => $this->cainta->id, 'guest_id' => $customer->id]);

        $this->assertSame([
            "826.bookings.branch.{$this->cainta->id}",
            "826.customer.{$customer->id}",
            "826.queue.branch.{$this->cainta->id}",
        ], $this->topicsOf('booking.created'));

        $event = collect($this->published())->firstWhere('type', 'booking.created');
        $this->assertSame(['booking' => $booking->id, 'branch' => $this->cainta->id, 'status' => 'confirmed'], $event['data']);
        $this->assertNotEmpty($event['dedupe']);
    }

    public function test_the_payload_carries_no_personal_details(): void
    {
        Booking::factory()->create(['branch_id' => $this->cainta->id, 'name' => 'Maria Santos', 'phone' => '09171234567']);

        $this->assertNotEmpty($this->published());

        foreach ($this->published() as $event) {
            $this->assertEqualsCanonicalizing(['booking', 'branch', 'status'], array_keys($event['data']));
        }
    }

    public function test_a_queue_move_is_published(): void
    {
        $booking = Booking::factory()->create(['branch_id' => $this->cainta->id]);

        $booking->update(['status' => 'arrived']);

        $this->assertSame([
            "826.bookings.branch.{$this->cainta->id}",
            "826.queue.branch.{$this->cainta->id}",
        ], $this->topicsOf('booking.changed'));
        $this->assertSame('arrived', collect($this->published())->firstWhere('type', 'booking.changed')['data']['status']);
    }

    public function test_a_change_nobody_needs_to_see_is_not_published(): void
    {
        $booking = Booking::factory()->create(['branch_id' => $this->cainta->id]);
        $before = Http::recorded()->count();

        $booking->update(['read_at' => now()]);

        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_nothing_is_published_until_the_transaction_commits(): void
    {
        DB::transaction(function () {
            Booking::factory()->create(['branch_id' => $this->cainta->id]);

            Http::assertNothingSent();
        });

        $this->assertNotEmpty($this->published());
    }

    public function test_a_rolled_back_change_is_never_published(): void
    {
        try {
            DB::transaction(function () {
                Booking::factory()->create(['branch_id' => $this->cainta->id]);

                throw new \RuntimeException('undo');
            });
        } catch (\RuntimeException) {
        }

        Http::assertNothingSent();
    }

    public function test_every_event_has_its_own_dedupe_id_and_a_retry_repeats_it(): void
    {
        $this->fakeFlow(Http::sequence()->push('', 500)->push(['taken' => 1]));

        Booking::factory()->create(['branch_id' => $this->cainta->id]);

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->data()['events'][0]['dedupe']);

        $this->assertCount(2, $sent);
        $this->assertSame($sent[0], $sent[1]);
    }

    public function test_the_token_goes_as_a_bearer_and_nothing_else_secret_is_sent(): void
    {
        Booking::factory()->create(['branch_id' => $this->cainta->id]);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer app-token')
            && $request->url() === 'https://flow.test/radiance.flow.v1.Events/PublishBatch'
            && ! str_contains($request->body(), self::SECRET));
    }

    public function test_a_flow_that_is_down_never_breaks_the_request_and_is_left_alone_for_a_while(): void
    {
        $this->fakeFlow(fn () => throw new ConnectionException('refused'));
        Log::spy();

        $first = Booking::factory()->create(['branch_id' => $this->cainta->id]);
        $this->assertNotNull($first->fresh());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Live update not sent'))->atLeast()->once();

        $sentBefore = Http::recorded()->count();
        Booking::factory()->create(['branch_id' => $this->cainta->id]);

        $this->assertSame($sentBefore, Http::recorded()->count());
    }

    public function test_a_refusal_from_flow_is_only_logged(): void
    {
        $this->fakeFlow(Http::response(['code' => 'permission_denied'], 403));
        Log::spy();

        Booking::factory()->create(['branch_id' => $this->cainta->id]);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Flow answered 403'))->once();
    }

    public function test_without_the_app_token_nothing_is_sent(): void
    {
        config(['flow.app_token' => null]);

        Booking::factory()->create(['branch_id' => $this->cainta->id]);

        Http::assertNothingSent();
    }

    public function test_a_message_and_its_conversation_go_to_the_branch_inbox(): void
    {
        $conversation = Conversation::query()->create(['branch_id' => $this->cainta->id, 'external_id' => 'psid-1']);

        ConversationMessage::query()->create(['conversation_id' => $conversation->id, 'direction' => 'in', 'body' => 'Hello, private words', 'sent_at' => now()]);

        $this->assertSame(["826.inbox.branch.{$this->cainta->id}"], $this->topicsOf('message.created'));
        $message = collect($this->published())->firstWhere('type', 'message.created');
        $this->assertSame(['conversation', 'message', 'branch', 'direction'], array_keys($message['data']));

        $conversation->update(['unread_count' => 3]);
        $this->assertSame(["826.inbox.branch.{$this->cainta->id}"], $this->topicsOf('conversation.changed'));
    }

    public function test_points_changes_go_to_that_customer_only(): void
    {
        $owner = Guest::factory()->create();
        $friend = Guest::factory()->create();
        $other = Guest::factory()->create();
        $staff = User::factory()->owner()->create();

        $booking = Booking::factory()->create(['branch_id' => $this->cainta->id, 'guest_id' => $friend->id]);
        ReferralRedemption::query()->create(['code_guest_id' => $owner->id, 'used_by_guest_id' => $friend->id, 'booking_id' => $booking->id, 'points_awarded' => 50, 'awarded_at' => now()]);
        RewardRedemption::query()->create(['guest_id' => $other->id, 'points' => 10, 'note' => 'sticker', 'created_by' => $staff->id]);

        $this->assertSame(["826.customer.{$owner->id}", "826.customer.{$other->id}"], collect($this->published())->where('type', 'rewards.changed')->pluck('topic')->all());
        $this->assertSame(['guest' => $owner->id], collect($this->published())->firstWhere('type', 'rewards.changed')['data']);
    }

    public function test_a_bell_notification_goes_to_that_user_s_own_topic(): void
    {
        $user = User::factory()->owner()->create();
        $other = User::factory()->owner()->create();

        Notification::make()->title('Heads-up')->sendToDatabase($user);

        $this->assertSame(["826.notify.user.{$user->id}"], $this->topicsOf('notification.created'));
        $this->assertNotContains("826.notify.user.{$other->id}", $this->topicsOf('notification.created'));
    }

    public function test_the_topic_names_are_the_documented_ones(): void
    {
        $this->assertSame('826.queue.branch.3', Topics::queue(3));
        $this->assertSame('826.bookings.branch.3', Topics::bookings(3));
        $this->assertSame('826.inbox.branch.3', Topics::inbox(3));
        $this->assertSame('826.notify.user.9', Topics::notify(9));
        $this->assertSame('826.customer.9', Topics::customer(9));
        $this->assertTrue(method_exists(LiveEvents::class, 'listen'));
    }
}
