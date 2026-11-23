<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /download: the page for the Android app, and the APK itself (only a
 * published release; the file stays on the private disk).
 */
class DownloadController extends Controller
{
    public function page(): View
    {
        return view('download', ['release' => AppRelease::latestPublished()]);
    }

    public function apk(string $version): StreamedResponse
    {
        $release = AppRelease::query()->published()->where('version', $version)->firstOrFail();

        abort_unless(Storage::disk(AppRelease::DISK)->exists($release->apk_path), 404);

        return Storage::disk(AppRelease::DISK)->download($release->apk_path, "826-auto-{$release->version}.apk", [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
