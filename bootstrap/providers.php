<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\OperationsPanelProvider;
use App\Providers\Filament\OwnerPanelProvider;
use App\Providers\Filament\SalesPanelProvider;

return [
    AppServiceProvider::class,
    OwnerPanelProvider::class,
    SalesPanelProvider::class,
    OperationsPanelProvider::class,
    AdminPanelProvider::class,
];
