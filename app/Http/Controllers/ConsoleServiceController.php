<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReordersRecords;
use App\Models\BackjobType;
use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\VehicleSize;
use App\Support\ImagePipeline;
use App\Support\ServiceForm;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The React admin's Shop → Services, under ServicePolicy like the Filament
 * resource. Prices never reach the site; bookings keep what they were booked with.
 */
class ConsoleServiceController extends Controller
{
    use ReordersRecords;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Service::class);

        $sizes = VehicleSize::query()->active()->count();
        $services = Service::query()->with(['category', 'rates'])->withCount('branches')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'data' => $services->map(fn (Service $service) => [
                'id' => $service->getKey(),
                'name' => $service->name,
                'categoryId' => $service->service_category_id,
                'category' => $service->category?->name,
                'estimateDays' => $service->estimate_days,
                'takes' => ServiceForm::takesLabel($service->estimate_days),
                'prices' => ServiceForm::priceLabel($service),
                'priced' => $service->rates->filter->hasPrice()->count(),
                'sizes' => $sizes,
                'branches' => (int) $service->branches_count,
                'homeServiceOk' => $service->home_service_ok,
                'isActive' => $service->is_active,
            ])->all(),
            'categories' => $this->categories(),
        ])->header('Cache-Control', 'no-store');
    }

    public function form(): JsonResponse
    {
        Gate::authorize('create', Service::class);

        return response()->json(['values' => $this->values(new Service), 'options' => $this->options()]);
    }

    public function show(Service $service): JsonResponse
    {
        Gate::authorize('update', $service);

        return $this->record($service);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Service::class);

        $service = new Service;
        $this->save($request, $service);

        return $this->record($service->refresh())->setStatusCode(201);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        Gate::authorize('update', $service);

        $this->save($request, $service);

        return $this->record($service->refresh());
    }

    public function destroy(Service $service): Response
    {
        Gate::authorize('delete', $service);

        $service->delete();

        return response()->noContent();
    }

    public function reorder(Request $request): Response
    {
        return $this->saveOrder($request, Service::class);
    }

    private function save(Request $request, Service $service): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'serviceCategoryId' => ['required', 'integer', Rule::exists('service_categories', 'id')],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('services', 'slug')->ignore($service->getKey())],
            'summary' => ['nullable', 'string', 'max:160'],
            'estimateDays' => ['nullable', 'integer', 'min:1', 'max:'.ServiceForm::MAX_DAYS],
            'warrantyMonths' => ['nullable', 'integer', 'min:1', 'max:240'],
            'backjobTypes' => ['nullable', 'array'],
            'backjobTypes.*' => ['integer', Rule::exists('warranty_backjob_types', 'id')],
            'description' => ['nullable', 'string', 'max:3000'],
            'inclusions' => ['nullable', 'array'],
            'inclusions.*' => ['required', 'string', 'max:160'],
            'isActive' => ['required', 'boolean'],
            'homeServiceOk' => ['required', 'boolean'],
            'image' => ['nullable', ...UploadRules::image(UploadRules::PHOTO_MAX_KB)],
            'removeImage' => ['sometimes', 'boolean'],
            // The column is unsigned
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'rates' => ['nullable', 'array'],
            'rates.*' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'branches' => ['nullable', 'array'],
            'branches.*' => ['integer', Rule::exists('branches', 'id')],
        ], [
            ...UploadRules::messages('image', UploadRules::PHOTO_MAX_KB),
            'inclusions.*.required' => 'Write the line, or remove it.',
            'inclusions.*.max' => 'Keep the line under 160 characters.',
        ], [
            'serviceCategoryId' => 'category', 'slug' => 'Web address', 'summary' => 'one-line summary', 'estimateDays' => '“Takes (days)” number', 'warrantyMonths' => 'warranty months',
            'sortOrder' => 'order', 'rates.*' => 'price',
        ]);

        $attributes = [
            'name' => $data['name'],
            'service_category_id' => (int) $data['serviceCategoryId'],
            'slug' => $data['slug'],
            'summary' => $data['summary'] ?? null,
            'estimate_days' => $data['estimateDays'] ?? null,
            'warranty_months' => $data['warrantyMonths'] ?? null,
            'description' => $data['description'] ?? null,
            'inclusions' => array_values($data['inclusions'] ?? []),
            'is_active' => (bool) $data['isActive'],
            'home_service_ok' => (bool) $data['homeServiceOk'],
            'sort_order' => (int) ($data['sortOrder'] ?? 0),
        ];

        if ($file = $request->file('image')) {
            // HasImageRenditions turns it into the WebP master and its sizes on save
            $attributes['image'] = $file->store('services', ['disk' => 'public', 'visibility' => 'public']);
        } elseif ($data['removeImage'] ?? false) {
            $attributes['image'] = null;
        }

        $service->fill($attributes)->save();
        ServiceForm::saveRates($service, array_map(fn ($price) => ['price' => $price], $data['rates'] ?? []));
        $service->backjobTypes()->sync($data['backjobTypes'] ?? []);
        $service->branches()->sync($data['branches'] ?? []);
    }

    private function record(Service $service): JsonResponse
    {
        return response()->json([
            'id' => $service->getKey(),
            'name' => $service->name,
            'values' => $this->values($service),
            'options' => $this->options(),
            'imageUrl' => $service->image ? app(ImagePipeline::class)->url($service->image, 'sm') : null,
            'canDelete' => Gate::allows('delete', $service),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Service $service): array
    {
        return [
            'name' => (string) $service->name,
            'serviceCategoryId' => $service->service_category_id,
            'slug' => (string) $service->slug,
            'summary' => $service->summary,
            'estimateDays' => $service->estimate_days,
            'warrantyMonths' => $service->warranty_months,
            'backjobTypes' => $service->exists ? $service->backjobTypes()->pluck('warranty_backjob_types.id')->all() : [],
            'description' => $service->description,
            'inclusions' => $service->inclusionList(),
            'isActive' => $service->exists ? $service->is_active : true,
            'homeServiceOk' => (bool) $service->home_service_ok,
            'sortOrder' => $service->sort_order ?? 0,
            // An object even with no size classes yet
            'rates' => (object) array_map(fn (array $rate) => $rate['price'], ServiceForm::rates($service->exists ? $service->load('rates') : null)),
            'branches' => $service->exists ? $service->branches()->pluck('branches.id')->all() : [],
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function options(): array
    {
        $option = fn (int $value, string $label) => ['value' => (string) $value, 'label' => $label];

        return [
            'categories' => $this->categories(),
            'backjobTypes' => BackjobType::query()->ordered()->get()->map(fn (BackjobType $type) => $option($type->id, $type->name))->all(),
            'sizes' => VehicleSize::query()->ordered()->get()->map(fn (VehicleSize $size) => $option($size->id, $size->name.($size->is_active ? '' : ' (hidden)')))->all(),
            'branches' => Branch::query()->orderBy('sort_order')->orderBy('name')->get()->map(fn (Branch $branch) => $option($branch->id, $branch->name.($branch->status === 'draft' ? ' (draft)' : '')))->all(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function categories(): array
    {
        return ServiceCategory::query()->ordered()->get()->map(fn (ServiceCategory $category) => ['value' => (string) $category->id, 'label' => $category->name])->all();
    }
}
