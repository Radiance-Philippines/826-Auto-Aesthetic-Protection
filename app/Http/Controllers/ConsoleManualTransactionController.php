<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\ReferralSetting;
use App\Models\Service;
use App\Models\VehicleSize;
use App\Support\BookingDesk;
use App\Support\ManualTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The React admin's Manual transaction: the Filament page's form, saved
 * through App\Support\ManualTransaction.
 */
class ConsoleManualTransactionController extends StaffController
{
    private const MAX_PESOS = 10_000_000;

    /**
     * GET ?branch=: what the form offers, with that branch's services.
     */
    public function form(Request $request): JsonResponse
    {
        $this->requireArea('bookings.sales');

        $branches = BookingDesk::branchOptions($this->user());
        $branch = (int) $request->query('branch');
        $referral = ReferralSetting::current();

        return response()->json([
            'branches' => $this->options($branches),
            'services' => isset($branches[$branch]) ? $this->options(BookingDesk::serviceOptions($branch)) : [],
            'statuses' => $this->options($this->statuses()),
            'methods' => $this->options(ManualTransaction::METHODS),
            'types' => $this->options(Booking::VEHICLE_TYPES),
            'sizes' => $this->options($this->sizes()),
            'maxYear' => now()->year + 1,
            'today' => today()->toDateString(),
            // Null: no referral codes are being taken
            'referralHint' => $referral->isActive() ? 'For a customer’s first booking only: '.$referral->label().' for them, and the code’s owner earns points when the job is Exited.' : null,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET rates?size=&services[]=: each picked service's starting price for that size class.
     */
    public function rates(Request $request): JsonResponse
    {
        $this->requireArea('bookings.sales');

        $ids = array_map('intval', array_filter((array) $request->query('services', []), 'is_numeric'));
        $size = (int) $request->query('size');
        $rates = $size > 0 ? ManualTransaction::ratesFor($size, $ids) : [];

        return response()->json([
            'data' => Service::query()->whereKey($ids)->orderBy('sort_order')->get()->map(fn (Service $service) => [
                'id' => $service->getKey(),
                'name' => $service->name,
                'price' => $rates[$service->getKey()] ?? null,
            ])->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET customers?search=: this person's customers.
     */
    public function customers(Request $request): JsonResponse
    {
        $this->requireArea('bookings.sales');

        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';
        $guests = Guest::query()
            ->visibleTo($this->user())
            ->where(fn (Builder $match) => $match->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $guests->map(fn (Guest $guest) => ['value' => (string) $guest->getKey(), 'label' => $guest->name.($guest->phone ? " · {$guest->phone}" : '')])->all(),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireArea('bookings.sales');

        $user = $this->user();
        $branches = BookingDesk::branchOptions($user);
        $branch = is_numeric($request->input('branchId')) ? (int) $request->input('branchId') : null;
        $new = $request->boolean('newCustomer');
        $money = ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PESOS];
        $picked = array_filter((array) $request->input('services', []), 'is_numeric');

        $data = $request->validate([
            'branchId' => ['required', Rule::in(array_keys($branches))],
            'newCustomer' => ['boolean'],
            'guestId' => $new ? ['exclude'] : ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                if (! Guest::query()->visibleTo($user)->whereKey((int) $value)->exists()) {
                    $fail('Pick one of your customers.');
                }
            }],
            'name' => $new ? ['required', 'string', 'max:120'] : ['exclude'],
            'phone' => $new ? ['required', 'string', 'max:40', 'regex:'.ConsoleBookingController::PHONE] : ['exclude'],
            'serviceDate' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(ManualTransaction::STATUSES)],
            'vehicleYear' => ['required', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'vehicleModel' => ['required', 'string', 'max:80'],
            'vehicleType' => ['required', Rule::in(array_keys(Booking::VEHICLE_TYPES))],
            'plateNumber' => ['required', 'string', 'max:15'],
            'vehicleSizeId' => ['required', Rule::in(array_keys($this->sizes()))],
            'services' => ['required', 'array'],
            'services.*' => ['integer', Rule::in(array_keys(BookingDesk::serviceOptions($branch)))],
            'prices' => ['array'],
            ...collect($picked)->mapWithKeys(fn (mixed $id) => ['prices.'.(int) $id => ['required', 'integer', 'min:0', 'max:'.self::MAX_PESOS]])->all(),
            'discount' => $money,
            'referralCode' => ReferralSetting::current()->isActive() ? ['nullable', 'string', 'max:40'] : ['exclude'],
            'amountPaid' => $money,
            'paymentMethod' => [(int) $request->input('amountPaid') > 0 ? 'required' : 'nullable', Rule::in(array_keys(ManualTransaction::METHODS))],
            'paymentReference' => ['nullable', 'string', 'max:255'],
            'adminNote' => ['nullable', 'string', 'max:2000'],
        ], [
            'guestId.required' => 'Pick the customer, or turn on “New customer”.',
            'services.required' => 'Pick at least one service.',
            'prices.*.required' => 'Type the price.',
            'paymentMethod.required' => 'Say how it was paid.',
            'phone.regex' => 'Use numbers only, like 0917 123 4567.',
        ], [
            'branchId' => 'branch',
            'serviceDate' => 'date of service',
            'vehicleYear' => 'year',
            'vehicleModel' => 'make and model',
            'vehicleType' => 'type',
            'plateNumber' => 'plate number',
            'vehicleSizeId' => 'size class',
            'phone' => 'mobile',
            'prices.*' => 'price',
            'amountPaid' => 'amount paid',
            'paymentMethod' => 'payment method',
            'paymentReference' => 'reference note',
            'adminNote' => 'private note',
            'referralCode' => 'referral code',
        ]);

        try {
            $booking = ManualTransaction::record($user, [
                'branch_id' => $data['branchId'],
                'guest_id' => $data['guestId'] ?? null,
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'vehicle_year' => $data['vehicleYear'],
                'vehicle_model' => $data['vehicleModel'],
                'vehicle_type' => $data['vehicleType'],
                'plate_number' => $data['plateNumber'],
                'vehicle_size_id' => $data['vehicleSizeId'],
                'services' => $data['services'],
                'prices' => $data['prices'] ?? [],
                'service_date' => $data['serviceDate'],
                'status' => $data['status'],
                'discount' => $data['discount'] ?? null,
                'amount_paid' => $data['amountPaid'] ?? null,
                'payment_method' => $data['paymentMethod'] ?? null,
                'payment_reference' => $data['paymentReference'] ?? null,
                'admin_note' => $data['adminNote'] ?? null,
                'referral_code' => $data['referralCode'] ?? null,
            ]);
        } catch (ValidationException $e) {
            if (isset($e->errors()['referral_code'])) {
                throw ValidationException::withMessages(['referralCode' => $e->errors()['referral_code']]);
            }

            // Anything else is about the whole entry, as the page's "Not saved" notice
            throw ValidationException::withMessages(['transaction' => collect($e->errors())->flatten()->first()]);
        }

        return response()->json(['id' => $booking->getKey(), 'message' => "{$booking->reference} · ₱".number_format((int) $booking->total_price)], 201);
    }

    /**
     * @return array<string, string>
     */
    private function statuses(): array
    {
        return collect(ManualTransaction::STATUSES)->mapWithKeys(fn (string $status) => [$status => $status === 'confirmed' ? 'Booked' : Booking::STATUSES[$status]])->all();
    }

    /**
     * @return array<int, string>
     */
    private function sizes(): array
    {
        return VehicleSize::query()->active()->ordered()->pluck('name', 'id')->all();
    }

    /**
     * @param  array<int|string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private function options(array $options): array
    {
        return collect($options)->map(fn (string $label, int|string $value) => ['value' => (string) $value, 'label' => $label])->values()->all();
    }
}
