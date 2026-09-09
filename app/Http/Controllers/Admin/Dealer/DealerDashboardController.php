<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\DealerCustomerAd;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;
use App\Http\Requests\DealerStoreCustomerAdRequest;
use App\Services\CustomerLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Barryvdh\DomPDF\Facade\Pdf;

class DealerDashboardController extends Controller
{
    public function index()
    {
        $user   = auth()->user();
        $dealer = $user->dealer;

        if (!$dealer) {
            return view('dealer.dashboard', [
                'dealer'                  => null,
                'allocatedDevices'        => collect(),
                'assignedDevices'         => collect(),
                'dealerCustomers'         => collect(),
                'pendingReminders'        => collect(),
                'allocatedDeviceCount'    => 0,
                'myStockCount'            => 0,
                'readyForActivationCount' => 0,
                'transfers'               => collect(),
                'totalStockReceived'      => 0,
                'totalCustomersCount'     => 0,
                'totalDevicesCount'       => 0,
                'totalCommission'         => 0,
                'ratePerDevice'           => 1000,
            ]);
        }

        $dealerId = $dealer->id;

        $allocatedDevices = SetupShalotrackDevice::with('deviceType')
            ->where('dealer_id', $dealerId)
            ->where(function ($q) {
                $q->whereNull('assigned_customer_id')
                  ->orWhere('assigned_customer_id', 0);
            })
            ->where('status', '!=', 'Assigned to Customer')
            ->orderByDesc('shdevice_id')
            ->get();

        $assignedDevices = SetupShalotrackDevice::with(['deviceType', 'assignedCustomer'])
            ->where('dealer_id', $dealerId)
            ->whereNotNull('assigned_customer_id')
            ->where('assigned_customer_id', '>', 0)
            ->orderByDesc('shdevice_id')
            ->get();

        $dealerCustomers = DealerCustomerAd::where('dealer_id', $dealerId)
            ->orderBy('name')
            ->get();

        $pendingReminders = collect();
        foreach ($dealerCustomers as $cust) {
            if ((int) $cust->no_of_devices > 0) {
                $pendingReminders->push([
                    'customer_id'   => $cust->id,
                    'customer_name' => $cust->name,
                    'shortage'      => (int) $cust->no_of_devices,
                    'message'       => "Customer {$cust->name} requires {$cust->no_of_devices} more device(s) to complete order.",
                ]);
            }
        }

        $allocatedDeviceCount    = $allocatedDevices->count();
        $totalCustomersCount     = $dealerCustomers->count();
        $totalDevicesCount       = $dealerCustomers->sum('no_of_devices');
        $myStockCount            = $allocatedDeviceCount;
        $assignedDeviceCount     = $assignedDevices->count();
        $ratePerDevice           = 1000;
        $totalCommission         = $assignedDeviceCount * $ratePerDevice;
        $readyForActivationCount = $allocatedDevices
            ->filter(fn($d) => strtolower(trim((string) $d->status)) === 'not activated')
            ->count();

        $transfers          = DealerTransferLedger::where('dealer_id', $dealerId)->latest()->get();
        $totalStockReceived = $transfers->sum('quantity');

        return view('dealer.dashboard', compact(
            'dealer', 'allocatedDevices', 'assignedDevices', 'dealerCustomers',
            'pendingReminders', 'allocatedDeviceCount', 'myStockCount',
            'readyForActivationCount', 'transfers', 'totalStockReceived',
            'totalCustomersCount', 'totalDevicesCount', 'totalCommission', 'ratePerDevice'
        ));
    }

    /**
     * Store a new dealer customer lead.
     * Email added as a second identity anchor for cross-system matching.
     */
    public function storeDealerCustomerAd(DealerStoreCustomerAdRequest $request)
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        if (!$dealerId) {
            return back()->withErrors(['dealer' => 'Dealer account not found!']);
        }

        $hasDevice       = $request->boolean('has_device');
        $requiredDevices = $hasDevice ? (int) $request->input('no_of_devices', 0) : 0;

        $availableStock = SetupShalotrackDevice::where('dealer_id', $dealerId)
            ->where(function ($q) {
                $q->whereNull('assigned_customer_id')->orWhere('assigned_customer_id', 0);
            })
            ->where('status', '!=', 'Assigned to Customer')
            ->count();

        $customer = DealerCustomerAd::create([
            'dealer_id'     => $dealerId,
            'name'          => trim($request->name),
            'email'         => $request->email ? strtolower(trim($request->email)) : null,
            'contact'       => trim($request->contact),
            'nic_or_id'     => $request->nic_or_id ? trim($request->nic_or_id) : null,
            'no_of_devices' => $requiredDevices,
            'imei_numbers'  => [],
            'address'       => $request->address,
        ]);

        if ($requiredDevices > $availableStock) {
            $shortage = $requiredDevices - $availableStock;

            $this->sendFirebaseNotification([
                'title'         => '⚠️ Stock Reminder Alert!',
                'body'          => "Customer {$customer->name} requested {$requiredDevices} devices, but you only have {$availableStock} in stock. Pending: {$shortage} devices.",
                'customer_name' => $customer->name,
                'required'      => $requiredDevices,
                'available'     => $availableStock,
                'shortage'      => $shortage,
            ]);
        }

