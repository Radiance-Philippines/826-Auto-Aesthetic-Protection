<?php

namespace App\Support\Dashboard;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The daily sales table for the chosen period and branch, as a CSV: only for
 * accounts with the sales area.
 */
final class SalesExport
{
    public static function download(Insights $insights): StreamedResponse
    {
        abort_unless($insights->can('bookings.sales'), 403);

        $rows = $insights->daily();
        $branch = $insights->branchId ? Str::slug($insights->branches()->first()?->name ?? '') : null;
        $name = '826-daily-sales-'.$insights->period->from->toDateString().'-to-'.$insights->period->to->toDateString().($branch ? "-{$branch}" : '').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Units done', 'Sales', 'Collected', 'Discounts'], escape: '');

            foreach ($rows as $row) {
                fputcsv($out, [$row['date'], $row['units'], $row['sales'], $row['collected'], $row['discounts']], escape: '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
