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
use App\Http\Controllers\Admin\MasterPages\AddDeviceTypeController;
use App\Http\Controllers\Admin\MasterPages\StockTransferController;

use App\Http\Controllers\Admin\Supplier\SupplierProfileController;
use App\Http\Controllers\Admin\Supplier\SupplierDashboardController;
use App\Http\Controllers\Admin\Supplier\SupplierManagementController;
use App\Http\Controllers\Admin\Supplier\SupplierInvoiceController;


use App\Http\Controllers\Admin\Dealer\DealerManagementController;
use App\Http\Controllers\Admin\Dealer\DealerProfileController;
use App\Http\Controllers\Admin\Dealer\DealerAccountController;
use App\Http\Controllers\Admin\Dealer\ManageReplacementController;
use App\Http\Controllers\Admin\Dealer\DealerLedgerController;
use App\Http\Controllers\Admin\Dealer\AssignedDevicesController;
use App\Http\Controllers\Admin\Dealer\DealerComplaintController;

// FIX: this was pointing at Admin\Dealer\DealerDashboardController, a class
// that doesn't exist — the real one lives in a separate top-level Dealer
// namespace (the Dealer Portal, not Admin's dealer-management area).
use App\Http\Controllers\Admin\Dealer\DealerDashboardController;

use App\Http\Controllers\Admin\Customer\CustomerSetupController;
use App\Http\Controllers\Admin\Customer\CustomerDeviceManagementController;

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

use App\Http\Controllers\Admin\Vehicles\VehicleDetailsController;
use App\Http\Controllers\Admin\Vehicles\GpsTrackingController;
use App\Http\Controllers\Admin\Vehicles\DeviceCommandController;




/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

use Illuminate\Support\Facades\Auth;

Route::get('/', function () {

    if (Auth::check()) {

        $user = Auth::user();

        return match ($user->role) {
            'ADMIN'      => redirect()->route('admin.dashboard'),
            'DEALER'     => redirect()->route('dealer.dashboard'),
            'FINANCE'    => redirect()->route('finance.dashboard'),
            'TECHNICIAN' => redirect()->route('technician.dashboard'),
            'SUPPLIER'   => redirect()->route('supplier.dashboard'),
            default      => redirect()->route('login'),
        };
    }

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

        Route::get('/setup-device', [AddDeviceController::class, 'index'])->name('admin.setup-device');
        Route::post('/setup-device', [AddDeviceController::class, 'store'])->name('admin.device.store');
        Route::get('/setup-device/list', [AddDeviceController::class, 'list'])->name('admin.device.list');

        //add new device type
        Route::get('/add-device-type', [AddDeviceTypeController::class, 'index'])->name('admin.add-device-type');
        Route::post('/add-device-type',[AddDeviceTypeController::class, 'store'])->name('admin.device-types.store');
        Route::post('/add-device-type/add-features', [AddDeviceTypeController::class, 'storeFeature'])->name('admin.features.store');
        Route::get('/device-types/import-template', [AddDeviceTypeController::class, 'downloadImportTemplate'])->name('admin.device-types.import-template');
        Route::post('/device-types/import', [AddDeviceTypeController::class, 'importDeviceTypes'])->name('admin.device-types.import');

        //stock transfer
        Route::get('/stock-transfer', [StockTransferController::class, 'index'])->name('admin.stock_transfer');
        Route::post('/stock-transfer', [StockTransferController::class, 'store'])->name('admin.stock_transfer.store');

        Route::put('/stock-transfer/{ledger}', [StockTransferController::class, 'update'])->name('admin.stock_transfer.update');
        Route::delete('/stock-transfer/{ledger}', [StockTransferController::class, 'destroy'])->name('admin.stock_transfer.destroy');
        Route::get('/stock-transfer/{ledger}/edit-data', [StockTransferController::class, 'editData'])->name('admin.stock_transfer.edit-data');

        // Report generation for Stock Transfer
         Route::get('/stock-transfer/report', [StockTransferController::class, 'generateReport'])->name('admin.stock_transfer.report');

        // stock transfer automation
         Route::get('/device-categories/{category}/sim-numbers', [StockTransferController::class, 'getSimNumbers'])
        ->where('category', '.*')
        ->name('admin.stock_transfer.sim-numbers');

        //excel
        Route::get('/devices/import-template', [AddDeviceController::class, 'downloadImportTemplate'])
       ->name('admin.device.import-template');

        Route::post('/devices/import', [AddDeviceController::class, 'importDevices'])
        ->name('admin.device.import');

        Route::get('/add-sim',[AddSimController::class, 'index'])->name('admin.add-sim');

        Route::get('/sim/import-template', [AddSimController::class, 'downloadImportTemplate'])->name('admin.stock.sim.import-template');
        Route::post('/sim/import', [AddSimController::class, 'importSims'])->name('admin.stock.sim.import');

        Route::post('/add-sim', [AddSimController::class, 'store'])->name('admin.stock.sim.store');

        Route::patch('/admin/stock/sim/{sim}/update-status',
         [AddSimController::class, 'updateStatus'])
         ->name('admin.stock.sim.update-status');

         //report generation
        Route::get('/add-sim/not-activated/export', [AddSimController::class, 'exportNotActivated'])->name('admin.sim.export-not-activated');
        Route::get('/add-sim/activated/export', [AddSimController::class, 'exportActivated'])->name('admin.sim.export-activated');

    });


