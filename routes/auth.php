<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    // Public self-registration is intentionally NOT routed: staff accounts
    // (Admins table) are created by an administrator, and the stock Breeze
    // register endpoint wrote to a separate `users` table this portal's login
    // never reads.

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // "Forgot password" without email: the person asks, an administrator sets a temporary password.
    Route::get('forgot-password', [PasswordResetRequestController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetRequestController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('password.request.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});