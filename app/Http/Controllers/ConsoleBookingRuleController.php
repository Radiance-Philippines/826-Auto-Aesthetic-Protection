<?php

namespace App\Http\Controllers;

use App\Models\BookingSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The React admin's Booking rules, like the Filament page (settings area,
 * closed while viewing as someone: both from ConsoleScreen).
 */
class ConsoleBookingRuleController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->record(BookingSetting::instance());
    }

    public function update(Request $request): JsonResponse
    {
        $moving = ['exclude_unless:rescheduleEnabled,true', 'required', 'integer'];
        $data = $request->validate([
            'cancelCutoffHours' => ['required', 'integer', 'min:0', 'max:720'],
            'rescheduleEnabled' => ['required', 'boolean'],
            'rescheduleCutoffHours' => [...$moving, 'min:0', 'max:720'],
            'maxReschedules' => [...$moving, 'min:1', 'max:10'],
            'maxUnpaidHoldsPerGuest' => ['required', 'integer', 'min:0', 'max:20'],
            'maxUpcomingPerGuest' => ['required', 'integer', 'min:0', 'max:50'],
            'policyText' => ['nullable', 'string', 'max:4000'],
        ], [], [
            'cancelCutoffHours' => 'hours',
            'rescheduleCutoffHours' => 'hours',
            'maxReschedules' => 'number of times',
            'maxUnpaidHoldsPerGuest' => 'unpaid holds',
            'maxUpcomingPerGuest' => 'upcoming bookings',
            'policyText' => 'terms',
        ]);

        // With moving off, its two limits are hidden and keep what they had
        $settings = BookingSetting::instance();
        $settings->update([
            'cancel_cutoff_hours' => $data['cancelCutoffHours'],
            'reschedule_enabled' => $request->boolean('rescheduleEnabled'),
            ...(isset($data['rescheduleCutoffHours']) ? ['reschedule_cutoff_hours' => $data['rescheduleCutoffHours']] : []),
            ...(isset($data['maxReschedules']) ? ['max_reschedules' => $data['maxReschedules']] : []),
            'max_unpaid_holds_per_guest' => $data['maxUnpaidHoldsPerGuest'],
            'max_upcoming_per_guest' => $data['maxUpcomingPerGuest'],
            'policy_text' => $data['policyText'] ?? null,
        ]);

        return $this->record($settings->refresh());
    }

    private function record(BookingSetting $settings): JsonResponse
    {
        return response()->json([
            'cancelCutoffHours' => $settings->cancel_cutoff_hours,
            'rescheduleEnabled' => (bool) $settings->reschedule_enabled,
            'rescheduleCutoffHours' => $settings->reschedule_cutoff_hours,
            'maxReschedules' => $settings->max_reschedules,
            'maxUnpaidHoldsPerGuest' => $settings->max_unpaid_holds_per_guest,
            'maxUpcomingPerGuest' => $settings->max_upcoming_per_guest,
            'policyText' => $settings->policy_text,
        ])->header('Cache-Control', 'no-store');
    }
}
