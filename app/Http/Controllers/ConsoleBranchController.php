<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReordersRecords;
use App\Models\Branch;
use App\Models\PaymentSetting;
use App\Models\Service;
use App\Models\User;
use App\Support\Access;
use App\Support\BranchForm;
use App\Support\Inbox\InboxException;
use App\Support\Meta\PageConnection;
use App\Support\PaymentAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The React admin's Shop → Branches, under BranchPolicy like the Filament
 * resource (which shows every branch, whatever the top bar's selector says).
 * The Page token is write-only: it is never answered.
 */
class ConsoleBranchController extends Controller
{
    use ReordersRecords;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Branch::class);

        $branches = Branch::query()->withCount('staff')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $branches->map(fn (Branch $branch) => [
            'id' => $branch->getKey(),
            'name' => $branch->name,
            'city' => $branch->city,
            'status' => $branch->status,
            'statusLabel' => BranchForm::statusLabel($branch->status),
            'online' => BranchForm::onlineLabel($branch),
            'takingBookings' => $branch->isOnsiteBookable(),
            'dailyCap' => $branch->daily_cap,
            'staff' => (int) $branch->staff_count,
        ])->all()])->header('Cache-Control', 'no-store');
    }

    public function form(): JsonResponse
    {
        Gate::authorize('create', Branch::class);

        return response()->json(['values' => $this->values(new Branch), 'options' => $this->options()]);
    }

    public function show(int $id): JsonResponse
    {
        $branch = Branch::query()->findOrFail($id);
        Gate::authorize('update', $branch);

        return $this->record($branch);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Branch::class);

        $branch = new Branch;
        $this->save($request, $branch);

        return $this->record($branch->refresh())->setStatusCode(201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $branch = Branch::query()->findOrFail($id);
        Gate::authorize('update', $branch);

        $this->save($request, $branch);
        $branch->refresh();

        return $this->record($branch, BranchForm::warnings($branch));
    }

    public function destroy(int $id): Response
    {
        $branch = Branch::query()->findOrFail($id);
        Gate::authorize('delete', $branch);

        $branch->delete();

        return response()->noContent();
    }

    public function reorder(Request $request): Response
    {
        return $this->saveOrder($request, Branch::class);
    }

    /**
     * "Connect check": the typed Page ID and token (the saved token when none is typed).
     */
    public function connect(Request $request, int $id): JsonResponse
    {
        $branch = Branch::query()->findOrFail($id);
        Gate::authorize('update', $branch);

        $data = $request->validate([
            'facebookPageId' => ['nullable', 'string', 'max:50'],
            'facebookPageToken' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $warning = PageConnection::check($branch, (string) ($data['facebookPageId'] ?? ''), $data['facebookPageToken'] ?? null);
        } catch (InboxException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'title' => 'Connected to '.$branch->facebook_page_name,
            'body' => $warning ?? 'The token and the Page ID match. New messages arrive in the Inbox.',
            'warning' => $warning !== null,
            'facebook' => $this->facebook($branch),
        ]);
    }

    private function save(Request $request, Branch $branch): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('branches', 'slug')->ignore($branch->getKey())],
            'status' => ['required', Rule::in(array_keys(Branch::STATUSES))],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40', PaymentAccounts::TEL],
            'messengerUrl' => ['nullable', 'url', 'max:255'],
            'facebookUrl' => ['nullable', 'url', 'max:255'],
            'mapUrl' => ['nullable', 'url', 'max:500'],
            'mapLat' => ['nullable', 'numeric', 'min:4', 'max:22'],
            'mapLng' => ['nullable', 'numeric', 'min:116', 'max:127'],
            'alertEmail' => ['nullable', 'email', 'max:190'],
            // The column is unsigned
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'dailyCap' => ['required', 'integer', 'min:0', 'max:'.Branch::MAX_DAILY_CAP],
            'leadDays' => ['required', 'integer', 'min:0', 'max:'.Branch::MAX_LEAD_DAYS],
            'monthsAhead' => ['required', 'integer', 'min:1', 'max:12'],
            'weeklyHours' => ['required', 'array'],
            'services' => ['nullable', 'array'],
            'services.*' => ['integer', Rule::exists('services', 'id')],
            'offersHomeService' => ['required', 'boolean'],
            'homeServiceAreas' => ['nullable', 'array'],
            'homeServiceAreas.*' => ['string', 'max:255'],
            'facebookPageId' => ['nullable', 'string', 'max:50', 'regex:/^[0-9]+$/', Rule::unique('branches', 'facebook_page_id')->ignore($branch->getKey())],
            'facebookPageToken' => ['nullable', 'string', 'max:1000'],
            'staff' => ['nullable', 'array'],
            'staff.*' => ['integer', Rule::exists('users', 'id')->whereNot('role', Access::SUPER_ADMIN)],
        ];
        $messages = [];
        $names = ['slug' => 'Web address', 'alertEmail' => '“Email new bookings to” address', 'dailyCap' => 'daily cap', 'leadDays' => 'notice needed', 'monthsAhead' => '“Book up to” choice', 'messengerUrl' => 'Messenger link', 'facebookUrl' => 'Facebook page', 'mapUrl' => 'Google Maps link', 'mapLat' => 'latitude', 'mapLng' => 'longitude', 'sortOrder' => 'order on the site', 'facebookPageId' => 'Facebook Page ID', 'facebookPageToken' => 'Page access token'];

        foreach (Branch::WEEKDAYS as $day => $label) {
            $open = "weeklyHours.{$day}.openToday";
            $rules += [
                $open => ['required', 'boolean'],
                "weeklyHours.{$day}.open" => ['nullable', 'required_if_accepted:'.$open, 'date_format:H:i'],
                "weeklyHours.{$day}.close" => ['nullable', 'required_if_accepted:'.$open, 'date_format:H:i'],
            ];

            // Only an open day's times are compared: a closed day keeps whatever was typed
            if ($request->boolean($open)) {
                $rules["weeklyHours.{$day}.close"][] = "after:weeklyHours.{$day}.open";
            }

            $messages["weeklyHours.{$day}.open.required_if_accepted"] = "Set when {$label} opens.";
            $messages["weeklyHours.{$day}.close.required_if_accepted"] = "Set when {$label} closes.";
            $messages["weeklyHours.{$day}.close.after"] = "{$label} must close after it opens.";
        }

        [$accountRules, $accountMessages, $accountNames] = PaymentAccounts::validation();
        $data = $request->validate($rules + $accountRules, $messages + $accountMessages, $names + $accountNames);

        $attributes = BranchForm::saveHours([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'status' => $data['status'],
            'city' => $data['city'] ?? null,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'messenger_url' => $data['messengerUrl'] ?? null,
            'facebook_url' => $data['facebookUrl'] ?? null,
            'map_url' => $data['mapUrl'] ?? null,
            'map_lat' => $data['mapLat'] ?? null,
            'map_lng' => $data['mapLng'] ?? null,
            'alert_email' => $data['alertEmail'] ?? null,
            'sort_order' => (int) ($data['sortOrder'] ?? 0),
            'daily_cap' => (int) $data['dailyCap'],
            'lead_days' => (int) $data['leadDays'],
            'months_ahead' => (int) $data['monthsAhead'],
            'offers_home_service' => (bool) $data['offersHomeService'],
            'facebook_page_id' => $data['facebookPageId'] ?? null,
            'weekly_hours' => collect($data['weeklyHours'])->map(fn (array $day) => [
                'open_today' => (bool) $day['openToday'],
                'open' => $day['open'] ?? null,
                'close' => $day['close'] ?? null,
            ])->all(),
        ]);

        // As in Filament, a hidden field keeps what was saved
        if ($attributes['offers_home_service']) {
            $attributes['home_service_areas'] = array_values($data['homeServiceAreas'] ?? []);
        }

        if (filled($data['facebookPageToken'] ?? null)) {
            $attributes['facebook_page_token'] = $data['facebookPageToken'];
        }

        $branch->fill($attributes + PaymentAccounts::attributes($request, $data, 'payments/branches'))->save();
        $branch->services()->sync($data['services'] ?? []);

        // The list offers everyone but super admins, so a super admin attached elsewhere stays
        $chosen = $data['staff'] ?? [];
        $branch->staff()->detach($branch->staff()->where('role', '!=', Access::SUPER_ADMIN)->whereNotIn('users.id', $chosen)->pluck('users.id'));
        $branch->staff()->syncWithoutDetaching($chosen);
    }

    /**
     * @param  list<array{title: string, body: string}>  $warnings
     */
    private function record(Branch $branch, array $warnings = []): JsonResponse
    {
        $qr = fn (?string $path) => $path ? Storage::disk(PaymentSetting::DISK)->url($path) : null;

        return response()->json([
            'id' => $branch->getKey(),
            'name' => $branch->name,
            'values' => $this->values($branch),
            'options' => $this->options(),
            'gcashQrUrl' => $qr($branch->gcash_qr),
            'mayaQrUrl' => $qr($branch->maya_qr),
            'missing' => $branch->missing(),
            'warnings' => $warnings,
            'canDelete' => Gate::allows('delete', $branch),
            'siteUrl' => $branch->status === 'draft' ? null : url("/branches/{$branch->slug}"),
            'facebook' => $this->facebook($branch),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * @return array{pageName: string|null, hasToken: bool, status: string}
     */
    private function facebook(Branch $branch): array
    {
        return [
            'pageName' => $branch->facebook_page_name,
            'hasToken' => filled($branch->facebook_page_token),
            'status' => $branch->hasFacebookPage() ? 'Connected '.$branch->facebook_connected_at->diffForHumans() : 'Not connected',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Branch $branch): array
    {
        $hours = BranchForm::fillHours(['weekly_hours' => $branch->weekly_hours])['weekly_hours'];
        $values = [
            'name' => (string) $branch->name,
            'slug' => (string) $branch->slug,
            'status' => $branch->status,
            'city' => $branch->city,
            'address' => $branch->address,
            'phone' => $branch->phone,
            'messengerUrl' => $branch->messenger_url,
            'facebookUrl' => $branch->facebook_url,
            'mapUrl' => $branch->map_url,
            'mapLat' => $branch->map_lat,
            'mapLng' => $branch->map_lng,
            'alertEmail' => $branch->alert_email,
            'sortOrder' => $branch->sort_order ?? 0,
            'dailyCap' => $branch->daily_cap,
            'leadDays' => $branch->lead_days,
            'monthsAhead' => $branch->months_ahead,
            'weeklyHours' => collect($hours)->map(fn (array $day) => [
                'openToday' => $day['open_today'],
                'open' => Branch::clock($day['open']),
                'close' => Branch::clock($day['close']),
            ])->all(),
            'services' => $branch->exists ? $branch->services()->pluck('services.id')->all() : [],
            'offersHomeService' => (bool) $branch->offers_home_service,
            'homeServiceAreas' => $branch->homeServiceAreas(),
            'facebookPageId' => $branch->facebook_page_id,
            'staff' => $branch->exists ? $branch->staff()->where('role', '!=', Access::SUPER_ADMIN)->pluck('users.id')->all() : [],
        ];

        foreach (array_keys(Branch::paymentMethodLabels()) as $method) {
            $values["{$method}Enabled"] = (bool) $branch->{"{$method}_enabled"};
            $values["{$method}Name"] = $branch->{"{$method}_name"};
            $values["{$method}Number"] = $branch->{"{$method}_number"};
        }

        return $values;
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function options(): array
    {
        $option = fn (int|string $value, string $label) => ['value' => (string) $value, 'label' => $label];

        return [
            'statuses' => collect(Branch::STATUSES)->map(fn (string $label, string $value) => $option($value, $label))->values()->all(),
            'services' => Service::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])->map(fn (Service $service) => $option($service->id, $service->name))->all(),
            'staff' => User::query()->where('role', '!=', Access::SUPER_ADMIN)->orderBy('name')->get(['id', 'name', 'email'])->map(fn (User $user) => $option($user->id, "{$user->name} ({$user->email})"))->all(),
        ];
    }
}
