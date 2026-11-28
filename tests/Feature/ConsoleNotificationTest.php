<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's bell reads what Filament stores, and only your own.
 */
class ConsoleNotificationTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const BELL = '/console/api/sales/notifications';

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->sales = $this->staffUser('sales_admin', Branch::factory()->create());
    }

    private function notify(User $user, string $title): string
    {
        // As HeadsUp and the Inbox write them
        Notification::make()
            ->title($title)
            ->body('Jun changed the unit')
            ->icon('heroicon-o-bell-alert')
            ->warning()
            ->actions([Action::make('open')->label('Open booking')->url(url('sales/bookings/7'))->markAsRead()])
            ->sendToDatabase($user);

        return $user->notifications()->where('data->title', $title)->sole()->id;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['getJson', ''],
            'read all' => ['postJson', '/read-all'],
            'read' => ['postJson', '/{id}/read'],
            'dismiss' => ['deleteJson', '/{id}'],
            'clear' => ['deleteJson', ''],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $id = $this->notify($this->sales, 'Heads-up');

        $this->{$method}(self::BELL.str_replace('{id}', $id, $path))->assertUnauthorized();
        $this->assertSame(1, $this->sales->unreadNotifications()->count());
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_the_role_does_not_have_is_403(string $method, string $path): void
    {
        $id = $this->notify($this->sales, 'Heads-up');
        $this->actingAs($this->sales);

        $this->{$method}(str_replace('/sales/', '/owner/', self::BELL).str_replace('{id}', $id, $path))->assertForbidden();
        $this->assertSame(1, $this->sales->unreadNotifications()->count());
    }

    public function test_the_list_is_what_filament_stored_with_links_into_the_new_screens(): void
    {
        $id = $this->notify($this->sales, 'Heads-up: Maria');
        $this->notify(User::factory()->owner()->create(), 'Someone else’s');
        $stored = $this->sales->notifications()->sole()->data;

        $this->actingAs($this->sales)->getJson(self::BELL)
            ->assertOk()
            ->assertJsonPath('meta', ['unread' => 1, 'more' => false])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.title', 'Heads-up: Maria')
            ->assertJsonPath('data.0.status', 'warning')
            ->assertJsonPath('data.0.read', false)
            ->assertJsonPath('data.0.actions', [['label' => 'Open booking', 'url' => url('console/sales/bookings/7'), 'marksRead' => true, 'newTab' => false]]);

        // Reading never rewrites what Filament shows
        $this->assertSame($stored, $this->sales->notifications()->sole()->data);
    }

    public function test_read_dismiss_and_clear_touch_only_your_own(): void
    {
        $first = $this->notify($this->sales, 'One');
        $this->notify($this->sales, 'Two');
        $this->notify($this->sales, 'Three');
        $owner = User::factory()->owner()->create();
        $theirs = $this->notify($owner, 'Theirs');
        $this->actingAs($this->sales);

        $this->postJson(self::BELL."/{$theirs}/read")->assertNotFound();
        $this->deleteJson(self::BELL."/{$theirs}")->assertNotFound();

        $this->postJson(self::BELL."/{$first}/read")->assertNoContent();
        $this->getJson(self::BELL)->assertJsonPath('meta.unread', 2);
        $this->postJson(self::BELL.'/read-all')->assertNoContent();
        $this->getJson(self::BELL)->assertJsonPath('meta.unread', 0)->assertJsonCount(3, 'data');
        $this->deleteJson(self::BELL."/{$first}")->assertNoContent();
        $this->getJson(self::BELL)->assertJsonCount(2, 'data');
        $this->deleteJson(self::BELL)->assertNoContent();
        $this->getJson(self::BELL)->assertJsonCount(0, 'data');

        $this->assertSame(1, $owner->unreadNotifications()->count());
    }
}
