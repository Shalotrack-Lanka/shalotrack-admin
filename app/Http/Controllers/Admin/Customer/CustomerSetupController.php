<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerAd;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Barryvdh\DomPDF\Facade\Pdf;

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

    // PDF Report Generator Method
    public function generateReport(Request $request)
    {
        $type = $request->query('type', 'active');

        if ($type === 'active') {
            $customers = CustomerAd::where('cus_status', 'verified')->orderBy('full_name')->get();
            $title = 'Active Customers Report';
        } else {
            $customers = CustomerAd::where(function ($query) {
                $query->where('cus_status', 'not_verified')->orWhereNull('cus_status');
            })->orderBy('full_name')->get();
            $title = 'Inactive Customers Report';
        }

        // Watermark Image එක Base64 වලට Convert කරගැනීම
        $logoPath = public_path('images/logo.png'); // ඔයාගේ Logo එක තියෙන Path එක දෙන්න
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $typeImg = pathinfo($logoPath, PATHINFO_EXTENSION);
            $dataImg = file_get_contents($logoPath);
            $logoBase64 = 'data:image/' . $typeImg . ';base64,' . base64_encode($dataImg);
        }

        $pdf = Pdf::loadView('admin.customer.report_pdf', compact('customers', 'title', 'logoBase64'))
            ->setPaper('a4', 'portrait');

        return $pdf->stream(strtolower(str_replace(' ', '_', $title)) . '.pdf');
    }
}
