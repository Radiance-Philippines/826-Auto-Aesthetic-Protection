<?php

namespace App\Http\Controllers;

use App\Filament\Pages\BranchServices;
use App\Models\Branch;
use App\Support\BranchOffer;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saves which services a branch offers. Only the ticks: services themselves are not changed.
 */
class BranchServicesController extends Controller
{
    public function __invoke(Request $request, Branch $branch): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canAccessArea(BranchServices::AREA) && $user->canManageBranch($branch), 403);

        $data = $request->validate([
            'services' => ['sometimes', 'array'],
            'services.*' => ['integer', Rule::exists('services', 'id')->where('is_active', true)],
        ]);

        BranchOffer::save($branch, $data['services'] ?? []);

        Notification::make()->success()->title("{$branch->name}: services saved")->send();

        return back();
    }
}
