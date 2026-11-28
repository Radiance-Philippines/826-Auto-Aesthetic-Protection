<?php

namespace App\Http\Controllers;

use App\Models\ReferralSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The React admin's Referral settings, like the Filament page (settings area,
 * closed while viewing as someone).
 */
class ConsoleReferralSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->record(ReferralSetting::instance());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'discountType' => ['required', Rule::in(array_keys(ReferralSetting::TYPES))],
            'discountValue' => [Rule::requiredIf($request->boolean('enabled')), 'nullable', 'integer', 'min:1', 'max:'.($request->input('discountType') === 'percent' ? 100 : 10_000_000)],
            'ownerPoints' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'pesoPerPoint' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ], ['discountValue.required' => 'Set the discount before accepting codes.'], [
            'discountValue' => 'discount',
            'ownerPoints' => 'points',
            'pesoPerPoint' => 'pesos per point',
        ]);

        $settings = ReferralSetting::instance();
        $settings->update([
            'enabled' => $request->boolean('enabled'),
            'discount_type' => $data['discountType'],
            'discount_value' => $data['discountValue'] ?? null,
            'owner_points' => $data['ownerPoints'] ?? null,
            'peso_per_point' => $data['pesoPerPoint'] ?? null,
        ]);

        return $this->record($settings->refresh());
    }

    private function record(ReferralSetting $settings): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'discountType' => $settings->discount_type,
            'discountValue' => $settings->discount_value,
            'ownerPoints' => $settings->owner_points,
            'pesoPerPoint' => $settings->peso_per_point,
            'types' => collect(ReferralSetting::TYPES)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
        ])->header('Cache-Control', 'no-store');
    }
}
