<?php

namespace App\Support;

use App\Models\PaymentSetting;
use Illuminate\Http\Request;

/**
 * The React admin's GCash and Maya fields, in Payment settings and on a
 * branch: the same rules as the Filament forms. A QR is kept as uploaded
 * (no WebP conversion), so it always scans.
 */
final class PaymentAccounts
{
    /** Filament's TextInput::tel() rule */
    public const TEL = 'regex:/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/';

    /**
     * @return array{0: array<string, list<string>>, 1: array<string, string>, 2: array<string, string>} rules, messages, attribute names
     */
    public static function validation(): array
    {
        [$rules, $messages, $names] = [[], [], []];

        foreach (PaymentSetting::METHODS as $method => $label) {
            $rules += [
                "{$method}Enabled" => ['required', 'boolean'],
                "{$method}Qr" => ['nullable', ...UploadRules::image(UploadRules::QR_MAX_KB)],
                "remove{$method}Qr" => ['sometimes', 'boolean'],
                "{$method}Name" => ['nullable', 'string', 'max:120'],
                "{$method}Number" => ['nullable', 'string', 'max:40', self::TEL],
            ];
            $messages += UploadRules::messages("{$method}Qr", UploadRules::QR_MAX_KB);
            $names += ["{$method}Name" => "{$label} account name", "{$method}Number" => "{$label} number"];
        }

        return [$rules, $messages, $names];
    }

    /**
     * What to save, from validated input; a new QR is stored in $directory.
     * (The model deletes the one it replaces.)
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributes(Request $request, array $data, string $directory): array
    {
        $attributes = [];

        foreach (array_keys(PaymentSetting::METHODS) as $method) {
            $attributes["{$method}_enabled"] = (bool) $data["{$method}Enabled"];
            $attributes["{$method}_name"] = $data["{$method}Name"] ?? null;
            $attributes["{$method}_number"] = $data["{$method}Number"] ?? null;

            if ($file = $request->file("{$method}Qr")) {
                $attributes["{$method}_qr"] = $file->store($directory, ['disk' => PaymentSetting::DISK, 'visibility' => 'public']);
            } elseif ($data["remove{$method}Qr"] ?? false) {
                $attributes["{$method}_qr"] = null;
            }
        }

        return $attributes;
    }
}
