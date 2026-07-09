<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerAd;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CustomerSetupController extends Controller
{
    public function index()
    {
        CustomerAd::expireOverdueSubscriptions();

        $activeCustomers = CustomerAd::where('payment_status', 'paid')
            ->orderBy('full_name')
            ->get();

        $inactiveCustomers = CustomerAd::where('payment_status', 'not_paid')
            ->orderBy('full_name')
            ->get();

        
        return view('admin.customer.customer_setup', compact('activeCustomers', 'inactiveCustomers'));
    }
    
    public function refresh()
    {
    CustomerAd::expireOverdueSubscriptions();

    $activeCustomers = CustomerAd::where('payment_status', 'paid')
        ->orderBy('full_name')
        ->get();

    $inactiveCustomers = CustomerAd::where('payment_status', 'not_paid')
        ->orderBy('full_name')
        ->get();

    return response()->json([
        'active_html'   => view('admin.customer._active_table', compact('activeCustomers'))->render(),
        'inactive_html' => view('admin.customer._inactive_table', compact('inactiveCustomers'))->render(),
    ]);
    }



    public function update(Request $request, string $customerId)
    {
        $customer = CustomerAd::findOrFail($customerId);

        $validated = $request->validate([
            'payment_status'       => 'required|in:paid,not_paid',
            'imei_number'          => 'nullable|string|max:255',
            'sim_number'           => 'nullable|string|max:255',
            'device_type'          => 'nullable|string|max:255',
            'subscription_period'  => 'nullable|in:3_months,6_months,1_year,3_years',
            'bank_invoice'         => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        // Marking as Paid requires a subscription period and an invoice on file
        if ($validated['payment_status'] === 'paid') {
            if (empty($validated['subscription_period'])) {
                return back()->withErrors(['subscription_period' => 'Subscription period is required to mark a customer as Paid.'])->withInput();
            }

            $hasInvoice = $request->hasFile('bank_invoice') || $customer->bank_invoice_path;
            if (! $hasInvoice) {
                return back()->withErrors(['bank_invoice' => 'A bank invoice must be attached before saving as Paid.'])->withInput();
            }
        }

        // Upload new invoice if one was provided
        if ($request->hasFile('bank_invoice')) {
            $file = $request->file('bank_invoice');
            $path = 'bank-invoices/' . $customer->customer_id . '-' . now()->timestamp . '.' . $file->getClientOriginalExtension();
            Storage::disk('supabase')->put($path, file_get_contents($file));
            $customer->bank_invoice_path = $path;
        }

        $customer->imei_number = $validated['imei_number'] ?? $customer->imei_number;
        $customer->sim_number = $validated['sim_number'] ?? $customer->sim_number;
        $customer->device_type = $validated['device_type'] ?? $customer->device_type;
        $customer->payment_status = $validated['payment_status'];

        if ($validated['payment_status'] === 'paid') {
            $customer->subscription_period = $validated['subscription_period'];
            $customer->subscription_start_date = now()->toDateString();
            $customer->subscription_end_date = $this->calculateEndDate(now(), $validated['subscription_period'])->toDateString();
        } else {
            $customer->subscription_start_date = null;
            $customer->subscription_end_date = null;
        }

        $customer->save();

        return redirect()->route('admin.customer-setup')->with('success', 'Customer updated successfully.');
    }

    public function viewInvoice(string $customerId)
    {
        $customer = CustomerAd::findOrFail($customerId);
        abort_if(! $customer->bank_invoice_path, 404, 'No invoice on file.');

        $url = Storage::disk('supabase')->temporaryUrl($customer->bank_invoice_path, now()->addMinutes(10));
        return redirect($url);
    }

    private function calculateEndDate($start, string $period)
    {
        return match ($period) {
            '3_months' => $start->copy()->addMonths(3),
            '6_months' => $start->copy()->addMonths(6),
            '1_year'   => $start->copy()->addYear(),
            '3_years'  => $start->copy()->addYears(3),
        };
    }
}