/*
    |--------------------------------------------------------------------------
    | Cancel Requests
    |--------------------------------------------------------------------------
    */
    Route::prefix('admin/cancel-requests')->group(function () {

        // Cancel Device
        Route::get('/cancel-device', [CancelDeviceController::class, 'index'])->name('admin.cancel-device');
        Route::patch('/cancel-device/{device}', [CancelDeviceController::class, 'update'])->name('admin.cancel-device.update');
        Route::get('/cancel-device/not-activated/export', [CancelDeviceController::class, 'exportNotActivated'])->name('admin.cancel-device.export-not-activated');
        Route::get('/cancel-device/activated/export', [CancelDeviceController::class, 'exportActivated'])->name('admin.cancel-device.export-activated');

        // Cancel Sim
        Route::get('/cancel-sim', [CancelSimController::class, 'index'])->name('admin.cancel-sim');
        Route::patch('/cancel-sim/{sim}', [CancelSimController::class, 'update'])->name('admin.cancel-sim.update');

    });


    /*
|--------------------------------------------------------------------------
| Customer
|--------------------------------------------------------------------------
*/

Route::prefix('admin/customer')->middleware('auth')->group(function () {

    // Customer Setup
    Route::get('/setup', [CustomerSetupController::class, 'index'])->name('admin.customer-setup');
    Route::get('/setup/refresh', [CustomerSetupController::class, 'refresh'])->name('admin.customer-setup.refresh');
    Route::patch('/setup/{customerId}/status', [CustomerSetupController::class, 'toggleStatus'])->name('admin.customer-setup.toggle-status');

    // Customer Device Management
    Route::get('/device-management', [CustomerDeviceManagementController::class, 'index'])->name('admin.customer-device-management');
    Route::post('/device-management/{vehicleId}/activate', [CustomerDeviceManagementController::class, 'activate'])->name('admin.customer-device-management.activate');
    Route::patch('/device-management/{activatedDevice}', [CustomerDeviceManagementController::class, 'update'])->name('admin.customer-device-management.update');
    Route::post('/device-management/{expiredDevice}/reactivate', [CustomerDeviceManagementController::class, 'reactivate'])->name('admin.customer-device-management.reactivate');

    // Report generation for Customer Setup and Customer Device Management
    Route::get('/setup/report', [CustomerSetupController::class, 'generateReport'])->name('admin.customer-setup.report');
    Route::get('customer-device-management/report', [CustomerDeviceManagementController::class, 'generateReport'])->name('admin.customer-device-management.report');

});



/*
    |--------------------------------------------------------------------------
    | Vehicles
    |--------------------------------------------------------------------------
    */

