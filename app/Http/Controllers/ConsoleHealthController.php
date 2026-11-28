<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;
use App\Support\SystemChecks;
use Illuminate\Http\JsonResponse;

/**
 * The React admin's System health: yes or no, and the live app version.
 */
class ConsoleHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'version' => AppRelease::latestPublished()?->version,
            'checks' => SystemChecks::labelled(),
        ])->header('Cache-Control', 'no-store');
    }
}
