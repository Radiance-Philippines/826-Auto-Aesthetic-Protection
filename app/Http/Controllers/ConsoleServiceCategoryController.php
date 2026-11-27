<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReordersRecords;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The React admin's Shop → Categories, under ServiceCategoryPolicy like the
 * Filament resource. A category with services is never deleted.
 */
class ConsoleServiceCategoryController extends Controller
{
    use ReordersRecords;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', ServiceCategory::class);

        return response()
            ->json(['data' => ServiceCategory::query()->withCount('services')->orderBy('sort_order')->orderBy('id')->get()->map(fn (ServiceCategory $category) => $this->row($category))->all()])
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', ServiceCategory::class);

        $category = ServiceCategory::query()->create($this->validated($request, new ServiceCategory));

        return response()->json($this->row($category->loadCount('services')), 201);
    }

    public function update(Request $request, ServiceCategory $category): JsonResponse
    {
        Gate::authorize('update', $category);

        $category->update($this->validated($request, $category));

        return response()->json($this->row($category->loadCount('services')));
    }

    public function destroy(ServiceCategory $category): Response
    {
        Gate::authorize('delete', $category);
        // The Filament resource only offers Delete for an empty category
        abort_if($category->services()->exists(), 403, 'This category still has services. Move them to another one first.');

        $category->delete();

        return response()->noContent();
    }

    public function reorder(Request $request): Response
    {
        return $this->saveOrder($request, ServiceCategory::class);
    }

    /**
     * @return array{name: string, slug: string, description: string|null, is_active: bool}
     */
    private function validated(Request $request, ServiceCategory $category): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'string', 'alpha_dash', 'max:80', Rule::unique('service_categories', 'slug')->ignore($category->getKey())],
            'description' => ['nullable', 'string', 'max:1000'],
            'isActive' => ['required', 'boolean'],
        ]);

        return ['name' => $data['name'], 'slug' => $data['slug'], 'description' => $data['description'] ?? null, 'is_active' => (bool) $data['isActive']];
    }

    /**
     * @return array{id: int, name: string, slug: string, description: string|null, isActive: bool, services: int}
     */
    private function row(ServiceCategory $category): array
    {
        return [
            'id' => $category->getKey(),
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'isActive' => $category->is_active,
            'services' => (int) $category->services_count,
        ];
    }
}
