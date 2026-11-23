<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Support\Panels;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as Responsable;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * After a password sign-in: the page the person was after, else the home
 * of their own panel (an old link into another panel is redirected on).
 */
class LoginResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = Filament::auth()->user();

        return redirect()->intended($user instanceof User ? Panels::homeUrl($user) : Filament::getUrl());
    }
}
