<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {

    Route::view('/admin/dashboard', 'admin.dashboard')->name('admin.dashboard');
    Route::view('/dealer/dashboard','dealer.dashboard')->name('dealer.dashboard');
    Route::view('/finance/dashboard','finance.dashboard')->name('finance.dashboard');
    Route::view('/technician/dashboard','technician.dashboard')->name('technician.dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');

  // ADMIN PROFILE
    Route::get('/admin/profile', [AdminProfileController::class, 'show'])->name('admin.profile');

  // DEALER PROFILE
    Route::get('/dealer/profile', [AdminProfileController::class, 'show'])->name('dealer.profile');

  // FINANCE PROFILE
    Route::get('/finance/profile', [AdminProfileController::class, 'show'])->name('finance.profile');

  // TECHNICIAN PROFILE
    Route::get('/technician/profile', [AdminProfileController::class, 'show'])->name('technician.profile');

    Route::get('/dashboard', function () {
        return redirect('/admin/dashboard');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');
});



require __DIR__.'/auth.php';

