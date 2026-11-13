<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Support\Input;
use App\Http\Controllers\Controller;
use App\Models\CustomerAd;
use App\Services\Audit;
use App\Services\CustomerLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;

class CustomerSetupController extends Controller
{
    public function index()
    {
        // customers:sync now also syncs vehicles — one command keeps both
        // Customer-ad and vehicle_ad tables current at the same time.
        $this->syncFromApi();

        $activeCustomers   = $this->getActive();
        $inactiveCustomers = $this->getInactive();

        return view('admin.customer.customer_setup', compact('activeCustomers', 'inactiveCustomers'));
    }

    public function refresh()
    {
        $this->syncFromApi();

        $activeCustomers   = $this->getActive();
        $inactiveCustomers = $this->getInactive();

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

        $customer             = CustomerAd::findOrFail($customerId);
        $before               = $customer->cus_status;
        $customer->cus_status = $validated['cus_status'];
        $customer->save();

        // Verifying a customer is a trust decision, so who did it (and from what to what) is kept.
        if ($before !== $customer->cus_status) {
            Audit::record('customer.verification_changed', 'customer', $customer->customer_id, $customer->full_name, ['cus_status' => ['from' => $before, 'to' => $customer->cus_status]]);
        }

        $activeCustomers   = $this->getActive();
        $inactiveCustomers = $this->getInactive();

        return response()->json([
            'active_html'   => view('admin.customer._active_table', compact('activeCustomers'))->render(),
            'inactive_html' => view('admin.customer._inactive_table', compact('inactiveCustomers'))->render(),
        ]);
    }

    public function generateReport(Request $request)
    {
        $type = Input::choice($request->query('type'), ['active', 'inactive'], 'active');

        if ($type === 'active') {
            $customers = CustomerAd::where('cus_status', 'verified')->orderBy('full_name')->get();
            $title     = 'Active Customers Report';
        } else {
            $customers = CustomerAd::where(function ($q) {
                $q->where('cus_status', 'not_verified')->orWhereNull('cus_status');
            })->orderBy('full_name')->get();
            $title = 'Inactive Customers Report';
        }

        $logoPath   = public_path('images/logo.png');
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $typeImg    = pathinfo($logoPath, PATHINFO_EXTENSION);
            $dataImg    = file_get_contents($logoPath);
            $logoBase64 = 'data:image/' . $typeImg . ';base64,' . base64_encode($dataImg);
        }

        $pdf = Pdf::loadView('admin.customer.report_pdf', compact('customers', 'title', 'logoBase64'))
            ->setPaper('a4', 'portrait');

        return $pdf->stream(strtolower(str_replace(' ', '_', $title)) . '.pdf');
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Pull fresh customers and vehicles. If the app server cannot be reached the command throws; the page must
     * still open with the customers already saved here (like the Vehicle Details page) instead of showing a 500 error.
     */
    private function syncFromApi(): void
    {
        try {
            Artisan::call('customers:sync');
        } catch (\Throwable $e) {
            Log::warning('Customer setup: sync skipped, showing the saved list: ' . $e->getMessage());
        }
    }

    private function getActive()
    {
        $customers = CustomerAd::with('vehicles')
            ->where('cus_status', 'verified')
            ->orderBy('full_name')
            ->get();

        return CustomerLinkService::enrichCustomers($customers);
    }

    private function getInactive()
    {
        $customers = CustomerAd::with('vehicles')
            ->where(function ($q) {
                $q->where('cus_status', 'not_verified')->orWhereNull('cus_status');
            })
            ->orderBy('full_name')
            ->get();

        return CustomerLinkService::enrichCustomers($customers);
    }
}