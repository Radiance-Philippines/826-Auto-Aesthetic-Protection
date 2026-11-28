<?php

namespace App\Support;

use App\Models\Branch;

/**
 * What the admin's branch form and list work out, for both admins: the weekly
 * hours as a form edits them, what to say after a save, and the list's labels.
 */
final class BranchForm
{
    /**
     * Weekly hours as the form edits them (an "open today" switch per day).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillHours(array $data): array
    {
        $saved = is_array($data['weekly_hours'] ?? null) ? $data['weekly_hours'] : [];
        $hours = [];

        foreach (array_keys(Branch::WEEKDAYS) as $day) {
            $row = $saved[$day] ?? $saved[(string) $day] ?? null;
            $hours[$day] = [
                'open_today' => is_array($row) && filled($row['open'] ?? null),
                'open' => $row['open'] ?? null,
                'close' => $row['close'] ?? null,
            ];
        }

        return [...$data, 'weekly_hours' => $hours];
    }

    /**
     * Back to the stored shape: "HH:MM" times, null for closed days.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function saveHours(array $data): array
    {
        $form = is_array($data['weekly_hours'] ?? null) ? $data['weekly_hours'] : [];
        $hours = [];

        foreach (array_keys(Branch::WEEKDAYS) as $day) {
            $row = $form[$day] ?? $form[(string) $day] ?? [];
            $hours[(string) $day] = ($row['open_today'] ?? false) && Branch::clock($row['open'] ?? null) && Branch::clock($row['close'] ?? null)
                ? [
                    'open' => Branch::clock($row['open']),
                    'close' => Branch::clock($row['close']),
                ]
                : null;
        }

        $data['weekly_hours'] = $hours;

        return $data;
    }

    /**
     * What to tell the person after saving a branch: why it still takes no
     * online bookings, and upcoming drop-offs on a day that is now closed.
     *
     * @return list<array{title: string, body: string}>
     */
    public static function warnings(Branch $branch): array
    {
        $warnings = [];

        if ($branch->status === 'open' && $branch->missing() !== []) {
            $warnings[] = ['title' => 'Saved, but not taking online bookings yet', 'body' => implode(' ', $branch->missing())];
        }

        $outside = $branch->bookings()
            ->countingTowardCap()
            ->where('drop_off_on', '>=', today()->toDateString())
            ->get()
            ->filter(fn ($booking) => ! $branch->opensOn($booking->drop_off_on))
            ->count();

        if ($outside > 0) {
            $warnings[] = [
                'title' => "{$outside} upcoming ".($outside === 1 ? 'booking drops' : 'bookings drop').' off on a day that’s now closed',
                'body' => 'They keep their times. Check the queue and move them if needed.',
            ];
        }

        return $warnings;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'Open',
            'coming_soon' => 'Coming soon',
            default => 'Draft',
        };
    }

    /**
     * The list's "Online booking" column.
     */
    public static function onlineLabel(Branch $branch): string
    {
        return $branch->isOnsiteBookable() ? 'Taking bookings' : (count($branch->missing()) === 0 ? 'Not open' : count($branch->missing()).' to fill in');
    }
}
