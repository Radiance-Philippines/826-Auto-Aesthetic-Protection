<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;
use App\Support\Staff\TeamApps;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /download: the page for the customers' Android app and the operations
 * app, and the APKs (only a published release; the files stay on the private
 * disk). The other team apps are not listed: their direct links are shared.
 */
class DownloadController extends Controller
{
    public function page(): View
    {
        return view('download', ['release' => AppRelease::latestPublished(), 'operations' => AppRelease::latestPublished('operations')]);
    }

    public function apk(string $version): StreamedResponse
    {
        return $this->download(AppRelease::CUSTOMER, $version, '826-auto');
    }

    public function staffApk(string $version): StreamedResponse
    {
        return $this->download(AppRelease::STAFF, $version, '826-team');
    }

    public function teamApk(string $app, string $version): StreamedResponse
    {
        abort_unless(in_array($app, TeamApps::keys(), true), 404);

        return $this->download($app, $version, "826-{$app}");
    }

    private function download(string $app, string $version, string $name): StreamedResponse
    {
        $release = AppRelease::query()->published()->where('app', $app)->where('version', $version)->firstOrFail();

        abort_unless(Storage::disk(AppRelease::DISK)->exists($release->apk_path), 404);

        return Storage::disk(AppRelease::DISK)->download($release->apk_path, "{$name}-{$release->version}.apk", [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
