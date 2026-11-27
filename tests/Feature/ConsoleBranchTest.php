<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Service;
use App\Models\User;
use App\Support\Meta\MetaClient;
use App\Support\UploadRules;
use Database\Factories\BranchFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Shop → Branches endpoints: BranchPolicy, the Filament
 * form's rules, uploads and notices.
 */
class ConsoleBranchTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const BRANCHES = '/console/api/owner/branches';

    private Branch $cainta;

    private Branch $sanMateo;

    private Service $coating;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->coating = Service::factory()->create(['name' => 'Coating']);
        $this->cainta = Branch::factory()->create(['name' => 'Cainta', 'slug' => 'cainta', 'sort_order' => 1]);
        $this->sanMateo = Branch::factory()->draft()->create(['name' => 'San Mateo', 'slug' => 'san-mateo', 'sort_order' => 2]);
    }

    private function owner(): static
    {
        return $this->actingAs(User::factory()->owner()->create());
    }

    /**
     * The whole form, as the screen sends it.
     *
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        $day = ['openToday' => true, 'open' => '08:00', 'close' => '18:00'];

        return [
            'name' => 'Antipolo', 'slug' => 'antipolo', 'status' => 'open', 'city' => 'Antipolo', 'address' => '', 'phone' => '0917 000 0000',
            'messengerUrl' => '', 'facebookUrl' => '', 'mapUrl' => '', 'mapLat' => '14.58', 'mapLng' => '121.17', 'alertEmail' => '', 'sortOrder' => 3,
            'dailyCap' => 4, 'leadDays' => 1, 'monthsAhead' => 3,
            'weeklyHours' => [1 => $day, 2 => $day, 3 => $day, 4 => $day, 5 => $day, 6 => $day, 7 => ['openToday' => false, 'open' => null, 'close' => null]],
            'services' => [$this->coating->id],
            'offersHomeService' => true, 'homeServiceAreas' => ['Antipolo', 'Cainta'],
            'facebookPageId' => '', 'staff' => [],
            'gcashEnabled' => false, 'gcashName' => '', 'gcashNumber' => '',
            'mayaEnabled' => false, 'mayaName' => '', 'mayaNumber' => '',
            ...$changes,
        ];
    }

    private function send(string $url, array $data): TestResponse
    {
        return $this->post($url, $data, ['Accept' => 'application/json']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['getJson', ''],
            'blank form' => ['getJson', '/form'],
            'one' => ['getJson', '/{id}'],
            'create' => ['postJson', ''],
            'update' => ['postJson', '/{id}'],
            'delete' => ['deleteJson', '/{id}'],
            'reorder' => ['postJson', '/reorder'],
            'connect check' => ['postJson', '/{id}/connect'],
        ];
    }

    private function callEndpoint(string $method, string $url): TestResponse
    {
        $url = str_replace('{id}', (string) $this->sanMateo->id, $url);
        $body = [...$this->form(), 'ids' => [$this->sanMateo->id, $this->cainta->id], 'facebookPageId' => '1', 'facebookPageToken' => 'x'];

        return $method === 'getJson' ? $this->getJson($url) : $this->{$method}($url, $body);
    }

    private function assertUntouched(): void
    {
        $this->assertSame(['Cainta', 'San Mateo'], Branch::query()->orderBy('sort_order')->pluck('name')->all());
        $this->assertNull($this->sanMateo->fresh()->facebook_page_id);
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method, string $path): void
    {
        $this->callEndpoint($method, self::BRANCHES.$path)->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_role_without_the_owner_panel_is_403_even_for_its_own_branch(string $method, string $path): void
    {
        $this->actingAs($this->staffUser('branch_manager', $this->sanMateo, ['permissions' => ['shop']]));

        $this->callEndpoint($method, self::BRANCHES.$path)->assertForbidden();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_panel_that_does_not_show_them_is_404(string $method, string $path): void
    {
        $this->owner();

        $this->callEndpoint($method, str_replace('owner', 'operations', self::BRANCHES).$path)->assertNotFound();
        $this->assertUntouched();
    }

    public function test_the_list_shows_every_branch_in_order_whatever_the_selector_says(): void
    {
        $this->owner();
        $this->post(route('panel.branch'), ['branch' => $this->cainta->id]);

        $this->getJson(self::BRANCHES)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cainta')
            ->assertJsonPath('data.1.name', 'San Mateo')
            ->assertJsonPath('data.1.statusLabel', 'Draft')
            ->assertJsonPath('data.0.dailyCap', 2);
    }

    public function test_a_branch_is_read_without_its_page_token(): void
    {
        $this->cainta->update(['facebook_page_id' => '123', 'facebook_page_token' => 'secret-token', 'daily_cap' => 0]);
        $this->owner();

        $response = $this->getJson(self::BRANCHES."/{$this->cainta->id}")
            ->assertOk()
            ->assertJsonPath('values.weeklyHours.1', ['openToday' => true, 'open' => '08:00', 'close' => '18:00'])
            ->assertJsonPath('values.weeklyHours.7.openToday', false)
            ->assertJsonPath('facebook.hasToken', true)
            ->assertJsonPath('facebook.status', 'Not connected')
            ->assertJsonPath('siteUrl', url('/branches/cainta'));

        $this->assertStringNotContainsString('secret-token', $response->getContent());
        $this->assertContains('Set how many units the branch takes per day (daily cap).', $response->json('missing'));
        $this->getJson(self::BRANCHES."/{$this->sanMateo->id}")->assertJsonPath('siteUrl', null);
        $this->getJson(self::BRANCHES.'/999999')->assertNotFound();
    }

    public function test_a_branch_is_added_with_its_services_staff_hours_and_qr(): void
    {
        $detailer = User::factory()->operations()->create();
        $this->owner();

        $id = $this->send(self::BRANCHES, $this->form(['staff' => [$detailer->id], 'gcashEnabled' => '1', 'gcashQr' => UploadedFile::fake()->image('qr.png', 300, 300)]))
            ->assertCreated()
            ->assertJsonPath('values.slug', 'antipolo')
            ->assertJsonPath('canDelete', true)
            ->json('id');

        $branch = Branch::query()->findOrFail($id);
        $this->assertSame(['open' => '08:00', 'close' => '18:00'], $branch->weeklyHours()[1]);
        $this->assertNull($branch->weeklyHours()[7]);
        $this->assertSame([$this->coating->id], $branch->services()->pluck('services.id')->all());
        $this->assertSame([$detailer->id], $branch->staff()->pluck('users.id')->all());
        $this->assertSame(['Antipolo', 'Cainta'], $branch->homeServiceAreas());
        $this->assertSame([4, true], [$branch->daily_cap, $branch->gcash_enabled]);
        $this->assertStringStartsWith('payments/branches/', $branch->gcash_qr);
        Storage::disk('public')->assertExists($branch->gcash_qr);
    }

    public function test_what_the_form_refuses(): void
    {
        $this->owner();
        $admin = User::factory()->owner()->create();

        $this->postJson(self::BRANCHES, [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'slug', 'status', 'dailyCap', 'leadDays', 'monthsAhead', 'weeklyHours']);

        $this->postJson(self::BRANCHES, $this->form([
            'slug' => 'cainta', 'status' => 'gone', 'phone' => 'call me', 'mapUrl' => 'not a link', 'mapLat' => 90, 'alertEmail' => 'nobody',
            'dailyCap' => 51, 'leadDays' => 61, 'monthsAhead' => 13, 'services' => [999999], 'facebookPageId' => 'abc', 'staff' => [$admin->id],
            'weeklyHours' => [1 => ['openToday' => true, 'open' => '18:00', 'close' => '08:00'], 2 => ['openToday' => true, 'open' => null, 'close' => null]] + $this->form()['weeklyHours'],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'slug' => 'The Web address has already been taken.',
            'status', 'phone', 'mapUrl', 'mapLat', 'alertEmail', 'dailyCap', 'leadDays', 'monthsAhead', 'services.0', 'facebookPageId', 'staff.0',
            'weeklyHours.1.close' => 'Monday must close after it opens.',
            'weeklyHours.2.open' => 'Set when Tuesday opens.',
        ]);

        $this->assertSame(2, Branch::query()->count());
    }

    #[DataProvider('badFiles')]
    public function test_a_qr_must_be_a_small_picture(string $name, int $kb, string $mime, string $message): void
    {
        $this->owner();

        $this->send(self::BRANCHES, $this->form(['gcashQr' => UploadedFile::fake()->create($name, $kb, $mime)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gcashQr' => $message]);

        $this->assertSame([], Storage::disk('public')->allFiles('payments'));
    }

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function badFiles(): array
    {
        return [
            'svg' => ['qr.svg', 1, 'image/svg+xml', 'Use a JPG, PNG or WebP picture.'],
            'pdf' => ['qr.pdf', 1, 'application/pdf', 'Use a JPG, PNG or WebP picture.'],
            'over 5 MB' => ['qr.png', UploadRules::QR_MAX_KB + 1, 'image/png', 'Keep the picture under 5 MB.'],
        ];
    }

    public function test_an_update_keeps_hidden_and_write_only_fields_and_replaces_the_qr(): void
    {
        Storage::disk('public')->put('payments/branches/old.png', 'old');
        $this->cainta->update(['gcash_qr' => 'payments/branches/old.png', 'facebook_page_id' => '123', 'facebook_page_token' => 'kept', 'home_service_areas' => ['Taytay']]);
        $admin = User::factory()->owner()->create();
        $this->cainta->staff()->attach($admin);
        $leaving = $this->staffUser('operations', $this->cainta);
        $this->owner();
        $url = self::BRANCHES."/{$this->cainta->id}";
        $form = $this->form(['name' => 'Cainta Ortigas', 'slug' => 'cainta', 'offersHomeService' => '0', 'homeServiceAreas' => [], 'facebookPageId' => '123']);

        $this->send($url, [...$form, 'gcashQr' => UploadedFile::fake()->image('new.png')])->assertOk()->assertJsonPath('name', 'Cainta Ortigas');

        $branch = $this->cainta->fresh();
        Storage::disk('public')->assertMissing('payments/branches/old.png');
        Storage::disk('public')->assertExists($branch->gcash_qr);
        $this->assertSame('kept', $branch->facebook_page_token);
        $this->assertSame(['Taytay'], $branch->home_service_areas);
        // The staff list has no super admins, so it never detaches one
        $this->assertSame([$admin->id], $branch->staff()->pluck('users.id')->all());
        $this->assertNotNull($leaving->fresh());

        $qr = $branch->gcash_qr;
        $this->postJson($url, [...$form, 'removegcashQr' => true])->assertOk()->assertJsonPath('gcashQrUrl', null);
        Storage::disk('public')->assertMissing($qr);
    }

    public function test_saving_says_what_still_blocks_bookings_and_what_lands_on_a_closed_day(): void
    {
        $monday = today()->next('Monday')->toDateString();
        Booking::factory()->status('confirmed')->on($monday)->create(['branch_id' => $this->cainta->id]);
        $this->owner();
        $closed = ['openToday' => false, 'open' => null, 'close' => null];

        $warnings = $this->postJson(self::BRANCHES."/{$this->cainta->id}", $this->form(['slug' => 'cainta', 'dailyCap' => 0, 'weeklyHours' => [1 => $closed] + $this->form()['weeklyHours']]))
            ->assertOk()
            ->json('warnings');

        $this->assertSame(['Saved, but not taking online bookings yet', '1 upcoming booking drops off on a day that’s now closed'], array_column($warnings, 'title'));
    }

    public function test_only_a_branch_without_bookings_is_deleted(): void
    {
        Booking::factory()->create(['branch_id' => $this->cainta->id]);
        $this->owner();

        $this->getJson(self::BRANCHES."/{$this->cainta->id}")->assertJsonPath('canDelete', false);
        $this->deleteJson(self::BRANCHES."/{$this->cainta->id}")->assertForbidden();
        $this->deleteJson(self::BRANCHES."/{$this->sanMateo->id}")->assertNoContent();
        $this->assertSame(['Cainta'], Branch::query()->pluck('name')->all());
    }

    public function test_the_order_is_saved_from_the_whole_list_only(): void
    {
        $this->owner();

        $this->postJson(self::BRANCHES.'/reorder', ['ids' => [$this->sanMateo->id, $this->cainta->id]])->assertNoContent();
        $this->assertSame(['San Mateo', 'Cainta'], Branch::query()->orderBy('sort_order')->pluck('name')->all());

        $this->postJson(self::BRANCHES.'/reorder', ['ids' => [$this->cainta->id]])->assertUnprocessable();
    }

    public function test_connect_check_marks_the_branch_connected_or_says_why_not(): void
    {
        Http::fake([MetaClient::GRAPH.'/me*' => Http::response(['id' => '123', 'name' => '826 Cainta']), MetaClient::GRAPH.'/*' => Http::response(['success' => true])]);
        $this->owner();
        $url = self::BRANCHES."/{$this->cainta->id}/connect";

        $this->postJson($url, ['facebookPageId' => '', 'facebookPageToken' => ''])->assertUnprocessable()->assertJsonPath('message', 'Enter the Facebook Page ID first.');
        $this->postJson($url, ['facebookPageId' => '999', 'facebookPageToken' => 'tok'])->assertUnprocessable();
        $this->assertNull($this->cainta->fresh()->facebook_connected_at);

        $response = $this->postJson($url, ['facebookPageId' => '123', 'facebookPageToken' => 'tok'])
            ->assertOk()
            ->assertJsonPath('title', 'Connected to 826 Cainta')
            ->assertJsonPath('facebook.pageName', '826 Cainta');

        $this->assertStringNotContainsString('tok"', $response->getContent());
        $this->assertTrue($this->cainta->fresh()->hasFacebookPage());
        $this->assertSame(BranchFactory::hours(), $this->cainta->fresh()->weekly_hours);
    }
}
