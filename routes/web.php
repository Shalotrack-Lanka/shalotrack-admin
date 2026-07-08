<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\AdminProfileController;

use App\Http\Controllers\Admin\MasterPages\AddDeviceController;
use App\Http\Controllers\Admin\MasterPages\AddSimController;
use App\Http\Controllers\Admin\MasterPages\CancelDeviceController;
use App\Http\Controllers\Admin\MasterPages\CancelSimController;
use App\Http\Controllers\Admin\MasterPages\FeatureController;
use App\Http\Controllers\Admin\MasterPages\PriceGroupController;
use App\Http\Controllers\Admin\MasterPages\PriceGroupDetailsController;
use App\Http\Controllers\Admin\MasterPages\ChangeProductCodeController;


use App\Http\Controllers\Admin\Supplier\AddSupplierController;
use App\Http\Controllers\Admin\Supplier\SupplierInvoiceController;

use App\Http\Controllers\Admin\Dealer\AddDealerController;
use App\Http\Controllers\Admin\Dealer\ManageReplacementController;
use App\Http\Controllers\Admin\Dealer\DealerLedgerController;

use App\Http\Controllers\Admin\Complains_Enquiries\TroubleshootController;
use App\Http\Controllers\Admin\Complains_Enquiries\ViewComplainsController;
use App\Http\Controllers\Admin\Complains_Enquiries\FeedbackController;
use App\Http\Controllers\Admin\Complains_Enquiries\DeviceReplaceRequestController;

use App\Http\Controllers\Admin\Activations\ActivationReportController;
use App\Http\Controllers\Admin\Activations\CustomerDocumentUploadController;

use App\Http\Controllers\Admin\Reports\StockInReportController;
use App\Http\Controllers\Admin\Reports\CreditInvoiceReportController;

use App\Http\Controllers\Admin\Stock\ManageStockController;
use App\Http\Controllers\Admin\Stock\CurrentStockController;
use App\Http\Controllers\Admin\Stock\SoldDeviceReportController;
use App\Http\Controllers\Admin\Stock\AddFaultyDeviceController;




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

        Route::get('/add-device',
            [AddDeviceController::class, 'index'])
            ->name('admin.add-device');

        Route::post('/add-device', [AddFaultyDeviceController::class, 'store'])->name('admin.device.store');

        Route::get('/add-sim',
            [AddSimController::class, 'index'])
            ->name('admin.add-sim');

        Route::post('/add-sim', [AddSimController::class, 'store'])->name('admin.stock.sim.store');

        Route::get('/cancel-device',
        [CancelDeviceController::class, 'index'])
         ->name('admin.cancel-device');

        Route::patch('/cancel-device/{device}',
        [CancelDeviceController::class, 'update'])
        ->name('admin.cancel-device.update');

        Route::get('/cancel-sim',
        [CancelSimController::class, 'index'])
        ->name('admin.cancel-sim');

        Route::patch('/cancel-sim/{sim}',
        [CancelSimController::class, 'update'])
        ->name('admin.cancel-sim.update');



 

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

         Route::get('/add-supplier-invoice',
        [SupplierInvoiceController::class,'index'])
        ->name('admin.supplier-invoice');

        Route::post('/add-supplier',
        [AddSupplierController::class, 'store'])
        ->name('admin.suppliers.store');

        Route::get('/{id}/edit',
        [AddSupplierController::class, 'edit'])
        ->name('admin.suppliers.edit');

        Route::put('/{id}',
        [AddSupplierController::class, 'update'])
        ->name('admin.suppliers.update');

        Route::post('/{id}/attach-product',
        [AddSupplierController::class, 'attachProduct'])
        ->name('admin.suppliers.attach-product');

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

    Route::post('/add-dealer', [AddDealerController::class, 'store'])
    ->name('admin.dealer.store');

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
    
    Route::get('/customer-document-upload',
        [CustomerDocumentUploadController::class,'index'])
        ->name('admin.customer-document-upload');
    

});


/*
|--------------------------------------------------------------------------
| Stock
|--------------------------------------------------------------------------
*/

Route::prefix('admin/stock')->middleware('auth')->group(function () {

    // 1. Manage Stock Routes (මෙහි name එක හරියටම 'admin.stock.store' ලෙස දී ඇත)
    Route::get('/manage-stock', [ManageStockController::class, 'index'])->name('admin.stock.manage');
    Route::post('/manage-stock', [ManageStockController::class, 'store'])->name('admin.stock.store');
    Route::put('/manage-stock/{stock}', [ManageStockController::class, 'update'])->name('admin.stock.update');
    Route::get('/manage-stock/{stock}/download', [ManageStockController::class, 'download'])->name('admin.stock.download');

    // 2. Current Stock Route
    Route::get('/current-stock', [CurrentStockController::class, 'index'])->name('admin.current-stock');

    // 3. Sold Device Report Route
   // Route::get('/sold-device-report', [SoldDeviceReportController::class, 'index'])->name('admin.sold-device-report');

    // 4. Add Faulty Device Route
   // Route::get('/add-faulty-device', [AddFaultyDeviceController::class, 'index'])->name('admin.add-faulty-device');
});

/*
|--------------------------------------------------------------------------
| Reports
|--------------------------------------------------------------------------
*/

Route::prefix('admin/report')->middleware(['auth'])->group(function () {

    Route::get('/stock-in-report',
        [StockInReportController::class,'index'])
        ->name('admin.stock-in-report');

    Route::get('/credit-invoice-report',
        [CreditInvoiceReportController::class,'index'])
        ->name('admin.credit-invoice-report');

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