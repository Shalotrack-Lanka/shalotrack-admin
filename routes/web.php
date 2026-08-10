<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\DashboardController;

use App\Http\Controllers\Admin\MasterPages\AddDeviceController;
use App\Http\Controllers\Admin\MasterPages\AddSimController;
use App\Http\Controllers\Admin\MasterPages\CancelDeviceController;
use App\Http\Controllers\Admin\MasterPages\CancelSimController;
use App\Http\Controllers\Admin\MasterPages\FeatureController;
use App\Http\Controllers\Admin\MasterPages\PriceGroupController;
use App\Http\Controllers\Admin\MasterPages\PriceGroupDetailsController;
use App\Http\Controllers\Admin\MasterPages\ChangeProductCodeController;

use App\Http\Controllers\Admin\Supplier\SupplierProfileController;
use App\Http\Controllers\Admin\Supplier\SupplierDashboardController;
use App\Http\Controllers\Admin\Supplier\SupplierManagementController;
use App\Http\Controllers\Admin\Supplier\SupplierInvoiceController;


use App\Http\Controllers\Admin\Dealer\DealerManagementController;
use App\Http\Controllers\Admin\Dealer\DealerProfileController;
use App\Http\Controllers\Admin\Dealer\DealerAccountController;
use App\Http\Controllers\Admin\Dealer\ManageReplacementController;
use App\Http\Controllers\Admin\Dealer\DealerLedgerController;
use App\Http\Controllers\Admin\Dealer\StockTransferController;
use App\Http\Controllers\Admin\Dealer\AssignedDevicesController;

// FIX: this was pointing at Admin\Dealer\DealerDashboardController, a class
// that doesn't exist — the real one lives in a separate top-level Dealer
// namespace (the Dealer Portal, not Admin's dealer-management area).
use App\Http\Controllers\Admin\Dealer\DealerDashboardController;

use App\Http\Controllers\Admin\Customer\CustomerSetupController;

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

use App\Http\Controllers\Admin\AdminPanel\AddDeviceTypeController;

use App\Http\Controllers\Admin\Vehicles\VehicleDetailsController;
use App\Http\Controllers\Admin\Vehicles\GpsTrackingController;




/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');

