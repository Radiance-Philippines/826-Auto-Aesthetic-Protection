<?php

namespace App\Support;

use App\Providers\Filament\PanelPages;

/**
 * The React admin's address for a Filament admin link (a bell notification's
 * stored URL, a resource's own getUrl()): same panel, same page, with the
 * list tab under the name the new lists use. Anything else is left alone.
 */
final class ConsoleUrl
{
    public static function from(?string $url): ?string
    {
        // Only web addresses ever become links
        if ($url === null || ! preg_match('~^(https?://|/(?!/))~i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        $panels = implode('|', PanelPages::panels());

        if (! preg_match("~/({$panels})(/.*)?$~", (string) ($parts['path'] ?? ''), $match)) {
            return $url;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        if (isset($query['activeTab'])) {
            $query = ['tab' => $query['activeTab']] + array_diff_key($query, ['activeTab' => null]);
        }

        return url('console/'.$match[1].rtrim($match[2] ?? '', '/')).($query === [] ? '' : '?'.http_build_query($query));
    }
}
