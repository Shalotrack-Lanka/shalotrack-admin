<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\AdminProfileController;

use App\Http\Controllers\Admin\MasterPages\ProductController;
use App\Http\Controllers\Admin\MasterPages\FeatureController;
use App\Http\Controllers\Admin\MasterPages\PriceGroupController;
use App\Http\Controllers\Admin\MasterPages\PriceGroupDetailsController;
use App\Http\Controllers\Admin\MasterPages\ChangeProductCodeController;


use App\Http\Controllers\Admin\Supplier\AddSupplierController;
use App\Http\Controllers\Admin\Supplier\AddProductPoController;

use App\Http\Controllers\Admin\Dealer\AddDealerController;
use App\Http\Controllers\Admin\Dealer\ManageReplacementController;
use App\Http\Controllers\Admin\Dealer\DealerLedgerController;

use App\Http\Controllers\Admin\Complains_Enquiries\TroubleshootController;
use App\Http\Controllers\Admin\Complains_Enquiries\ViewComplainsController;
use App\Http\Controllers\Admin\Complains_Enquiries\FeedbackController;
use App\Http\Controllers\Admin\Complains_Enquiries\DeviceReplaceRequestController;

use App\Http\Controllers\Admin\Activations\ActivationReportController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboards
    |--------------------------------------------------------------------------
    */

    Route::view('/admin/dashboard', 'admin.dashboard')->name('admin.dashboard');
    Route::view('/dealer/dashboard', 'dealer.dashboard')->name('dealer.dashboard');
    Route::view('/finance/dashboard', 'finance.dashboard')->name('finance.dashboard');
    Route::view('/technician/dashboard', 'technician.dashboard')->name('technician.dashboard');

    Route::get('/dashboard', function () {
        return redirect()->route('admin.dashboard');
    });

    /*
    |--------------------------------------------------------------------------
    | Master Pages
    |--------------------------------------------------------------------------
    */

    Route::prefix('admin/master-pages')->group(function () {

        Route::get('/products',
            [ProductController::class, 'index'])
            ->name('admin.products');

        Route::get('/features',
            [FeatureController::class, 'index'])
            ->name('admin.features');

        Route::get('/price-groups',
            [PriceGroupController::class, 'index'])
            ->name('admin.price-groups');

        Route::get('/price-group-details',
            [PriceGroupDetailsController::class, 'index'])
            ->name('admin.price-group-details');

        Route::get('/change-product-code',
            [ChangeProductCodeController::class, 'index'])
            ->name('admin.change-product-code');

    });


    /*
|--------------------------------------------------------------------------
| Supplier
|--------------------------------------------------------------------------
*/

Route::prefix('admin/supplier')->group(function () {

        Route::get('/add-supplier',
        [AddSupplierController::class,'index'])
        ->name('admin.suppliers');

         Route::get('/add-product-po',
        [AddProductPoController::class,'index'])
        ->name('admin.add-product-po');

});


/*
|--------------------------------------------------------------------------
| Dealer
|--------------------------------------------------------------------------
*/

Route::prefix('admin/dealer')->group(function () {

    Route::get('/add-dealer', [AddDealerController::class, 'index'])
        ->name('admin.add-dealer');

    Route::get('/manage-replacement',[ManageReplacementController::class,'index'])
        ->name('admin.manage-replacement');

    Route::get('/dealer-ledger',[DealerLedgerController::class,'index'])
        ->name('admin.dealer-ledger');

});


/*
|--------------------------------------------------------------------------
| Complains & Enquiries
|--------------------------------------------------------------------------
*/

Route::prefix('admin/complains')->group(function () {

    Route::get('/troubleshoot',
        [TroubleshootController::class,'index'])
        ->name('admin.troubleshoot');

     Route::get('/view-complaints',
        [ViewComplainsController::class,'index'])
        ->name('admin.view-complains');

     Route::get('/feedback',
        [FeedbackController::class,'index'])
        ->name('admin.feedback');

    Route::get('/device-replace-request',
        [DeviceReplaceRequestController::class,'index'])
        ->name('admin.device-replace-request');

});

/*
|--------------------------------------------------------------------------
| Activations
|--------------------------------------------------------------------------
*/


Route::prefix('admin/activations')->middleware('auth')->group(function () {

    Route::get('/activation-report',
        [ActivationReportController::class,'index'])
        ->name('admin.activation-report');

});

    /*
    |--------------------------------------------------------------------------
    | Profiles
    |--------------------------------------------------------------------------
    */

    Route::get('/admin/profile',
        [AdminProfileController::class, 'show'])
        ->name('admin.profile');

    Route::get('/dealer/profile',
        [AdminProfileController::class, 'show'])
        ->name('dealer.profile');

    Route::get('/finance/profile',
        [AdminProfileController::class, 'show'])
        ->name('finance.profile');

    Route::get('/technician/profile',
        [AdminProfileController::class, 'show'])
        ->name('technician.profile');

    /*
    |--------------------------------------------------------------------------
    | Breeze Profile
    |--------------------------------------------------------------------------
    */

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');

});

require __DIR__.'/auth.php';