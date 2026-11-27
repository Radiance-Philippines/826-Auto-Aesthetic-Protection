<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Guest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Inbox endpoints: the inbox area, the branch's own threads
 * (another's is a 404) and ConversationPolicy, like the Filament page.
 */
class ConsoleInboxTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const INBOX = '/console/api/sales/inbox';

    private Branch $cainta;

    private Branch $sanMateo;

    private Conversation $chat;

    private Conversation $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
        Http::fake();
        $this->chat = $this->chatAt($this->cainta, 'Maria Santos');
        $this->theirs = $this->chatAt($this->sanMateo, 'Far Away');
    }

    private function chatAt(Branch $branch, string $name): Conversation
    {
        $chat = Conversation::create(['branch_id' => $branch->id, 'external_id' => "psid-{$branch->id}", 'customer_name' => $name, 'unread_count' => 1, 'last_message_at' => now(), 'last_inbound_at' => now()->subDays(2), 'last_message_preview' => 'Hello po']);
        $chat->messages()->create(['direction' => ConversationMessage::IN, 'body' => 'Hello po', 'external_id' => "m-{$branch->id}", 'sent_at' => now()]);

        return $chat;
    }

    private function sales(array $state = []): User
    {
        $user = $this->staffUser('sales_admin', $this->cainta, $state);
        $this->actingAs($user);

        return $user;
    }

    private function url(string $path = '', ?Conversation $chat = null): string
    {
        return self::INBOX.'/'.($chat ?? $this->chat)->id.$path;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['getJson', ''],
            'new thread' => ['postJson', ''],
            'thread' => ['getJson', '/{id}'],
            'team' => ['getJson', '/{id}/team'],
            'assign' => ['postJson', '/{id}/assign'],
            'customers' => ['getJson', '/{id}/customers'],
            'link' => ['postJson', '/{id}/customer'],
            'reply' => ['postJson', '/{id}/reply'],
            'done' => ['postJson', '/{id}/done'],
        ];
    }

    private function callEndpoint(string $method, string $path, Conversation $chat): TestResponse
    {
        $url = self::INBOX.str_replace('{id}', (string) $chat->id, $path);

        return $method === 'getJson' ? $this->getJson($url) : $this->postJson($url, ['branch' => $chat->branch_id, 'subject' => 'S', 'body' => 'B', 'done' => true, 'assignedTo' => null, 'guestId' => null]);
    }

    private function assertUntouched(): void
    {
        $this->assertSame(2, Conversation::count());
        $this->assertSame(2, ConversationMessage::count());
        $this->assertSame([Conversation::OPEN, Conversation::OPEN], Conversation::query()->orderBy('id')->pluck('status')->all());
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, $path, $this->chat)->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_without_the_inbox_area_is_403(string $method, string $path): void
    {
        $this->sales(['permissions' => ['bookings.view']]);

        $this->callEndpoint($method, $path, $this->chat)->assertForbidden();
        $this->assertUntouched();
    }

    public function test_another_branchs_chat_is_404_and_not_listed(): void
    {
        $this->sales();

        foreach (self::endpoints() as [$method, $path]) {
            if (str_contains($path, '{id}')) {
                $this->callEndpoint($method, $path, $this->theirs)->assertNotFound();
            }
        }

        $this->postJson(self::INBOX, ['branch' => $this->sanMateo->id, 'subject' => 'S', 'body' => 'B'])->assertUnprocessable();
        $this->getJson(self::INBOX)->assertOk()->assertJsonPath('conversations.*.name', ['Maria Santos']);
        $this->assertSame(1, $this->theirs->fresh()->unread_count);
        $this->assertUntouched();
    }

    public function test_the_list_has_tabs_filters_and_search(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->getJson(self::INBOX)
            ->assertJsonPath('branch', 'all')
            ->assertJsonPath('tabs.0', ['key' => 'all', 'label' => 'All', 'unread' => 2])
            ->assertJsonCount(3, 'tabs')
            ->assertJsonCount(2, 'conversations');
        $this->getJson(self::INBOX."?branch={$this->sanMateo->id}")->assertJsonPath('conversations.*.name', ['Far Away']);
        $this->getJson(self::INBOX.'?search=maria')->assertJsonPath('conversations.*.name', ['Maria Santos']);
        $this->getJson(self::INBOX.'?filter=done')->assertJsonCount(0, 'conversations');
        $this->getJson(self::INBOX.'?channel=internal')->assertJsonCount(0, 'conversations');
    }

    public function test_opening_a_chat_marks_it_read_and_a_reply_is_saved(): void
    {
        $this->sales();

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('name', 'Maria Santos')
            ->assertJsonPath('pastWindow', true)
            ->assertJsonPath('hasOlder', false)
            ->assertJsonPath('messages.0.body', 'Hello po')
            ->assertJsonPath('messages.0.right', false);
        $this->assertSame(0, $this->chat->fresh()->unread_count);

        config(['app.demo' => true]);
        $this->postJson($this->url('/reply'), ['body' => ''])->assertUnprocessable();
        $this->postJson($this->url('/reply'), ['body' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->postJson($this->url('/reply'), ['body' => 'Opo'])->assertCreated()->assertJsonPath('savedOnly', true);
        $this->getJson($this->url())->assertJsonPath('messages.1.note', 'Saved here only, not sent')->assertJsonPath('messages.1.right', true);
        Http::assertNothingSent();

        $this->postJson($this->url('/done'), ['done' => true])->assertOk();
        $this->assertSame(Conversation::DONE, $this->chat->fresh()->status);
        // Off again: a demo adds its sample chats to the list
        config(['app.demo' => false]);
        $this->getJson(self::INBOX)->assertJsonCount(0, 'conversations');
    }

    public function test_assign_and_link_stay_inside_the_branch(): void
    {
        $user = $this->sales();
        $elsewhere = $this->staffUser('sales_admin', $this->sanMateo);
        $detailer = $this->staffUser('operations', $this->cainta);
        $mine = Guest::factory()->walkIn()->create(['name' => 'Maria Mine', 'branch_id' => $this->cainta->id]);
        $other = Guest::factory()->walkIn()->create(['name' => 'Maria Theirs', 'branch_id' => $this->sanMateo->id]);

        $this->getJson($this->url('/team'))->assertJsonPath('data.*.value', [(string) $user->id]);
        $this->postJson($this->url('/assign'), ['assignedTo' => $elsewhere->id])->assertUnprocessable();
        $this->postJson($this->url('/assign'), ['assignedTo' => $detailer->id])->assertUnprocessable();
        $this->postJson($this->url('/assign'), ['assignedTo' => $user->id])->assertNoContent();
        $this->getJson($this->url())->assertJsonPath('assignee.name', $user->name);
        $this->postJson($this->url('/assign'), ['assignedTo' => null])->assertNoContent();
        $this->assertNull($this->chat->fresh()->assigned_to);

        $this->getJson($this->url('/customers?search=maria'))->assertJsonPath('data.*.value', [(string) $mine->id]);
        $this->postJson($this->url('/customer'), ['guestId' => $other->id])->assertNotFound();
        $this->postJson($this->url('/customer'), ['guestId' => $mine->id])->assertNoContent();
        $this->getJson($this->url())->assertJsonPath('customer.id', $mine->id);
        $this->postJson($this->url('/customer'), ['guestId' => null])->assertNoContent();
        $this->assertNull($this->chat->fresh()->guest_id);
    }

    public function test_a_team_thread_is_started_and_has_no_customer(): void
    {
        $this->sales();
        $booking = Booking::factory()->on('2026-11-17')->create(['branch_id' => $this->cainta->id]);

        $this->postJson(self::INBOX, ['branch' => $this->cainta->id, 'subject' => '', 'body' => ''])->assertUnprocessable();
        $this->postJson(self::INBOX, ['branch' => $this->cainta->id, 'subject' => 'Parts', 'body' => 'Ordered', 'booking' => '826-NOPE0'])->assertUnprocessable();
        $id = $this->postJson(self::INBOX, ['branch' => $this->cainta->id, 'subject' => 'Parts', 'body' => 'Ordered', 'booking' => $booking->reference])->assertCreated()->json('conversation.id');

        $this->getJson(self::INBOX."/{$id}")->assertJsonPath('channel', 'internal')->assertJsonPath('booking', ['reference' => $booking->reference, 'id' => $booking->id])->assertJsonPath('messages.0.right', true);
        $this->postJson(self::INBOX."/{$id}/customer", ['guestId' => null])->assertForbidden();
        $this->getJson(self::INBOX.'?channel=internal')->assertJsonPath('conversations.0.about', $booking->reference);
    }
}
