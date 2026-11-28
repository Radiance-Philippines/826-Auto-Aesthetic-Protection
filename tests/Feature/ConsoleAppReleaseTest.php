<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\Branch;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's App releases: super admin only, closed while viewing as
 * someone, the APK on the private disk with its size and sha256.
 */
class ConsoleAppReleaseTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const RELEASES = '/console/api/system/app-releases';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake(AppRelease::DISK);
    }

    private function release(array $state = []): AppRelease
    {
        Storage::disk(AppRelease::DISK)->put('apks/old.apk', 'old-bytes');

        return AppRelease::factory()->create(['apk_path' => 'apks/old.apk', 'version' => '0.1.0', 'version_code' => 1, ...$state]);
    }

    private function apk(string $bytes = 'apk-bytes'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('app.apk', $bytes)->mimeType('application/vnd.android.package-archive');
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(array $with = []): array
    {
        return ['app' => 'customer', 'version' => '0.2.0', 'versionCode' => '2', 'notes' => '', 'mandatory' => '0', 'isPublished' => '1', ...$with];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['getJson', ''],
            'create' => ['postJson', ''],
            'update' => ['postJson', '/{id}'],
            'delete' => ['deleteJson', '/{id}'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401_and_other_roles_403(string $method, string $path): void
    {
        $url = self::RELEASES.str_replace('{id}', (string) $this->release()->id, $path);

        $this->{$method}($url)->assertUnauthorized();

        $this->actingAs($this->staffUser('sales_admin', Branch::factory()->create(), ['permissions' => ['settings']]));
        $this->{$method}($url)->assertForbidden();
        $this->assertSame(1, AppRelease::query()->count());
    }

    #[DataProvider('endpoints')]
    public function test_it_is_closed_while_viewing_as_someone(string $method, string $path): void
    {
        $admin = User::factory()->owner()->create();
        $url = self::RELEASES.str_replace('{id}', (string) $this->release()->id, $path);
        $this->actingAs($admin)->withSession([Impersonation::IMPERSONATOR => User::factory()->owner()->create()->id, Impersonation::TARGET => $admin->id]);

        $this->assertContains($this->{$method}($url)->getStatusCode(), [403, 419]);
        $this->assertSame(1, AppRelease::query()->count());
    }

    public function test_a_release_is_uploaded_changed_and_deleted(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $id = $this->post(self::RELEASES, $this->fields(['apk' => $this->apk()]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('version', '0.2.0')
            ->assertJsonMissingPath('apk_path')
            ->json('id');

        $release = AppRelease::query()->findOrFail($id);
        $this->assertStringEndsWith('.apk', $release->apk_path);
        Storage::disk(AppRelease::DISK)->assertExists($release->apk_path);
        $this->assertSame([9, hash('sha256', 'apk-bytes'), true, false], [$release->size, $release->sha256, $release->is_published, $release->mandatory]);

        // No new file: the saved one stays
        $this->post(self::RELEASES."/{$id}", $this->fields(['notes' => 'Faster', 'mandatory' => '1']), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('notes', 'Faster');
        $this->assertSame($release->apk_path, $release->fresh()->apk_path);

        $this->post(self::RELEASES."/{$id}", $this->fields(['apk' => $this->apk('newer-apk-bytes')]), ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(hash('sha256', 'newer-apk-bytes'), $release->fresh()->sha256);

        $this->getJson(self::RELEASES)->assertOk()->assertJsonPath('data.0.versionCode', 2)->assertJsonPath('apps.0', ['value' => 'customer', 'label' => 'Customer app']);

        $this->deleteJson(self::RELEASES."/{$id}")->assertNoContent();
        $this->assertSame(0, AppRelease::query()->count());
    }

    public function test_what_the_form_refuses(): void
    {
        $this->release();
        $this->actingAs(User::factory()->owner()->create());

        $this->postJson(self::RELEASES, [])->assertUnprocessable()->assertJsonValidationErrors(['app', 'version', 'versionCode', 'apk', 'mandatory', 'isPublished']);

        $this->post(self::RELEASES, $this->fields(['version' => '0.1.0', 'versionCode' => '1', 'apk' => UploadedFile::fake()->image('photo.png')]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version' => 'This app already has that version.', 'versionCode' => 'This app already has that version code.', 'apk' => 'Choose an Android APK file.']);
        $this->postJson(self::RELEASES, $this->fields(['version' => '1.0', 'versionCode' => 0, 'notes' => str_repeat('a', 2001)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version' => 'Write the version like 0.1.0.', 'versionCode', 'notes']);

        // The same numbers are free for another app
        $this->post(self::RELEASES, $this->fields(['app' => 'staff', 'version' => '0.1.0', 'versionCode' => '1', 'apk' => $this->apk()]), ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(2, AppRelease::query()->count());
    }
}
