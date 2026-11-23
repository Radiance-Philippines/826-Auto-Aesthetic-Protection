<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Initials drawn locally as an SVG data URI. Filament's default provider loads ui-avatars.com, which sends every
 * signed-in person's name to a third party and fails without internet (the Flow CI sandbox).
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $name = Str::of(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->filter()
            ->map(fn (string $segment): string => mb_strtoupper(mb_substr($segment, 0, 1)))
            ->take(2)
            ->join('');

        $text = htmlspecialchars($name !== '' ? $name : '?', ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#0c0a09"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="system-ui,sans-serif" font-size="26" fill="#ffffff">'
            .$text.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
