<?php

namespace Tests\Feature;

use App\Filament\Resources\AppReleases\Pages\CreateAppRelease;
use App\Filament\Resources\AppReleases\Pages\ListAppReleases;
use App\Models\AppRelease;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Android app's releases: the admin resource (super admin only), the
 * /download page and the APK it serves.
 */
class AppReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake(AppRelease::DISK);
    }

    private function release(array $overrides = []): AppRelease
    {
        Storage::disk(AppRelease::DISK)->put('apks/build.apk', 'apk-bytes');

        return AppRelease::factory()->create(['apk_path' => 'apks/build.apk', 'version' => '0.1.0', 'version_code' => 1, ...$overrides]);
    }

    public function test_the_download_page_says_so_when_there_is_no_release(): void
    {
        $this->get('/download')->assertOk()->assertSee('isn’t ready to download yet')->assertSee('Android only for now');
    }

    public function test_the_download_page_offers_the_newest_published_release_with_its_checksum(): void
    {
        $this->release(['version' => '0.1.0', 'version_code' => 1]);
        $this->release(['version' => '0.2.0', 'version_code' => 2, 'sha256' => str_repeat('c', 64), 'notes' => 'Faster booking']);
        $this->release(['version' => '0.3.0', 'version_code' => 3, 'is_published' => false]);

        $this->get('/download')
            ->assertOk()
            ->assertSee('Android only for now')
            ->assertSee(url('/download/826-auto-0.2.0.apk'), false)
            ->assertSee(str_repeat('c', 64))
            ->assertSee('Faster booking')
            ->assertDontSee('0.3.0')
            ->assertSee('noindex', false);
    }

    public function test_the_apk_downloads_only_when_published(): void
    {
        $this->release();
        $this->release(['version' => '0.2.0', 'version_code' => 2, 'is_published' => false]);

        $this->get('/download/826-auto-0.1.0.apk')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.android.package-archive')
            ->assertDownload('826-auto-0.1.0.apk');
        $this->get('/download/826-auto-0.2.0.apk')->assertNotFound();
        $this->get('/download/826-auto-9.9.9.apk')->assertNotFound();
    }

    public function test_a_release_whose_file_is_gone_is_a_404_not_a_crash(): void
    {
        $this->release();
        Storage::disk(AppRelease::DISK)->delete('apks/build.apk');

        $this->get('/download/826-auto-0.1.0.apk')->assertNotFound();
    }

    public function test_only_a_super_admin_opens_app_releases(): void
    {
        $this->actingAs(User::factory()->branchManager()->create())->get('/admin/app-releases')->assertForbidden();
        $this->actingAs(User::factory()->salesAdmin()->create())->get('/admin/app-releases')->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())->get('/admin/app-releases')->assertOk();
    }

    public function test_the_owner_uploads_a_release_and_size_and_checksum_come_from_the_file(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreateAppRelease::class)
            ->fillForm([
                'version' => '0.1.0',
                'version_code' => 1,
                'apk_path' => UploadedFile::fake()->create('826.apk', 20, 'application/vnd.android.package-archive'),
                'notes' => 'First build',
                'mandatory' => false,
                'is_published' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $release = AppRelease::sole();
        $this->assertTrue($release->is_published);
        Storage::disk(AppRelease::DISK)->assertExists($release->apk_path);
        $this->assertSame(Storage::disk(AppRelease::DISK)->size($release->apk_path), $release->size);
        $this->assertSame(hash('sha256', Storage::disk(AppRelease::DISK)->get($release->apk_path)), $release->sha256);

        $this->getJson('/api/app/latest')->assertJsonPath('version', '0.1.0')->assertJsonPath('sha256', $release->sha256);
    }

    public function test_the_version_must_look_like_a_version_and_not_repeat(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $this->release();

        Livewire::test(CreateAppRelease::class)
            ->fillForm(['version' => '1.0', 'version_code' => 1, 'apk_path' => UploadedFile::fake()->create('826.apk', 5, 'application/vnd.android.package-archive')])
            ->call('create')
            ->assertHasFormErrors(['version', 'version_code']);
    }

    public function test_the_list_shows_releases_with_the_new_release_button_having_an_icon(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $release = $this->release();

        Livewire::test(ListAppReleases::class)->assertCanSeeTableRecords([$release])->assertActionHasIcon('create', Heroicon::OutlinedPlus);
    }

    public function test_the_download_page_does_not_list_the_team_app_but_its_apk_is_still_served_by_link(): void
    {
        $this->get('/download')->assertOk()->assertDontSee('826 Team');

        $this->release(['version' => '0.1.0', 'version_code' => 1]);
        Storage::disk(AppRelease::DISK)->put('apks/team.apk', 'team-bytes');
        AppRelease::factory()->staff()->create(['apk_path' => 'apks/team.apk', 'version' => '0.1.0', 'version_code' => 1, 'sha256' => str_repeat('d', 64), 'notes' => 'First team build']);

        $this->get('/download')
            ->assertOk()
            ->assertSee(url('/download/826-auto-0.1.0.apk'), false)
            ->assertDontSee('826-team')
            ->assertDontSee('826 Team')
            ->assertDontSee(str_repeat('d', 64))
            ->assertDontSee('First team build');

        $this->get('/download/826-team-0.1.0.apk')->assertOk()->assertDownload('826-team-0.1.0.apk');
        // Each app's file is served under its own name only
        $this->get('/download/826-auto-0.1.0.apk')->assertDownload('826-auto-0.1.0.apk');
        AppRelease::query()->where('app', 'customer')->delete();
        $this->get('/download/826-auto-0.1.0.apk')->assertNotFound();
        AppRelease::query()->where('app', 'staff')->update(['is_published' => false]);
        $this->get('/download/826-team-0.1.0.apk')->assertNotFound();
    }

    public function test_the_owner_adds_a_team_release_with_the_same_version_code_as_the_customer_app(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $this->release(['version' => '0.1.0', 'version_code' => 1]);

        Livewire::test(CreateAppRelease::class)
            ->fillForm(['app' => 'staff', 'version' => '0.1.0', 'version_code' => 1, 'apk_path' => UploadedFile::fake()->create('team.apk', 5, 'application/vnd.android.package-archive'), 'is_published' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['customer', 'staff'], AppRelease::orderBy('id')->pluck('app')->all());

        // But not twice inside the team app
        Livewire::test(CreateAppRelease::class)
            ->fillForm(['app' => 'staff', 'version' => '0.1.0', 'version_code' => 1, 'apk_path' => UploadedFile::fake()->create('team.apk', 5, 'application/vnd.android.package-archive')])
            ->call('create')
            ->assertHasFormErrors(['version', 'version_code']);
    }
}