Route::middleware(['auth'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboards
    |--------------------------------------------------------------------------
    */

    Route::get('/admin/dashboard',
    [DashboardController::class,'index'])
    ->name('admin.dashboard');

    // FIX: was Route::view() with zero data behind it — replaced with the
    // real controller so the dashboard actually receives $dealer and everything
    // else it needs. This is now the ONLY place dealer.dashboard is registered.
    Route::get('/dealer/dashboard', [DealerDashboardController::class, 'index'])->name('dealer.dashboard');

    Route::view('/finance/dashboard', 'finance.dashboard')->name('finance.dashboard');
    Route::view('/technician/dashboard', 'technician.dashboard')->name('technician.dashboard');

    Route::get('/dashboard', function () {
        return redirect()->route('admin.dashboard');
    })->name('dashboard.redirect');

    /*
    |--------------------------------------------------------------------------
    | Master Pages
    |--------------------------------------------------------------------------
    */

    Route::prefix('admin/master-pages')->group(function () {

        Route::get('/add-device', [AddDeviceController::class, 'index'])->name('admin.add-device');
        Route::post('/add-device', [AddDeviceController::class, 'store'])->name('admin.device.store');
        Route::get('/add-device/list', [AddDeviceController::class, 'list'])->name('admin.device.list');

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

        Route::patch('/admin/stock/sim/{sim}/update-status',
         [AddSimController::class, 'updateStatus'])
         ->name('admin.stock.sim.update-status');

         //report generation
        Route::get('/cancel-device/not-activated/export',
        [CancelDeviceController::class, 'exportNotActivated'])
        ->name('admin.cancel-device.export-not-activated');
  
        Route::get('/cancel-device/activated/export', 
        [CancelDeviceController::class, 'exportActivated'])
        ->name('admin.cancel-device.export-activated');

        Route::get('/add-sim/not-activated/export', 
        [AddSimController::class, 'exportNotActivated'])
        ->name('admin.sim.export-not-activated');

        Route::get('/add-sim/activated/export', 
        [AddSimController::class, 'exportActivated'])
        ->name('admin.sim.export-activated');

    });




/*
|--------------------------------------------------------------------------
| Admin Panel (System Config)
|--------------------------------------------------------------------------
*/

    Route::prefix('admin/admin-panel')->group(function () {

        Route::get('/add-device-types',
            [AddDeviceTypeController::class, 'index'])
            ->name('admin.add-device-types');

        Route::post('/add-device-types',
           [AddDeviceTypeController::class, 'store'])
            ->name('admin.device-types.store');

        Route::post('/add-device-types/add-features', [AddDeviceTypeController::class, 'storeFeature'])->name('admin.features.store');

        });



    /*
|--------------------------------------------------------------------------
| Supplier
|--------------------------------------------------------------------------
*/

Route::prefix('admin/supplier')->group(function () {

        Route::get('/supplier-management',
        [SupplierManagementController::class,'index'])
        ->name('admin.suppliers');

         Route::get('/supplier-management-invoice',
        [SupplierInvoiceController::class,'index'])
        ->name('admin.supplier-invoice');

        Route::post('/supplier-management',
        [SupplierManagementController::class, 'store'])
        ->name('admin.suppliers.store');

        Route::get('/{id}/edit',
        [SupplierManagementController::class, 'edit'])
        ->name('admin.suppliers.edit');

        Route::put('/{id}',
        [SupplierManagementController::class, 'update'])
        ->name('admin.suppliers.update');

        Route::post('/{id}/attach-product',
        [SupplierManagementController::class, 'attachProduct'])
        ->name('admin.suppliers.attach-product');

        Route::delete('/{id}/detach-product/{productId}',
         [SupplierManagementController::class, 'detachProduct'])
        ->name('admin.suppliers.detach-product');

        Route::get('/dashboard', [SupplierDashboardController::class, 'index'])
        ->name('supplier.dashboard');

        //profile routes for supplier
        Route::get('/profile', [SupplierProfileController::class, 'edit'])
        ->name('supplier.profile');

        Route::put('/profile', [SupplierProfileController::class, 'update'])
        ->name('supplier.profile.update');

        Route::patch('/{id}/toggle-status', [SupplierManagementController::class, 'toggleStatus'])
       ->name('admin.suppliers.toggle-status');

       Route::post('/supplier-management-invoice',
      [SupplierInvoiceController::class, 'store'])
       ->name('admin.supplier-invoice.store');

       Route::get('/{id}/purchase-data',
      [SupplierInvoiceController::class, 'getSupplierData'])
      ->name('admin.suppliers.purchase-data');

      Route::get(
    '/invoice/{id}/download',
    [SupplierInvoiceController::class, 'download'])
    ->name('admin.supplier-invoice.download');

});

/*
|--------------------------------------------------------------------------
| Dealer
|--------------------------------------------------------------------------
*/

Route::prefix('admin/dealer')->group(function () {

    Route::get('/dealer-management', [DealerManagementController::class, 'index'])
   ->name('admin.dealer-management');

    Route::get('/stock-transfer', [StockTransferController::class, 'index'])
    ->name('admin.dealer.stock-transfer');

    Route::get('/manage-replacement',[ManageReplacementController::class,'index'])
        ->name('admin.manage-replacement');

    Route::get('/dealer-ledger',[DealerLedgerController::class,'index'])
        ->name('admin.dealer-ledger');

    Route::post('/dealer-management', [DealerManagementController::class, 'store'])
        ->name('admin.dealer.store');

    Route::get('/stock-transfer', [StockTransferController::class, 'index'])
       ->name('admin.dealer.stock_transfer');

    Route::post('/stock-transfer', [StockTransferController::class, 'store'])
       ->name('admin.dealer.stock_transfer.store');
    
    // stock transfer automation
    Route::get('/suppliers/{deviceType}', [StockTransferController::class, 'getSuppliers'])
        ->name('admin.dealer.suppliers');

    Route::get('/stock-info/{deviceType}/{supplier}',
    [StockTransferController::class, 'getStockInfo'])
    ->name('admin.dealer.stock.info');

    Route::get('/assigned-devices', [AssignedDevicesController::class, 'index'])
    ->name('admin.dealer.assigned-devices');


    Route::get('/profile', [DealerAccountController::class, 'edit'])->name('dealer.profile.edit');
    Route::put('/profile', [DealerAccountController::class, 'update'])->name('dealer.profile.update');
    Route::get('/{id}/profile', [DealerProfileController::class, 'show'])->name('admin.dealer.profile');
    Route::patch('/{id}/toggle-status', [DealerProfileController::class, 'toggleStatus'])->name('admin.dealer.toggle-status');

});

/*
|--------------------------------------------------------------------------
| Customer
|--------------------------------------------------------------------------
*/

Route::prefix('admin/customer')->middleware('auth')->group(function () {

    Route::get('/setup', [CustomerSetupController::class, 'index'])
        ->name('admin.customer-setup');

    Route::get('/setup/refresh', [CustomerSetupController::class, 'refresh'])
        ->name('admin.customer-setup.refresh');

    Route::patch('/setup/{customerId}/status', [CustomerSetupController::class, 'toggleStatus'])
        ->name('admin.customer-setup.toggle-status');

});

/*
    |--------------------------------------------------------------------------
    | Vehicles
    |--------------------------------------------------------------------------
    */

Route::prefix('admin/vehicles')->name('admin.vehicles.')->group(function () {

    Route::get('/details',
     [VehicleDetailsController::class, 'index'])
     ->name('details');

     Route::get('/gps-tracking',
     [GpsTrackingController::class, 'index'])
     ->name('gps');

     Route::get('/gps-tracking/export', 
     [GpsTrackingController::class, 'exportPdf'])
    ->name('gps.export');

    Route::get('/gps-tracking/resolve-address', [GpsTrackingController::class, 'resolveAddress'])
    ->name('gps.resolve-address');
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

    Route::get('/manage-stock', [ManageStockController::class, 'index'])->name('admin.stock.manage');
    Route::post('/manage-stock', [ManageStockController::class, 'store'])->name('admin.stock.store');
    Route::put('/manage-stock/bulk-update', [ManageStockController::class, 'bulkUpdate'])->name('admin.stock.bulk-update');

    // 2. Current Stock Route
    //Route::get('/current-stock', [CurrentStockController::class, 'index'])->name('admin.current-stock');

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

   // Route::get('/dealer/profile',
     //   [AdminProfileController::class, 'show'])
      //  ->name('dealer.profile');

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