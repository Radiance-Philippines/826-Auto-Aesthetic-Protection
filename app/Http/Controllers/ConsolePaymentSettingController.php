<?php

namespace App\Http\Controllers;

use App\Models\PaymentSetting;
use App\Support\PaymentAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The React admin's Settings → Payments, behind the Filament page's own
 * canAccess() (the settings area) and closed while viewing as someone else
 * (ConsoleScreen checks both).
 */
class ConsolePaymentSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = PaymentSetting::instance();
        $qr = fn (?string $path) => $path ? Storage::disk(PaymentSetting::DISK)->url($path) : null;
        $values = [
            'reservationFeeEnabled' => $settings->reservation_fee_enabled,
            'amountValue' => $settings->amount_value,
            'holdMinutes' => $settings->hold_minutes,
            'instructions' => $settings->instructions,
        ];

        foreach (array_keys(PaymentSetting::METHODS) as $method) {
            $values["{$method}Enabled"] = (bool) $settings->{"{$method}_enabled"};
            $values["{$method}Name"] = $settings->{"{$method}_name"};
            $values["{$method}Number"] = $settings->{"{$method}_number"};
        }

        return response()->json(['values' => $values, 'gcashQrUrl' => $qr($settings->gcash_qr), 'mayaQrUrl' => $qr($settings->maya_qr)])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        [$accountRules, $messages, $names] = PaymentAccounts::validation();
        // As in the Filament form, the fee's own fields count only while it is on
        $fee = $request->boolean('reservationFeeEnabled') ? [
            'amountValue' => ['required', 'integer', 'min:1', 'max:1000000'],
            'holdMinutes' => ['required', 'integer', 'min:'.PaymentSetting::MIN_HOLD_MINUTES, 'max:'.PaymentSetting::MAX_HOLD_MINUTES],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ] : [];

        $data = $request->validate(['reservationFeeEnabled' => ['required', 'boolean']] + $fee + $accountRules, $messages, $names + ['amountValue' => 'amount', 'holdMinutes' => '“Hold the slot for” time', 'instructions' => 'payment notes']);

        $attributes = ['reservation_fee_enabled' => (bool) $data['reservationFeeEnabled'], 'amount_rule' => 'fixed'];

        if ($fee !== []) {
            $attributes += ['amount_value' => (int) $data['amountValue'], 'hold_minutes' => (int) $data['holdMinutes'], 'instructions' => $data['instructions'] ?? null];
        }

        PaymentSetting::instance()->update($attributes + PaymentAccounts::attributes($request, $data, 'payments'));

        return $this->show();
    }
}
