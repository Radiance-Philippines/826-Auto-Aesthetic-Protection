<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\Dashboard\Insights;
use App\Support\Staff\StaffViews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff app's Overview tab (the dashboard area): the admin dashboard's
 * own numbers (App\Support\Dashboard\Insights), scoped to the person's
 * branches, and only the cards their role may see on the website; the
 * others come back null.
 */
class StaffOverviewController extends StaffController
{
    /**
     * The app's period names, and the dashboard period each one is.
     *
     * @var array<string, string>
     */
    private const PERIODS = ['today' => 'today', '7d' => 'last_7', '30d' => 'last_30', 'month' => 'this_month'];

    private const TOP_REPS = 5;

    /**
     * GET /api/staff/overview?period=today|7d|30d|month&branch=
     */
    public function show(Request $request): JsonResponse
    {
        $this->requireArea('dashboard');

        $data = $request->validate(['period' => ['nullable', 'in:'.implode(',', array_keys(self::PERIODS))]]);
        $key = $data['period'] ?? '7d';
        $user = $this->user();
        $branch = $this->pickedBranch($request);
        $insights = Insights::for($user, ['period' => self::PERIODS[$key], 'branch' => $branch?->getKey()]);

        $money = StaffViews::seesMoney($user);
        $counts = $money || $user->canAccessArea('queue.update') ? $insights->sales() : null;
        $reviews = $user->canAccessArea('reviews') ? $insights->approvedReviews() : null;

        return response()->json([
            'period' => [
                'key' => $key,
                'label' => $insights->period->label(),
                'from' => $insights->period->from->toDateString(),
                'to' => $insights->period->to->toDateString(),
            ],
            'branch' => $branch ? ['id' => $branch->getKey(), 'name' => $branch->name] : null,
            'branches' => $insights->visibleBranches()->map(fn (Branch $each) => ['id' => $each->getKey(), 'name' => $each->name])->values()->all(),
            'sales' => $money ? $counts['sales'] : null,
            'collected' => $money ? $counts['collected'] : null,
            'averageTicket' => $money ? $counts['average'] : null,
            'unitsDone' => $counts['units'] ?? null,
            'turnaround' => $user->canAccessArea('queue.update') ? collect($insights->turnaround())->only(['toReady', 'toExit'])->all() : null,
            'branchesTable' => $money && $insights->seesManyBranches() ? $insights->branchTable() : null,
            'topReps' => $insights->seesStaff()
                ? collect($insights->staff())->take(self::TOP_REPS)->map(fn (array $row) => $money ? $row : collect($row)->except('sales')->all())->values()->all()
                : null,
            'backjobsOpen' => $user->canAccessArea('warranty') ? $insights->openBackjobs() : null,
            'reviewRating' => $reviews ? ['rating' => $reviews['rating'], 'count' => $reviews['reviews']] : null,
            'attention' => $insights->attention(),
        ])->header('Cache-Control', 'no-store');
    }
}
