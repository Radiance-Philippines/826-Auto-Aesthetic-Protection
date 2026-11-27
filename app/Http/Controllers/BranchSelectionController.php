<?php

namespace App\Http\Controllers;

use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The admin's branch selector: remembers the choice and reloads the page.
 */
class BranchSelectionController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['branch' => ['present', 'nullable', 'integer']]);

        CurrentBranch::choose($request->user(), $data['branch'] === null ? null : (int) $data['branch']);

        return back();
    }
}
