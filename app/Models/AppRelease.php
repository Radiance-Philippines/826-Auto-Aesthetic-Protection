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

    protected $fillable = ['version', 'version_code', 'apk_path', 'size', 'sha256', 'notes', 'mandatory', 'is_published'];

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

    public static function latestPublished(): ?self
    {
        return static::query()->published()->orderByDesc('version_code')->first();
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
        return route('download.apk', ['version' => $this->version]);
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
