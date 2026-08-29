<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\DealerCustomerAd;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;
use App\Http\Requests\DealerStoreCustomerAdRequest; 
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class DealerDashboardController extends Controller
{
    /**
     * Display the Dealer Dashboard with metrics, stock, allocated devices, and commissions.
     */
    public function index()
{
    $user = auth()->user();
    $dealer = $user->dealer;

    if (!$dealer) {
        return view('dealer.dashboard', [
            'dealer'                  => null,
            'allocatedDevices'        => collect(),
            'allocatedDeviceCount'    => 0,
            'myStockCount'            => 0,
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
    | 1. Physical Devices Allocated To Dealer (From setup_shalotrack_devices)
    |--------------------------------------------------------------------------
    */
    $allocatedDevices = SetupShalotrackDevice::with('deviceType')
        ->where('dealer_id', $dealerId)
        ->orderByDesc('shdevice_id')
        ->get();

    $allocatedDeviceCount = $allocatedDevices->count(); // Allocated Devices Count

    /*
    |--------------------------------------------------------------------------
    | 2. Customer Ads & Devices Count
    |--------------------------------------------------------------------------
    */
    $customerAds = DealerCustomerAd::where('dealer_id', $dealerId)->get();

    $totalCustomersCount = $customerAds->count();
    $totalDevicesCount   = $customerAds->sum('no_of_devices'); // Customer List Devices Count

    /*
    |--------------------------------------------------------------------------
    | 3. Total Received Stock (Equalizing Allocated Devices & Customer Devices)
    |--------------------------------------------------------------------------
    | My Allocated Devices ගණන සහ Customer List එකේ Devices ගණන
    | එක සමානව Card එකට සහ Tables දෙකටම පෙන්නුම් කරයි.
    */
    $myStockCount = $allocatedDeviceCount; 

    /*
    |--------------------------------------------------------------------------
    | 4. Commission Calculations & Ready Status
    |--------------------------------------------------------------------------
    */
    $ratePerDevice   = ($totalDevicesCount >= 10) ? 1500 : 1000;
    $totalCommission = $totalDevicesCount * $ratePerDevice;

    $readyForActivationCount = $allocatedDevices
        ->filter(fn($device) => strtolower(trim((string) $device->status)) === 'not activated')
        ->count();

    $transfers = DealerTransferLedger::where('dealer_id', $dealerId)->latest()->get();
    $totalStockReceived = $transfers->sum('quantity');

    return view('dealer.dashboard', compact(
        'dealer',
        'allocatedDevices',
        'allocatedDeviceCount',
        'myStockCount',
        'readyForActivationCount',
        'transfers',
        'totalStockReceived',
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
    /**
 * Display the Customer List with Search functionality for the logged-in Dealer.
 */
public function customerList(Request $request)
{
    $dealerId = auth()->user()->dealer->id ?? null;

    if (!$dealerId) {
        return back()->withErrors(['dealer' => 'Dealer account not found!']);
    }

    $search = trim((string) $request->input('search', ''));

    $customerAds = DealerCustomerAd::where('dealer_id', $dealerId)
        ->when($search !== '', function ($query) use ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('contact', 'ilike', "%{$search}%")
                  ->orWhere('nic_or_id', 'ilike', "%{$search}%")
                  ->orWhere('address', 'ilike', "%{$search}%")
                  // JSON column එකක් ලෙස IMEI List එකේ තියෙනවා නම් ඒ තුළ search කිරීම
                  ->orWhereRaw('imei_numbers::text ILIKE ?', ["%{$search}%"]);
            });
        })
        ->latest()
        ->get();

    return view('dealer.customer_list', compact('customerAds', 'search'));
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

    /**
     * Generate PDF Report for Customers Added by Dealer
     */
    public function generateReport(Request $request)
    {
        $customers = DealerCustomerAd::with('dealer')->latest()->get(); 
        
        $title = 'CUSTOMERS ADDED BY DEALERS REPORT';

        $logoPath = public_path('images/logo.png');
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $typeImg = pathinfo($logoPath, PATHINFO_EXTENSION);
            $dataImg = file_get_contents($logoPath);
            $logoBase64 = 'data:image/' . $typeImg . ';base64,' . base64_encode($dataImg);
        }

        $pdf = Pdf::loadView('admin.dealer.customer_report_pdf', compact('customers', 'title', 'logoBase64'))
            ->setPaper('a4', 'landscape');

        return $pdf->stream('customers_added_by_dealers_report.pdf');
    }
}