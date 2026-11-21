<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsBookingInput;
use App\Models\ReferralSetting;
use App\Support\Referrals;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/my-referral: the signed-in customer's referral code and what it
 * has done. A demo has no signed-in customer, so it gets a sample.
 */
class ReferralController extends Controller
{
    use ReadsBookingInput;

    public function show(): JsonResponse
    {
        $guest = $this->guest();
        abort_unless($guest || config('app.demo'), 401);

        $settings = ReferralSetting::current();

        if (! $guest) {
            return response()->json(['enabled' => true, 'code' => 'SAMPLE-7K3Q', 'discountLabel' => $settings->label() ?? '₱500 off', 'usedCount' => 2, 'pointsEarned' => 100, 'pointsPending' => 50])->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'enabled' => $settings->isActive(),
            'code' => $settings->isActive() ? Referrals::codeFor($guest) : $guest->referral_code,
            'discountLabel' => $settings->label(),
            ...Referrals::stats($guest),
        ])->header('Cache-Control', 'no-store');
    }
}
