<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Service;
use App\Support\BranchOffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The React admin's "Services" of a branch (Filament's BranchServices page,
 * whose canAccess guards both routes): which services each branch in view
 * offers. Only the ticks are saved.
 */
class ConsoleBranchServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'branches' => BranchOffer::branches($request->user())->map(fn (Branch $branch) => [
                'id' => $branch->getKey(),
                'name' => $branch->name,
                'services' => $branch->services->pluck('id')->all(),
            ])->values()->all(),
            'groups' => BranchOffer::services()->map(fn ($services, string $category) => [
                'category' => $category,
                'services' => $services->map(fn (Service $service) => ['value' => (string) $service->id, 'label' => $service->name])->values()->all(),
            ])->values()->all(),
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $branch): Response
    {
        // Another branch's (or one outside the top bar's choice) is not found
        $record = Branch::query()->visibleTo($request->user())->findOrFail($branch);

        $data = $request->validate([
            'services' => ['present', 'array'],
            'services.*' => ['integer', Rule::exists('services', 'id')->where('is_active', true)],
        ]);

        BranchOffer::save($record, $data['services']);

        return response()->noContent();
    }
}
