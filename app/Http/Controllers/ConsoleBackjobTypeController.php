<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReordersRecords;
use App\Models\BackjobType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The React admin's Shop → Backjob types, under BackjobTypePolicy like the
 * Filament resource. Backjobs already logged keep their record when a type goes.
 */
class ConsoleBackjobTypeController extends Controller
{
    use ReordersRecords;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', BackjobType::class);

        return response()
            ->json(['data' => BackjobType::query()->withCount('services')->orderBy('sort_order')->orderBy('id')->get()->map(fn (BackjobType $type) => $this->row($type))->all()])
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', BackjobType::class);

        $type = BackjobType::query()->create($this->validated($request));

        return response()->json($this->row($type->loadCount('services')), 201);
    }

    public function update(Request $request, BackjobType $backjobType): JsonResponse
    {
        Gate::authorize('update', $backjobType);

        $backjobType->update($this->validated($request));

        return response()->json($this->row($backjobType->loadCount('services')));
    }

    public function destroy(BackjobType $backjobType): Response
    {
        Gate::authorize('delete', $backjobType);

        $backjobType->delete();

        return response()->noContent();
    }

    public function reorder(Request $request): Response
    {
        return $this->saveOrder($request, BackjobType::class);
    }

    /**
     * @return array{name: string, is_active: bool}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'isActive' => ['required', 'boolean']]);

        return ['name' => $data['name'], 'is_active' => (bool) $data['isActive']];
    }

    /**
     * @return array{id: int, name: string, isActive: bool, services: int}
     */
    private function row(BackjobType $type): array
    {
        return ['id' => $type->getKey(), 'name' => $type->name, 'isActive' => $type->is_active, 'services' => (int) $type->services_count];
    }
}
