<?php

namespace App\Models;

use Database\Factories\AppReleaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One build of the Android app. Uploaded in /admin → App releases (super
 * admin); the newest published one is what /download and
 * GET /api/app/latest offer. The APK stays on the private disk and is
 * served by the download route.
 */
class AppRelease extends Model
{
    /** @use HasFactory<AppReleaseFactory> */
    use HasFactory;

    public const DISK = 'local';

    public const CUSTOMER = 'customer';

    public const STAFF = 'staff';

    /** What an upload may be and how big, for the admin forms (300 MB, as config/livewire.php) */
    public const APK_TYPES = ['application/vnd.android.package-archive', 'application/octet-stream', 'application/zip'];

    public const APK_MAX_KB = 300 * 1024;

    public const VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';

    /**
     * The legacy team app (0.1.x, no X-Team-App header); the per-panel team apps are in config/team_apps.php.
     *
     * @return array<string, string>
     */
    public static function apps(): array
    {
        return [
            self::CUSTOMER => 'Customer app',
            ...array_map(fn (array $app) => $app['label'], config('team_apps')),
            self::STAFF => '826 Team (legacy staff app)',
        ];
    }

    protected $attributes = ['app' => self::CUSTOMER];

    protected $fillable = ['app', 'version', 'version_code', 'apk_path', 'size', 'sha256', 'notes', 'mandatory', 'is_published'];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'size' => 'integer',
            'mandatory' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    public static function latestPublished(string $app = self::CUSTOMER): ?self
    {
        return static::query()->published()->where('app', $app)->orderByDesc('version_code')->first();
    }

    /**
     * Size and sha256 from the stored file, never from what a form says.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fingerprint(array $data): array
    {
        $disk = Storage::disk(self::DISK);

        if (! empty($data['apk_path']) && $disk->exists($data['apk_path'])) {
            $data['size'] = $disk->size($data['apk_path']);
            $data['sha256'] = hash_file('sha256', $disk->path($data['apk_path']));
        }

        return $data;
    }

    /**
     * Where the APK is downloaded from (the file name carries the version).
     */
    public function downloadUrl(): string
    {
        return match (true) {
            $this->app === self::STAFF => route('download.staff-apk', ['version' => $this->version]),
            $this->app === self::CUSTOMER => route('download.apk', ['version' => $this->version]),
            default => route('download.team-apk', ['app' => $this->app, 'version' => $this->version]),
        };
    }

    /**
     * Shape of GET /api/app/latest.
     *
     * @return array{version: string, versionCode: int, url: string, sha256: string|null, notes: string|null, mandatory: bool}
     */
    public function toApp(): array
    {
        return [
            'version' => $this->version,
            'versionCode' => $this->version_code,
            'url' => $this->downloadUrl(),
            'sha256' => $this->sha256,
            'notes' => $this->notes,
            'mandatory' => $this->mandatory,
        ];
    }
}
