<?php

namespace App\Http\Controllers;

use App\Models\VehicleSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The React admin's Shop → Vehicle sizes, under VehicleSizePolicy like the
 * Filament resource.
 */
class ConsoleVehicleSizeController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', VehicleSize::class);

        return response()
            ->json(['data' => VehicleSize::query()->withCount('rates')->ordered()->get()->map(fn (VehicleSize $size) => $this->row($size))->all()])
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', VehicleSize::class);

        $size = VehicleSize::query()->create($this->validated($request));

        return response()->json($this->row($size->loadCount('rates')), 201);
    }

    public function update(Request $request, VehicleSize $vehicleSize): JsonResponse
    {
        Gate::authorize('update', $vehicleSize);

        $vehicleSize->update($this->validated($request));

        return response()->json($this->row($vehicleSize->loadCount('rates')));
    }

    /**
     * Its prices go too; bookings already made keep the class's name.
     */
    public function destroy(VehicleSize $vehicleSize): Response
    {
        Gate::authorize('delete', $vehicleSize);

        $vehicleSize->delete();

        return response()->noContent();
    }

    /**
     * Every size's id, in the new order.
     */
    public function reorder(Request $request): Response
    {
        Gate::authorize('reorder', VehicleSize::class);

        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'distinct']])['ids'];

        DB::transaction(function () use ($ids) {
            if (VehicleSize::query()->count() !== count($ids) || VehicleSize::query()->whereKey($ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['ids' => 'The list changed. Reload and try again.']);
            }

            foreach (array_values($ids) as $index => $id) {
                VehicleSize::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });

        return response()->noContent();
    }

    /**
     * @return array{name: string, examples: string|null, is_active: bool}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'examples' => ['nullable', 'string', 'max:160'],
            'isActive' => ['required', 'boolean'],
        ], [
            'name.required' => 'Give the size a name.',
            'name.max' => 'Keep the name under 60 characters.',
            'examples.max' => 'Keep the examples under 160 characters.',
        ]);

        return ['name' => $data['name'], 'examples' => $data['examples'] ?? null, 'is_active' => (bool) $data['isActive']];
    }

    /**
     * @return array{id: int, name: string, examples: string|null, isActive: bool, pricesSet: int}
     */
    private function row(VehicleSize $size): array
    {
        return [
            'id' => $size->getKey(),
            'name' => $size->name,
            'examples' => $size->examples,
            'isActive' => $size->is_active,
            'pricesSet' => (int) $size->rates_count,
        ];
    }
}
