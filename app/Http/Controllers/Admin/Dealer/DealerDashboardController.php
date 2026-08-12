<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\DealerCustomerAd;
use App\Models\StockTransfer;
use App\Models\SetupShalotrackDevice;
use App\Http\Requests\DealerStoreCustomerAdRequest; 
use Illuminate\Http\Request;

class DealerDashboardController extends Controller
{
    /**
     * Display the Dealer Dashboard with metrics, stock, allocated devices, and commissions.
     */
    public function index()
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Authenticated User & Dealer Lookup
        |--------------------------------------------------------------------------
        */
        $user = auth()->user();
        $dealer = $user->dealer;

        /*
        |--------------------------------------------------------------------------
        | 2. If Dealer Account Is Not Linked
        |--------------------------------------------------------------------------
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
                'totalCustomersCount'     => 0,
                'totalDevicesCount'       => 0,
                'totalCommission'         => 0,
                'ratePerDevice'           => 1000,
            ]);
        }

        $dealerId = $dealer->id;

        /*
        |--------------------------------------------------------------------------
        | 3. Customer Ads & Commission Calculations
        |--------------------------------------------------------------------------
        */
        $customerAds = DealerCustomerAd::where('dealer_id', $dealerId)->get();

        $totalCustomersCount = $customerAds->count();
        $totalDevicesCount   = $customerAds->sum('no_of_devices');

        // Tier Logic: 10 or more devices = LKR 1500 per device, otherwise LKR 1000
        $ratePerDevice   = ($totalDevicesCount >= 10) ? 1500 : 1000;
        $totalCommission = $totalDevicesCount * $ratePerDevice;

        /*
        |--------------------------------------------------------------------------
        | 4. Physical Devices Allocated To Dealer
        |--------------------------------------------------------------------------
        */
        $allocatedDevices = SetupShalotrackDevice::with('deviceType')
            ->where('dealer_id', $dealerId)
            ->orderByDesc('shdevice_id')
            ->get();

        $allocatedDeviceCount = $allocatedDevices->count();

        /*
        |--------------------------------------------------------------------------
        | 5. Ready For Activation Count
        |--------------------------------------------------------------------------
        */
        $readyForActivationCount = $allocatedDevices
            ->filter(function ($device) {
                return strtolower(trim((string) $device->status)) === 'not activated';
            })
            ->count();

        /*
        |--------------------------------------------------------------------------
        | 6. Stock Transfers & History
        |--------------------------------------------------------------------------
        */
        $transfers = StockTransfer::with(['stock.deviceType'])
            ->where('dealer_id', $dealerId)
            ->latest()
            ->get();

        $totalStockReceived = $transfers->sum('quantity');

        $latestTransfer = $transfers->first();
        $latestStockReceived = $latestTransfer ? $latestTransfer->quantity : 0;

        /*
        |--------------------------------------------------------------------------
        | 7. Recent Activities Log
        |--------------------------------------------------------------------------
        */
        $recentActivity = $transfers
            ->take(5)
            ->map(function ($transfer) {
                $deviceType = $transfer->stock?->deviceType;

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

                $text = 'Received ' . $transfer->quantity . ' × ' . $deviceName;

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
        | 8. Return View With Compact Data
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
            'recentActivity',
            'totalCustomersCount',
            'totalDevicesCount',
            'totalCommission',
            'ratePerDevice'
        ));
    }

    /**
     * Store or Update Customer Ad along with IMEI Numbers.
     */
    public function storeDealerCustomerAd(DealerStoreCustomerAdRequest $request)
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        if (!$dealerId) {
            return back()->withErrors(['dealer' => 'Dealer account not found!']);
        }

        $newImeis = array_filter($request->imei_numbers ?? []);

        /*
        |--------------------------------------------------------------------------
        | Check Duplicate IMEIs Against Other Existing Records
        |--------------------------------------------------------------------------
        */
        $allExistingCustomers = DealerCustomerAd::all();
        $usedImeis = [];

        foreach ($allExistingCustomers as $customer) {
            // Ignore current customer's existing records if updating
            if ($customer->dealer_id == $dealerId && strtolower(trim($customer->name)) === strtolower(trim($request->name))) {
                continue;
            }

            if (!empty($customer->imei_numbers) && is_array($customer->imei_numbers)) {
                $usedImeis = array_merge($usedImeis, $customer->imei_numbers);
            }
        }

        foreach ($newImeis as $imei) {
            if (in_array($imei, $usedImeis)) {
                return back()
                    ->withInput()
                    ->withErrors(['imei_numbers' => "The IMEI number {$imei} has already been added to another customer!"]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Create or Update Customer Lead
        |--------------------------------------------------------------------------
        */
        $existingCustomer = DealerCustomerAd::where('dealer_id', $dealerId)
            ->where('name', trim($request->name))
            ->first();

        if ($existingCustomer) {
            $currentImeis = $existingCustomer->imei_numbers ?? [];
            $mergedImeis  = array_merge($currentImeis, $newImeis);

            $existingCustomer->update([
                'no_of_devices' => $existingCustomer->no_of_devices + $request->no_of_devices,
                'imei_numbers'  => $mergedImeis,
                'contact'       => $request->contact,
                'nic_or_id'     => $request->nic_or_id ?? $existingCustomer->nic_or_id,
                'address'        => $request->address ?? $existingCustomer->address,
            ]);

            $message = 'Customer details updated with new devices successfully!';
        } else {
            DealerCustomerAd::create([
                'dealer_id'     => $dealerId,
                'name'          => trim($request->name),
                'contact'       => $request->contact,
                'nic_or_id'     => $request->nic_or_id,
                'no_of_devices' => $request->no_of_devices,
                'imei_numbers'  => $newImeis,
                'address'       => $request->address,
            ]);

            $message = 'New Customer Added Successfully!';
        }

        return back()->with('success', $message);
    }

    /**
     * Display the Customer List for the logged-in Dealer.
     */
    public function customerList()
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        $customerAds = DealerCustomerAd::where('dealer_id', $dealerId)
            ->latest()
            ->get();

        return view('dealer.customer_list', compact('customerAds'));
    }

    /**
     * Delete a Customer Ad record.
     */
    public function destroyCustomerAd($id)
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        $customerAd = DealerCustomerAd::where('dealer_id', $dealerId)
            ->where('id', $id)
            ->firstOrFail();

        $customerAd->delete();

        return back()->with('success', 'Customer deleted successfully!');
    }
}