<?php

/*
| The team apps: one Android/iOS app per admin panel (owner, sales,
| operations, system), each with its own branding. More (HRM, payroll) are
| added here: a key, its label, the panel the account must be allowed to
| open (App\Support\Panels), and its tabs in order. A tab is shown only
| when the account has its area (App\Support\Staff\StaffViews::tabs); `me`
| is always last. The key is what the app sends as `X-Team-App` and what
| app_releases.app and user_tokens.app hold. An app that sends no header is
| the legacy 826 Team app (0.1.x): all tabs, no panel check.
*/
return [
    'owner' => ['label' => '826 Owner', 'panel' => 'owner', 'tabs' => ['overview', 'clients', 'inbox']],
    'sales' => ['label' => '826 Sales', 'panel' => 'sales', 'tabs' => ['today', 'clients', 'inbox']],
    'operations' => ['label' => '826 Operations', 'panel' => 'operations', 'tabs' => ['queue', 'inbox', 'clients']],
    'system' => ['label' => '826 System', 'panel' => 'system', 'tabs' => ['system']],
];