Route::prefix('admin/vehicles')->name('admin.vehicles.')->group(function () {

    // Vehicle Details
    Route::get('/details',[VehicleDetailsController::class, 'index'])->name('details');

    // GPS Tracking
    Route::get('/gps-tracking',[GpsTrackingController::class, 'index'])->name('gps');
    Route::get('/gps-tracking/resolve-address', [GpsTrackingController::class, 'resolveAddress'])->name('gps.resolve-address');
    Route::get('/gps/export', [GpsTrackingController::class, 'exportPdf'])->name('gps.export');

    // Device Commands
    Route::get('/device-commands', [DeviceCommandController::class, 'index'])->name('device-commands');
    Route::post('/device-commands/send', [DeviceCommandController::class, 'sendCommand'])->name('device-commands.send');
    Route::get('/device-commands/status/{imei}', [DeviceCommandController::class, 'deviceStatus'])->name('device-commands.status');
    Route::get('/device-commands/history/{vehicleId}', [DeviceCommandController::class, 'commandHistory'])->name('device-commands.history');

});


/*
|--------------------------------------------------------------------------
| Dealer
|--------------------------------------------------------------------------
*/

Route::prefix('admin/dealer')->group(function () {

    // Dealer Management
    Route::get('/dealer-management', [DealerManagementController::class, 'index'])->name('admin.dealer-management');
    Route::post('/dealer-management', [DealerManagementController::class, 'store'])->name('admin.dealer.store');
    Route::get('/customer-ads', [DealerManagementController::class, 'dealerCustomers'])->name('admin.dealers.customer-ads');

    // manage replacement
    Route::get('/manage-replacement',[ManageReplacementController::class,'index'])->name('admin.manage-replacement');

    // dealer ledger
    Route::get('/dealer-ledger',[DealerLedgerController::class,'index'])->name('admin.dealer-ledger');

    Route::get('/assigned-devices', [AssignedDevicesController::class, 'index'])->name('admin.dealer.assigned-devices');

    // Dealer Dashboard
    Route::delete('/unassign-device/{id}', [DealerDashboardController::class, 'unassignDevice'])->name('dealer.unassign-device');
    Route::post('/assign-device', [DealerDashboardController::class, 'assignDeviceToCustomer'])->name('dealer.assign-device');
    Route::post('/customer-ad', [DealerDashboardController::class, 'storeDealerCustomerAd'])->name('dealer.customer-ad.store');
    Route::get('customers', [DealerDashboardController::class, 'customerList'])->name('dealer.customers.index');
    Route::post('/dealer/reassign-device/{id}', [DealerDashboardController::class, 'reassignDevice'])->name('dealer.reassign-device');
    // Broken එකක් ලෙස සලකුණු කිරීම (Remove බොත්තම සඳහා) - අදාළ function එක 'markDeviceBroken' වේ.
    Route::delete('/dealer/remove-broken-device/{shdevice_id}', [DealerDashboardController::class, 'markDeviceBroken'])->name('dealer.remove-broken-device');
   // නැවත Pending වෙත ගෙන යාම (To Pending බොත්තම සඳහා) - අදාළ function එක 'moveToPending' වේ.
    Route::post('/dealer/move-to-pending/{shdevice_id}', [DealerDashboardController::class, 'moveToPending'])->name('dealer.move-to-pending');

    Route::get('/customer-ad/{id}/edit', [DealerDashboardController::class, 'editCustomerAd'])->name('dealer.customer-ad.edit');
    Route::delete('/customer-ad/{id}', [DealerDashboardController::class, 'destroyCustomerAd'])->name('dealer.customer-ad.destroy');
    Route::post('/dealer/customers/assign-new-device', [DealerDashboardController::class, 'assignNewDeviceFromList'])->name('dealer.customers.assign_new_device_from_list');
    // pdf report generation
    Route::get('/dealer-customers/report', [DealerDashboardController::class, 'generateReport'])->name('admin.dealer-customers.report');


    // Dealer Profile
    Route::get('/profile', [DealerAccountController::class, 'edit'])->name('dealer.profile.edit');
    Route::put('/profile', [DealerAccountController::class, 'update'])->name('dealer.profile.update');

    // Admin-facing: dedicated full profile page for a specific dealer
    Route::get('/{id}/profile', [DealerProfileController::class, 'show'])->name('admin.dealer.profile');
    Route::put('/{id}/profile', [DealerProfileController::class, 'update'])->name('admin.dealer.profile.update');
    Route::patch('/{id}/toggle-status', [DealerProfileController::class, 'toggleStatus'])->name('admin.dealer.toggle-status');

    // Device Commands
    Route::get('/device-commands', [DeviceCommandController::class, 'dealerIndex'])->name('device-commands');

});

