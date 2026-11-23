<?php

/*
 | The app launcher (the grid in the top bar of the web panels, and in the
 | four team apps). `panels` are the working apps, one per panel: which
 | ones a person gets is App\Support\AppLauncher (their role's panels,
 | App\Support\Panels). "coming" are placeholders for the apps the shop
 | plans, shown disabled with a "Soon" tag to the super admin so the owner
 | can see the whole family. When one is built, move it to a panel or a real
 | link and remove it from here. Whether apps follow departments or panels
 | is still to be decided: AppLauncher::tiles is the one place to change.
 |
 | `icon` is the web's Heroicon case, `glyph` the neutral name the team apps
 | map to their own icon set.
 */
return [
    'panels' => [
        'owner' => ['icon' => 'OutlinedBuildingStorefront', 'glyph' => 'store'],
        'sales' => ['icon' => 'OutlinedBanknotes', 'glyph' => 'banknote'],
        'operations' => ['icon' => 'OutlinedViewColumns', 'glyph' => 'columns'],
        'system' => ['icon' => 'OutlinedCog6Tooth', 'glyph' => 'settings'],
    ],
    'coming' => [
        ['key' => 'hr', 'label' => 'HR', 'icon' => 'OutlinedUserGroup', 'glyph' => 'users'],
        ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'OutlinedWallet', 'glyph' => 'wallet'],
        ['key' => 'accounting', 'label' => 'Accounting', 'icon' => 'OutlinedCalculator', 'glyph' => 'calculator'],
        ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'OutlinedArchiveBox', 'glyph' => 'archive'],
    ],
];