        return back()->with('success', 'New Customer Added Successfully!');
    }

    /**
     * Dealer portal: full customer list enriched with real app account data.
     *
     * Each DealerCustomerAd gets two extra attributes attached:
     *   ->appAccount   CustomerAd|null  — the matched real app user
     *   ->appVehicles  Collection       — that user's vehicles from VehicleAd
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
                      ->orWhere('email', 'ilike', "%{$search}%")
                      ->orWhere('nic_or_id', 'ilike', "%{$search}%")
                      ->orWhere('address', 'ilike', "%{$search}%")
                      ->orWhereRaw('imei_numbers::text ILIKE ?', ["%{$search}%"]);
                });
            })
            ->latest()
            ->get();

        // Enrich the collection with matched app accounts and vehicles.
        // Two DB queries total regardless of collection size — never N+1.
        $customerAds = CustomerLinkService::enrichLeads($customerAds);

        return view('dealer.customer_list', compact('customerAds', 'search'));
    }

    public function assignDeviceToCustomer(Request $request)
    {
        $request->validate([
            'shdevice_id' => 'required|exists:setup_shalotrack_devices,shdevice_id',
            'customer_id' => 'required|exists:dealer_customer_ads,id',
        ]);

        $dealerId = auth()->user()->dealer->id ?? null;

        $device = SetupShalotrackDevice::where('dealer_id', $dealerId)
            ->where('shdevice_id', $request->shdevice_id)
            ->firstOrFail();

        if (!empty($device->assigned_customer_id) && $device->assigned_customer_id > 0) {
            return back()->withErrors(['assign' => "Device IMEI {$device->imei_number} is already assigned to another customer!"]);
        }

        $customer = DealerCustomerAd::where('dealer_id', $dealerId)
            ->where('id', $request->customer_id)
            ->firstOrFail();

        if ((int) $customer->no_of_devices <= 0) {
            return back()->withErrors(['assign' => "Customer {$customer->name} has already received all requested devices! (Pending: 0)"]);
        }

        $device->assigned_customer_id = $customer->id;
        $device->status               = 'Assigned to Customer';
        $device->save();

        $currentImeis = $customer->imei_numbers ?? [];
        if (!is_array($currentImeis)) {
            $currentImeis = [];
        }
        if ($device->imei_number && !in_array($device->imei_number, $currentImeis)) {
            $currentImeis[] = $device->imei_number;
        }

        $customer->update([
            'no_of_devices' => max(0, ((int) $customer->no_of_devices) - 1),
            'imei_numbers'  => $currentImeis,
        ]);

        return back()->with('success', "Device IMEI {$device->imei_number} successfully assigned to {$customer->name}!");
    }

    public function unassignDevice($shdevice_id)
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        $device = SetupShalotrackDevice::where('dealer_id', $dealerId)
            ->where('shdevice_id', $shdevice_id)
            ->firstOrFail();

        $customerId = $device->assigned_customer_id;

        $device->assigned_customer_id = null;
        $device->status               = 'Not Activated';
        $device->save();

        if ($customerId) {
            $customer = DealerCustomerAd::find($customerId);
            if ($customer) {
                $imeis = array_values(array_filter(
                    $customer->imei_numbers ?? [],
                    fn($imei) => $imei !== $device->imei_number
                ));
                $customer->update([
                    'no_of_devices' => $customer->no_of_devices + 1,
                    'imei_numbers'  => $imeis,
                ]);
            }
        }

        return back()->with('success', "Device IMEI {$device->imei_number} unassigned and returned to Available Stocks!");
    }

    public function destroyCustomerAd($id)
    {
        $dealerId  = auth()->user()->dealer->id ?? null;
        $customerAd = DealerCustomerAd::where('dealer_id', $dealerId)
            ->where('id', $id)
            ->firstOrFail();
        $customerAd->delete();

        return back()->with('success', 'Customer deleted successfully!');
    }

    public function generateReport(Request $request)
    {
        $customers = DealerCustomerAd::with('dealer')->latest()->get();
        $title     = 'CUSTOMERS ADDED BY DEALERS REPORT';

        $logoPath   = public_path('images/logo.png');
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $typeImg    = pathinfo($logoPath, PATHINFO_EXTENSION);
            $dataImg    = file_get_contents($logoPath);
            $logoBase64 = 'data:image/' . $typeImg . ';base64,' . base64_encode($dataImg);
        }

        $pdf = Pdf::loadView('admin.dealer.customer_report_pdf', compact('customers', 'title', 'logoBase64'))
            ->setPaper('a4', 'landscape');

        return $pdf->stream('customers_added_by_dealers_report.pdf');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function sendFirebaseNotification(array $data): void
    {
        $jsonPath = storage_path('app/firebase-service-account.json');
        if (!file_exists($jsonPath)) {
            \Log::error('Firebase Service Account JSON File Not Found!');
            return;
        }
        try {
            $serviceAccount = json_decode(file_get_contents($jsonPath), true);
            $now            = time();
            $header         = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $payload        = base64_encode(json_encode([
                'iss'   => $serviceAccount['client_email'],
                'sub'   => $serviceAccount['client_email'],
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            ]));
            $signatureInput = $header . '.' . $payload;
            openssl_sign($signatureInput, $rawSignature, $serviceAccount['private_key'], 'SHA256');
            $jwt           = $signatureInput . '.' . base64_encode($rawSignature);
            $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);
            $accessToken = $tokenResponse->json()['access_token'] ?? null;
            if (!$accessToken) {
                \Log::error('Firebase OAuth Token Generation Failed');
                return;
            }
            $projectId = $serviceAccount['project_id'] ?? 'shalotracklanka';
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ])->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'topic'        => 'dealer_stock_alerts',
                    'notification' => ['title' => $data['title'], 'body' => $data['body']],
                    'data'         => [
                        'customer_name' => (string) $data['customer_name'],
                        'shortage'      => (string) $data['shortage'],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Firebase Push Notification Error: ' . $e->getMessage());
        }
    }
}