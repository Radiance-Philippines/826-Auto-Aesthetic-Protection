<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reading the branch, services, date and unit a customer picked, shared by
 * the availability, reservation and home-service endpoints.
 */
trait ReadsBookingInput
{
    /**
     * The honeypot field: hidden from people, filled in by bots.
     */
    public const HONEYPOT = 'fax_number';

    protected function guest(): ?Guest
    {
        $guest = Auth::guard('guest')->user();

        return $guest instanceof Guest ? $guest : null;
    }

    /**
     * A branch shown on the site; drafts are never found.
     */
    protected function listedBranch(mixed $id): Branch
    {
        $branch = is_numeric($id) ? Branch::query()->listed()->find((int) $id) : null;

        if (! $branch) {
            throw ValidationException::withMessages(['branch' => 'Pick a branch from the list.']);
        }

        return $branch;
    }

    /**
     * @return list<int>
     */
    protected function serviceIds(Request $request): array
    {
        $ids = $request->input('services', []);

        return is_array($ids) ? array_values(array_map('intval', array_filter($ids, 'is_numeric'))) : [];
    }

    protected function dateFrom(mixed $value, string $field = 'date'): CarbonImmutable
    {
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value)
            : false;

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => 'Pick a date on the calendar.']);
        }

        return $date;
    }

    /**
     * A booking the request may open (signed-in owner or private key), else 404.
     */
    protected function openableBooking(Request $request, mixed $id): Booking
    {
        $booking = is_numeric($id) ? Booking::find((int) $id) : null;
        $key = is_string($request->input('key', $request->query('key'))) ? $request->input('key', $request->query('key')) : null;

        abort_unless($booking && $booking->isOpenedBy($this->guest(), $key), 404);

        return $booking;
    }

    /**
     * @return array<string, mixed>
     */
    protected function contactRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^\+?[\d\s().-]+$/', function (string $attribute, mixed $value, Closure $fail) {
                $digits = strlen(preg_replace('/\D/', '', (string) $value));

                if ($digits < 10 || $digits > 13) {
                    $fail('Enter your mobile number, like 0917 123 4567.');
                }
            }],
            'email' => ['nullable', 'email', 'max:190'],
            'message' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
        ];
    }

    /**
     * The customer's unit: one of their saved ones (vehicle_id), or its
     * year, make and model, type and plate.
     *
     * @return array<string, mixed>
     */
    protected function vehicleRules(): array
    {
        return [
            'vehicle_id' => ['nullable', 'integer'],
            'vehicle_year' => ['required_without:vehicle_id', 'nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            'vehicle_model' => ['required_without:vehicle_id', 'nullable', 'string', 'max:80'],
            // Not asked on the website (the team sees the unit); the app may still send it
            'vehicle_type' => ['nullable', Rule::in(array_keys(Booking::VEHICLE_TYPES))],
            'plate_number' => ['required_without:vehicle_id', 'nullable', 'string', 'max:15', 'regex:/^[A-Za-z0-9 -]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function contactMessages(): array
    {
        return [
            'name.required' => 'Add your name so the branch knows who to look for.',
            'name.max' => 'That name is too long. Keep it under 120 characters.',
            'phone.required' => 'Add a mobile number so the branch can reach you.',
            'phone.regex' => 'Use numbers only, like 0917 123 4567.',
            'email.email' => 'That doesn’t look like an email address. Check it, or leave it blank.',
            'vehicle_year.required_without' => 'Add your car’s year.',
            'vehicle_year.integer' => 'Use a year, like 2021.',
            'vehicle_year.between' => 'That year doesn’t look right. Check it.',
            'vehicle_model.required_without' => 'Add your car’s make and model, like Toyota Vios.',
            'vehicle_type.in' => 'Pick the type of your car from the list.',
            'plate_number.required_without' => 'Add your plate number.',
            'plate_number.regex' => 'Use letters and numbers only for the plate.',
            'plate_number.max' => 'That plate number is too long.',
            'message.max' => 'Keep the note under 1,000 characters.',
            'consent.accepted' => 'Agree to the privacy notice to continue.',
        ];
    }
}
