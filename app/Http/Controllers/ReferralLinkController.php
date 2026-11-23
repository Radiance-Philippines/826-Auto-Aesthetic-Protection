<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * GET /r/{code}: the link a customer shares. It opens the booking page with
 * the code in the referral box; the code is only checked when the booking is
 * sent (Referrals::validate), never here.
 */
class ReferralLinkController extends Controller
{
    public function __invoke(string $code): RedirectResponse
    {
        $valid = preg_match('/^[A-Za-z0-9-]{3,40}$/', $code) === 1;

        return redirect($valid ? url('/book').'?'.http_build_query(['ref' => strtoupper($code)]) : url('/book'));
    }
}
