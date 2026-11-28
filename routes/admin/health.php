<?php

use App\Http\Controllers\ConsoleHealthController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::get('health', ConsoleHealthController::class)->middleware(ConsoleScreen::class.':health')->name('health');