Route::prefix('admin/complaints')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\Complaints\AdminComplaintController::class, 'index'])->name('admin.complaints.index');
    Route::post('/{complaintId}/reply', [\App\Http\Controllers\Admin\Complaints\AdminComplaintController::class, 'reply'])->name('admin.complaints.reply');
    Route::post('/{complaintId}/resolve', [\App\Http\Controllers\Admin\Complaints\AdminComplaintController::class, 'resolve'])->name('admin.complaints.resolve');
    Route::post('/{complaintId}/close', [\App\Http\Controllers\Admin\Complaints\AdminComplaintController::class, 'close'])->name('admin.complaints.close');

    // Polls for new complaints escalated from dealers (complaint count by ID set).
    Route::get('/check-new', [\App\Http\Controllers\Admin\Complaints\AdminComplaintController::class, 'checkNew'])->name('admin.complaints.check-new');

    // Polls for new replies from dealers/customers on open complaints.
    Route::get('/check-new-replies', [\App\Http\Controllers\Admin\Complaints\AdminComplaintController::class, 'checkNewReplies'])->name('admin.complaints.check-new-replies');

    Route::get('/resolved', [App\Http\Controllers\Admin\Complaints\AdminComplaintController::class, 'resolved'])->name('admin.complaints.resolved');
});



    /*
|--------------------------------------------------------------------------
| Supplier
|--------------------------------------------------------------------------
*/

Route::prefix('admin/supplier')->group(function () {

    // Supplier Dashboard
    Route::get('/dashboard', [SupplierDashboardController::class, 'index'])->name('supplier.dashboard');

    // Supplier Management
    Route::get('/supplier-management',[SupplierManagementController::class,'index'])->name('admin.suppliers');
    Route::post('/supplier-management',[SupplierManagementController::class, 'store'])->name('admin.suppliers.store');
    Route::get('/{id}/edit',[SupplierManagementController::class, 'edit'])->name('admin.suppliers.edit');
    Route::put('/{id}',[SupplierManagementController::class, 'update'])->name('admin.suppliers.update');
    Route::post('/{id}/attach-product',[SupplierManagementController::class, 'attachProduct'])->name('admin.suppliers.attach-product');
    Route::delete('/{id}/detach-product/{productId}',[SupplierManagementController::class, 'detachProduct'])->name('admin.suppliers.detach-product');
    Route::patch('/{id}/toggle-status', [SupplierManagementController::class, 'toggleStatus'])->name('admin.suppliers.toggle-status');

    //profile routes for supplier
    Route::get('/profile', [SupplierProfileController::class, 'edit'])->name('supplier.profile');
    Route::put('/profile', [SupplierProfileController::class, 'update'])->name('supplier.profile.update');
    // Admin-facing: dedicated full profile page for a specific supplier.
    Route::get('/{id}/profile', [SupplierProfileController::class, 'showForAdmin'])->name('admin.supplier.profile.show');

    // Supplier Invoice Management
    Route::get('/supplier-management-invoice',[SupplierInvoiceController::class,'index'])->name('admin.supplier-invoice');
    Route::post('/supplier-management-invoice',[SupplierInvoiceController::class, 'store'])->name('admin.supplier-invoice.store');
    Route::get('/{id}/purchase-data',[SupplierInvoiceController::class, 'getSupplierData'])->name('admin.suppliers.purchase-data');
    Route::get('/invoice/{id}/download',[SupplierInvoiceController::class, 'download'])->name('admin.supplier-invoice.download');

});


