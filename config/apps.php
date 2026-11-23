<?php

/*
 | The app launcher (the grid in the top bar). The four panels are the
 | working apps; "coming" are placeholders for the apps the shop plans, shown
 | disabled with a "Soon" tag so the owner can see the whole family. When one
 | is built, move it to a panel or a real link and remove it from here.
 | Whether apps follow departments or panels is still to be decided.
 */
return [
    'coming' => [
        ['key' => 'hr', 'label' => 'HR', 'icon' => 'OutlinedUserGroup'],
        ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'OutlinedWallet'],
        ['key' => 'accounting', 'label' => 'Accounting', 'icon' => 'OutlinedCalculator'],
        ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'OutlinedArchiveBox'],
    ],
];
