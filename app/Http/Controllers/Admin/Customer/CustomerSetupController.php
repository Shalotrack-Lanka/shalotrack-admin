<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerAd;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class CustomerSetupController extends Controller
{
    public function index()
    {
        // 1. Run the sync command every time the page loads
        Artisan::call('customers:sync');

        $activeCustomers = CustomerAd::where('cus_status', 'verified')
            ->orderBy('full_name')
            ->get();

        $inactiveCustomers = CustomerAd::where(function ($query) {
                $query->where('cus_status', 'not_verified')
                      ->orWhereNull('cus_status');
            })
            ->orderBy('full_name')
            ->get();

        return view('admin.customer.customer_setup', compact('activeCustomers', 'inactiveCustomers'));
    }

    public function refresh()
    {
        // 1. Trigger the sync command to pull new API data first!
        Artisan::call('customers:sync');

        // 2. Fetch the newly updated data
        $activeCustomers = CustomerAd::where('cus_status', 'verified')
            ->orderBy('full_name')
            ->get();

        $inactiveCustomers = CustomerAd::where(function ($query) {
                $query->where('cus_status', 'not_verified')
                      ->orWhereNull('cus_status');
            })
            ->orderBy('full_name')
            ->get();

        return response()->json([
            'active_html'   => view('admin.customer._active_table', compact('activeCustomers'))->render(),
            'inactive_html' => view('admin.customer._inactive_table', compact('inactiveCustomers'))->render(),
        ]);
    }

    public function toggleStatus(Request $request, string $customerId)
    {
        $validated = $request->validate([
            'cus_status' => 'required|in:verified,not_verified',
        ]);

        $customer = CustomerAd::findOrFail($customerId);
        $customer->cus_status = $validated['cus_status'];
        $customer->save();

        $activeCustomers = CustomerAd::where('cus_status', 'verified')
            ->orderBy('full_name')
            ->get();

        $inactiveCustomers = CustomerAd::where(function ($query) {
                $query->where('cus_status', 'not_verified')
                      ->orWhereNull('cus_status');
            })
            ->orderBy('full_name')
            ->get();

        return response()->json([
            'active_html'   => view('admin.customer._active_table', compact('activeCustomers'))->render(),
            'inactive_html' => view('admin.customer._inactive_table', compact('inactiveCustomers'))->render(),
        ]);
    }
}
