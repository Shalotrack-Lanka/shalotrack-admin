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
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class DealerDashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $userEmail = strtolower(trim($user->email));

        // 1. Strict Match: User ගේ හරියටම Email එකට ගැලපෙන Dealer Profile එක සොයන්න
        $dealer = \App\Models\Dealer::whereRaw('LOWER(contact_email) = ?', [$userEmail])->first();

        if (!$dealer && $user->dealer_id) {
            $dealer = \App\Models\Dealer::find($user->dealer_id);
        }

        // 2. අදාළ Profile එකක් නැත්නම්, අලුතින්ම සාදන්න
        if (!$dealer) {
            $dealer = \App\Models\Dealer::create([
                'full_name'     => $user->name ?: 'Dealer Account',
                'contact_email' => $user->email,
                'status'        => 'active',
                'region'        => 'Western',
                'dealer_status' => 'Authorized Dealer'
            ]);
        } else {
            // 💡 AUTO-CORRECT FIX: Database එකේ නම Email එකක් විදිහට හරි "Dealer Account" විදිහට හරි සේව් වෙලා නම්, ඒක ලොග් වුණු කෙනාගේ ඇත්ත නමට මාරු කරන්න.
            if (in_array($dealer->full_name, ['Dealer Account', 'Default Dealer']) || str_contains($dealer->full_name, '@')) {
                $dealer->full_name = $user->name ?: 'Dealer';
                $dealer->save();
            }
        }

        // 3. User Table එකේ Dealer ID එක Sync කරන්න
        if ($user->dealer_id !== $dealer->id) {
            $user->dealer_id = $dealer->id;
            $user->save();
        }

        // 4. Dealer Customer Leads
        $dealerLeads     = DealerCustomerAd::where('dealer_id', $dealer->id)->get();
        $dealerCustomers = $dealerLeads;

        $emails = $dealerLeads->pluck('email')->filter()->map(fn($e) => strtolower(trim($e)))->toArray();
        $phones = $dealerLeads->pluck('contact')->filter()->map(function($p) {
            $digits = preg_replace('/[^0-9]/', '', $p);
            return strlen($digits) >= 9 ? substr($digits, -9) : $digits;
        })->toArray();

        // 5. Allocated Devices (Available Stocks - Filtered to show only unassigned devices)
        $allocatedDevices = SetupShalotrackDevice::where('dealer_id', $dealer->id)
            ->where(function ($q) {
                $q->whereNull('assigned_customer_id')
                  ->orWhere('assigned_customer_id', 0);
            })
            ->where('status', '!=', 'Assigned to Customer')
            ->latest()
            ->get();
            
        $allocatedDevicesCount = $allocatedDevices->count();

        // Assigned Devices
        $assignedDevices = SetupShalotrackDevice::where('dealer_id', $dealer->id)
            ->whereNotNull('assigned_customer_id')
            ->where('assigned_customer_id', '>', 0)
            ->get();
            
        $assignedDevicesCount = $assignedDevices->count();

        // 6. Customers and Vehicles
        $customerIds = \App\Models\CustomerAd::query()
            ->where(function ($q) use ($emails, $phones) {
                if (!empty($emails)) {
                    $q->whereIn(DB::raw('LOWER(email)'), $emails);
                }
                foreach ($phones as $phone) {
                    $q->orWhere('phone_number', 'LIKE', '%' . $phone);
                }
            })
            ->pluck('customer_id')
            ->toArray();

        $totalCustomers    = count($customerIds);
        $vehicles          = \App\Models\VehicleAd::whereIn('customer_id', $customerIds)->get();
        $totalVehicles     = $vehicles->count();
        $activeGpsVehicles = $vehicles->whereNotNull('imei')->where('imei', '!=', '')->count();

        return view('dealer.dashboard', compact(
            'dealer',
            'dealerLeads',
            'dealerCustomers',
            'totalCustomers',
            'totalVehicles',
            'activeGpsVehicles',
            'allocatedDevices',
            'allocatedDevicesCount',
            'assignedDevices',
            'assignedDevicesCount'
        ));
    }

    /**
     * Store a new dealer customer lead.
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

    public function customerList(Request $request)
    {
        \Illuminate\Support\Facades\Artisan::call('customers:sync');

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