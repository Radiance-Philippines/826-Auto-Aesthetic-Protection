<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * Validation rules shared by every place a customer types a password or a
 * phone number (sign-up, reset, change password, profile), in one spot.
 */
final class CustomerRules
{
    /**
     * At least 10 characters with a letter and a digit, never the email
     * address, at most 72 (bcrypt reads no more than that).
     *
     * @return list<mixed>
     */
    public static function password(?string $email = null): array
    {
        return [
            'required',
            'string',
            'max:72',
            Password::min(10)->letters()->numbers(),
            function (string $attribute, mixed $value, Closure $fail) use ($email) {
                if ($email !== null && mb_strtolower((string) $value) === mb_strtolower($email)) {
                    $fail('Your password can’t be your email address.');
                }
            },
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function phone(): array
    {
        return ['nullable', 'string', 'max:20', 'regex:/^\+?[\d\s().-]+$/', self::mobile()];
    }

    /**
     * A Philippine mobile number: 09XX XXX XXXX or +63 9XX XXX XXXX, written
     * with or without spaces, dashes, dots or brackets.
     */
    public static function mobile(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== null && preg_match('/^(0|\+?63)9\d{9}$/', preg_replace('/[\s().-]/', '', (string) $value)) !== 1) {
                $fail('Enter your mobile number, like 0917 123 4567.');
            }
        };
    }
}