/*
|--------------------------------------------------------------------------
| Stock
|--------------------------------------------------------------------------
*/

Route::prefix('admin/stock')->middleware('auth')->group(function () {

    Route::get('/manage-stock', [ManageStockController::class, 'index'])->name('admin.stock.manage');
    Route::post('/manage-stock', [ManageStockController::class, 'store'])->name('admin.stock.store');
    Route::patch('/ledger/{ledger}', [ManageStockController::class, 'updateLedgerDescription'])->name('admin.stock.ledger.update');
    Route::delete('/ledger/{ledger}', [ManageStockController::class, 'destroyLedger'])->name('admin.stock.ledger.destroy');

    Route::get('/report', [ManageStockController::class, 'generateReport'])->name('admin.stock.report');

    // Stock
    Route::get('/stock/import-template', [ManageStockController::class, 'downloadImportTemplate'])->name('admin.stock.import-template');
    Route::post('/stock/import', [ManageStockController::class, 'importStock'])->name('admin.stock.import');

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

     // Legacy dead page — superseded by /admin/complaints (AdminComplaintController),
     // which reads real data from the API. This route/controller/view are pure
     // static mockup with a hardcoded "no complaints" string and were never wired
     // to any backend. Redirecting rather than deleting so any old bookmark or
     // stale link still lands somewhere real instead of silently showing fake data.
     Route::get('/view-complaints', function () {
         return redirect()->route('admin.complaints.index', [], 301);
     })->name('admin.view-complains');

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



// Dealer Routes Group
Route::middleware(['auth'])->prefix('dealer')->name('dealer.')->group(function () {
    Route::get('/device-commands', [DeviceCommandController::class, 'dealerIndex'])->name('device-commands');
    Route::post('/device-commands/send', [DeviceCommandController::class, 'sendCommand'])->name('device-commands.send');
    Route::get('/device-commands/status/{imei}', [DeviceCommandController::class, 'deviceStatus'])->name('device-commands.status');
    Route::get('/device-commands/history/{vehicleId}', [DeviceCommandController::class, 'commandHistory'])->name('device-commands.history');

    Route::get('/gps-tracking', [GpsTrackingController::class, 'dealerIndex'])->name('gps-tracking');

    Route::get('/complaints', [DealerComplaintController::class, 'index'])->name('complaints');
    Route::post('/complaints/{complaintId}/reply', [DealerComplaintController::class, 'reply'])->name('complaints.reply');
    Route::post('/complaints/{complaintId}/escalate', [DealerComplaintController::class, 'escalate'])->name('complaints.escalate');
    Route::post('/complaints/{complaintId}/resolve', [DealerComplaintController::class, 'resolve'])->name('complaints.resolve');
    Route::post('/complaints/{complaintId}/close', [DealerComplaintController::class, 'close'])->name('complaints.close');

    // Polls for new complaint threads assigned to this dealer.
    Route::get('/check-new-complaints', [DealerComplaintController::class, 'checkNewComplaints'])->name('check-new-complaints');

// Dealer සඳහා අලුත් complaints චෙක් කරන Route එක
Route::get('/check-new-complaints', [\App\Http\Controllers\Admin\Dealer\DealerComplaintController::class, 'checkNewComplaints'])->name('dealer.check-new-complaints');

  Route::get('/complaints/resolved', [\App\Http\Controllers\Admin\Dealer\DealerComplaintController::class, 'resolved'])->name('complaints.resolved');

    // Polls for new admin replies on this dealer's open complaints.
    Route::get('/check-new-replies', [DealerComplaintController::class, 'checkNewReplies'])->name('check-new-replies');

    Route::get('/complaints/resolved', [DealerComplaintController::class, 'resolved'])->name('complaints.resolved');
});


require __DIR__.'/auth.php';