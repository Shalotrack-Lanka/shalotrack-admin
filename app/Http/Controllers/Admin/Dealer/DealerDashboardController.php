<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Models\DealerCustomerAd;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\SetupShalotrackDevice;
use App\Http\Requests\DealerStoreCustomerAdRequest; 

class DealerDashboardController extends Controller
{
    public function index()
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Logged-in User
        |--------------------------------------------------------------------------
        */
        $user = auth()->user();

        /*
        |--------------------------------------------------------------------------
        | 2. Find Dealer Linked With This Login
        |--------------------------------------------------------------------------
        */
        $dealer = $user->dealer;

        /*
        |--------------------------------------------------------------------------
        | 3. If Dealer Account Is Not Linked
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
       
        |
        */
        if (!$dealer) {

            return view('dealer.dashboard', [
                'dealer'                  => null,
                'allocatedDevices'        => collect(),
                'allocatedDeviceCount'    => 0,
                'readyForActivationCount' => 0,
                'transfers'               => collect(),
                'totalStockReceived'      => 0,
                'latestStockReceived'     => 0,
                'recentActivity'          => collect(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 4. My Allocated Physical Devices
        |--------------------------------------------------------------------------
        |
        | setup_shalotrack_devices table එකේ
        | dealer_id = logged-in dealer id
        |
        |
        */
        $allocatedDevices = SetupShalotrackDevice::with('deviceType')
            ->where('dealer_id', $dealer->id)
            ->orderByDesc('shdevice_id')
            ->get();


        /*
        |--------------------------------------------------------------------------
        | 5. Allocated Device Count
        |--------------------------------------------------------------------------
        */
        $allocatedDeviceCount = $allocatedDevices->count();


        /*
        |--------------------------------------------------------------------------
        | 6. Ready For Activation
        |--------------------------------------------------------------------------
        |
        | status = Not Activated
        |
        */
        $readyForActivationCount = $allocatedDevices
            ->filter(function ($device) {

                return strtolower(
                    trim((string) $device->status)
                ) === 'not activated';

            })
            ->count();


        /*
        |--------------------------------------------------------------------------
        | 7. Dealer Stock Transfer History
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        */
        $transfers = StockTransfer::with([
                'stock.deviceType'
            ])
            ->where('dealer_id', $dealer->id)
            ->latest()
            ->get();


        /*
        |--------------------------------------------------------------------------
        | 8. Total Stock Received
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | 15 Dialog
        | 5 Plus
        | 10 Basic
        |
        | Total = 30
        |
        */
        $totalStockReceived = $transfers->sum('quantity');


        /*
        |--------------------------------------------------------------------------
        | 9. Latest Stock Received
        |--------------------------------------------------------------------------
        |
        | 
        |
        */
        $latestTransfer = $transfers->first();

        $latestStockReceived = $latestTransfer
            ? $latestTransfer->quantity
            : 0;


        /*
        |--------------------------------------------------------------------------
        | 10. Recent Activities
        |--------------------------------------------------------------------------
        |
        | 
        |
        */
        $recentActivity = $transfers
            ->take(5)
            ->map(function ($transfer) {

                $deviceType = $transfer->stock?->deviceType;

                /*
                 * Device display name
                 *
                 * Example:
                 * SIM with Dialog
                 * V5 with GT06N
                 */

                if ($deviceType) {

                    $category = $deviceType->device_category ?? '';
                    $model    = $deviceType->model ?? '';

                    if ($category && $model) {

                        $deviceName = $category . ' with ' . $model;

                    } elseif ($model) {

                        $deviceName = $model;

                    } elseif ($category) {

                        $deviceName = $category;

                    } else {

                        $deviceName = 'device';
                    }

                } else {

                    $deviceName = 'device';
                }


                /*
                 * Activity text
                 */
                $text =
                    'Received '
                    . $transfer->quantity
                    . ' × '
                    . $deviceName;


                /*
                 * Add remarks if available
                 */
                if (!empty($transfer->remarks)) {

                    $text .= ' — ' . $transfer->remarks;
                }


                return [

                    'date' => $transfer->created_at,

                    'text' => $text,

                ];

            })
            ->values();


        /*
        |--------------------------------------------------------------------------
        | 11. Send Everything To Dealer Dashboard
        |--------------------------------------------------------------------------
        */
        return view('dealer.dashboard', compact(

            'dealer',

            'allocatedDevices',

            'allocatedDeviceCount',

            'readyForActivationCount',

            'transfers',

            'totalStockReceived',

            'latestStockReceived',

            'recentActivity'

        ));
    }

    public function storeDealerCustomerAd(Request $request)
{
   $dealerId = auth()->user()->dealer->id ?? 1;
    $newImeis = array_filter($request->imei_numbers ?? []);

    // -------------------------------------------------------------
    // Validate IMEI Numbers
    // -------------------------------------------------------------
    $allExistingCustomers = DealerCustomerAd::all();
    $usedImeis = [];

    foreach ($allExistingCustomers as $customer) {
        // Check if the customer belongs to the current dealer
        if ($customer->dealer_id == $dealerId && strtolower(trim($customer->name)) === strtolower(trim($request->name))) {
            continue;
        }

        if (!empty($customer->imei_numbers) && is_array($customer->imei_numbers)) {
            $usedImeis = array_merge($usedImeis, $customer->imei_numbers);
        }
    }

    // check for duplicates in the new IMEIs against the used IMEIs
    foreach ($newImeis as $imei) {
        if (in_array($imei, $usedImeis)) {
            return back()
                ->withInput()
                ->withErrors(['imei_numbers' => "The IMEI number {$imei} has already been added to another customer!"]);
        }
    }

    // -------------------------------------------------------------
    // Save or Update Logic
    // -------------------------------------------------------------
    $existingCustomer = DealerCustomerAd::where('dealer_id', $dealerId)
        ->where('name', trim($request->name))
        ->first();

    if ($existingCustomer) {
        $currentImeis = $existingCustomer->imei_numbers ?? [];
        $mergedImeis = array_merge($currentImeis, $newImeis);

        $existingCustomer->update([
            'no_of_devices' => $existingCustomer->no_of_devices + $request->no_of_devices,
            'imei_numbers' => $mergedImeis,
            'contact' => $request->contact,
            'nic_or_id' => $request->nic_or_id ?? $existingCustomer->nic_or_id,
            'address' => $request->address ?? $existingCustomer->address,
        ]);

        $message = 'Customer details updated with new devices successfully!';
    } else {
        DealerCustomerAd::create([
            'dealer_id' => $dealerId,
            'name' => trim($request->name),
            'contact' => $request->contact,
            'nic_or_id' => $request->nic_or_id,
            'no_of_devices' => $request->no_of_devices,
            'imei_numbers' => $newImeis,
            'address' => $request->address,
        ]);

        $message = 'New Customer Added Successfully!';
    }

    return back()->with('success', $message);
}

        public function customerList()
        {
            $dealerId = auth()->user()->dealer->id ?? null;

        
            $customerAds = DealerCustomerAd::where('dealer_id', $dealerId)
                            ->latest()
                            ->get();

            return view('dealer.customer_list', compact('customerAds'));
        }

        public function destroyCustomerAd($id)
            {
                $dealerId = auth()->user()->dealer->id ?? null;

                //remove the customer ad from the database
                $customerAd = DealerCustomerAd::where('dealer_id', $dealerId)
                                ->where('id', $id)
                                ->firstOrFail();

                $customerAd->delete();

                return back()->with('success', 'Customer deleted successfully!');
}
}