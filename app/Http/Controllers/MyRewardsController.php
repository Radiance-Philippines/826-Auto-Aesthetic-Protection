<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsBookingInput;
use App\Models\ReferralRedemption;
use App\Models\ReferralSetting;
use App\Models\RewardRedemption;
use App\Support\Referrals;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/my-rewards: the signed-in customer's points, the friends who
 * used their code (first name and last initial only) and the points ledger.
 */
class MyRewardsController extends Controller
{
    use ReadsBookingInput;

    public function show(): JsonResponse
    {
        $guest = $this->guest();
        abort_unless($guest, 401);

        $settings = ReferralSetting::current();

        $redemptions = $guest->referralRedemptions()->with('usedBy')->latest('id')->get();
        $spent = $guest->rewardRedemptions()->with('booking')->get();

        $earnedLines = $redemptions
            ->filter(fn (ReferralRedemption $redemption) => $redemption->voided_at === null && $redemption->points_awarded > 0)
            ->map(fn (ReferralRedemption $redemption) => [
                'at' => $redemption->awarded_at ?? $redemption->created_at,
                'kind' => 'earned',
                'points' => (int) $redemption->points_awarded,
                'note' => ($redemption->usedBy?->shortName() ?? 'A friend').' used your code',
            ]);

        $spentLines = $spent->map(fn (RewardRedemption $redemption) => [
            'at' => $redemption->created_at,
            'kind' => $redemption->booking_id ? 'spent' : 'adjusted',
            'points' => -$redemption->points,
            'note' => trim(($redemption->booking ? "Redeemed on {$redemption->booking->reference}" : 'Adjusted by the shop').($redemption->note ? ": {$redemption->note}" : '')),
        ]);

        return response()->json([
            'enabled' => $settings->isActive(),
            'code' => $settings->isActive() ? Referrals::codeFor($guest) : $guest->referral_code,
            'discountLabel' => $settings->label(),
            'pointsBalance' => $guest->pointsBalance(),
            'pointsEarned' => (int) $redemptions->whereNull('voided_at')->sum('points_awarded'),
            'pointsSpent' => (int) $spent->sum('points'),
            'pesoPerPoint' => $settings->peso_per_point ?: null,
            'friends' => $redemptions->map(fn (ReferralRedemption $redemption) => [
                'name' => $redemption->usedBy?->shortName() ?? 'A friend',
                'usedOn' => $redemption->created_at->toDateString(),
                'status' => $redemption->status(),
                'points' => (int) $redemption->points_awarded,
            ])->values(),
            'ledger' => $earnedLines->concat($spentLines)
                ->sortByDesc(fn (array $line) => $line['at']->getTimestamp())
                ->map(fn (array $line) => ['date' => $line['at']->toDateString(), 'kind' => $line['kind'], 'points' => $line['points'], 'note' => $line['note']])
                ->values(),
        ])->header('Cache-Control', 'no-store');
    }
}
