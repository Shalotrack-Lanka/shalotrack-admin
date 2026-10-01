<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Services\Audit;
use App\Enums\DeviceStatus;
use App\Http\Controllers\Controller;
use App\Models\DealerCustomerAd;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;
use App\Http\Requests\DealerStoreCustomerAdRequest;
use App\Services\CustomerLinkService;
use App\Services\DealerCommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class DealerDashboardController extends Controller
{
    public function __construct(private DealerCommissionService $commission)
    {
    }

    public function index()
    {
        $user = auth()->user();
        $userEmail = strtolower(trim($user->email));

        $dealer = \App\Models\Dealer::whereRaw('LOWER(contact_email) = ?', [$userEmail])->first();

        if (!$dealer && $user->dealer_id) {
            $dealer = \App\Models\Dealer::find($user->dealer_id);
        }

        if (!$dealer) {
            $dealer = \App\Models\Dealer::create([
                'full_name'     => $user->name ?: 'Dealer Account',
                'contact_email' => $user->email,
                'status'        => 'active',
                'region'        => 'Western',
                'dealer_status' => 'Authorized Dealer'
            ]);
        } else {
            if (in_array($dealer->full_name, ['Dealer Account', 'Default Dealer']) || str_contains($dealer->full_name, '@')) {
                $dealer->full_name = $user->name ?: 'Dealer';
                $dealer->save();
            }
        }

        if ($user->dealer_id !== $dealer->id) {
            $user->dealer_id = $dealer->id;
            $user->save();
        }

        // AUTO-FIX: Customer nathi ewa stock ekata gannawa
        // LOGGED as of 2026-09-29 -- this correction should be impossible given
        // the assign/unassign/reassign code paths in this controller, so if it
        // keeps firing in production logs, something outside this app is writing
        // bad rows into setup_shalotrack_devices. Do not remove this log until
        // that's confirmed one way or the other.
        $brokenAssignments = SetupShalotrackDevice::where('dealer_id', $dealer->id)
            ->where(function ($q) {
                $q->whereNull('assigned_customer_id')
                  ->orWhere('assigned_customer_id', 0);
            })
            ->where('status', DeviceStatus::AssignedToCustomer->value)
            ->get(['shdevice_id', 'imei_number']);

        if ($brokenAssignments->isNotEmpty()) {
            \Log::warning('Dealer dashboard auto-corrected orphaned "Assigned to Customer" device(s) with no linked customer', [
                'dealer_id' => $dealer->id,
                'devices'   => $brokenAssignments->map(fn($d) => [
                    'shdevice_id' => $d->shdevice_id,
                    'imei'        => $d->imei_number,
                ])->all(),
            ]);

            // The sale no longer exists, so any commission earned for it must be reversed too,
            // otherwise the dealer keeps money for a device that isn't sold (ledger stays
            // append-only: recordReversed adds a negative row, it never deletes).
            foreach ($brokenAssignments as $orphan) {
                $this->commission->recordReversed($orphan, 'orphaned_assignment_corrected');
            }

            // Back to the state an unsold device has in dealer stock: Activated.
            SetupShalotrackDevice::whereIn('shdevice_id', $brokenAssignments->pluck('shdevice_id'))
                ->update(['status' => DeviceStatus::Activated->value]);
        }

        $dealerLeads     = DealerCustomerAd::where('dealer_id', $dealer->id)->get();
        $dealerCustomers = $dealerLeads;

        // Only devices the dealer can really assign (see scopeAvailableForDealer).
        $allocatedDevices = SetupShalotrackDevice::availableForDealer($dealer->id)
            ->latest()
            ->get();
            
        $allocatedDevicesCount = $allocatedDevices->count();

        $assignedDevices = SetupShalotrackDevice::where('dealer_id', $dealer->id)
            ->whereNotNull('assigned_customer_id')
            ->where('assigned_customer_id', '>', 0)
            // Sold devices stay on the dealer's list after an admin binds them to the
            // customer's vehicle (status becomes Activated / Temporarily Stopped).
            ->whereIn('status', [
                DeviceStatus::AssignedToCustomer->value,
                DeviceStatus::Activated->value,
                DeviceStatus::TemporarilyStopped->value,
            ])
            ->get();
            
        $assignedDevicesCount = $assignedDevices->count();

        $pendingDevices = SetupShalotrackDevice::where('dealer_id', $dealer->id)
            ->where('status', DeviceStatus::PendingRepair->value)
            ->latest()
            ->get();

        $brokenDevices = SetupShalotrackDevice::where('dealer_id', $dealer->id)
            ->where('status', DeviceStatus::BrokenDevice->value)
            ->latest()
            ->get();

        // Commission is deliberately NOT shown to dealers yet (no payment-status gate, no historical backfill;
        // see DealerCommissionService). The ledger keeps recording earned/reversed rows on assign/unassign.
        // Not computed here either, so a commission-service fault can never break the dealer dashboard.
        $totalCustomers = $dealerLeads->count();

        $customerIds = app(\App\Services\DealerCustomerScope::class)->customerIds($dealer);

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
            'assignedDevicesCount',
            'pendingDevices',
            'brokenDevices'
        ));
    }

    public function storeDealerCustomerAd(DealerStoreCustomerAdRequest $request)
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        if (!$dealerId) {
            return back()->withErrors(['dealer' => 'Dealer account not found!']);
        }

        $hasDevice       = $request->boolean('has_device');
        $requiredDevices = $hasDevice ? (int) $request->input('no_of_devices', 0) : 0;

        $availableStock = SetupShalotrackDevice::availableForDealer($dealerId)->count();

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
        // No synchronous customers:sync here. It is scheduled every 5 minutes (routes/console.php);
        // running it inside the request made this page wait on the API (2 calls x 3 retries x 15 s
        // worst case) and added load on every page view.
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

        $availableDevices = SetupShalotrackDevice::availableForDealer($dealerId)
            ->latest()
            ->get();

        return view('dealer.customer_list', compact('customerAds', 'search', 'availableDevices'));
    }

    /** True when the device is bound to a customer's vehicle + subscription (an activated_devices row exists). */
    private function isBound(SetupShalotrackDevice $device): bool
    {
        return \App\Models\ActivatedDevice::where('imei_number', $device->imei_number)->exists();
    }

    public function assignNewDeviceFromList(Request $request)
    {
        $request->validate([
            'shdevice_id' => 'required|exists:setup_shalotrack_devices,shdevice_id',
            'customer_id' => 'required|exists:dealer_customer_ads,id',
        ]);

        $dealer   = auth()->user()->dealer;
        $dealerId = $dealer->id ?? null;

        $device = SetupShalotrackDevice::where('dealer_id', $dealerId)
            ->where('shdevice_id', $request->shdevice_id)
            ->firstOrFail();

        if (!empty($device->assigned_customer_id) && $device->assigned_customer_id > 0) {
            return back()->withErrors(['assign' => "Device IMEI {$device->imei_number} is already assigned to another customer!"]);
        }

        // FIX: nothing here previously checked device status before handing
        // it to a customer. A device that ended up in this dealer's stock
        // while already 'Activated' (e.g. via the stock-transfer gap fixed
        // separately in StockTransferController, or any future path that
        // produces the same bad state) would get silently assigned to a
        // dealer customer on top of whatever it was already bound to.
        // Belt-and-suspenders: block it here too, not just at the transfer
        // entry point, since this is the last line of defense before a real
        // double-binding happens.
        // Company activates first, dealer sells after: only an Activated, unbound
        // device can be sold. Anything else is refused with the reason.
        if ($device->status !== DeviceStatus::Activated->value || $this->isBound($device)) {
            return back()->withErrors(['assign' => "Device IMEI {$device->imei_number} cannot be assigned -- its status is \"{$device->status}\". Only activated devices that are not yet on a customer account can be sold. If it is \"Not Activated\", ask the admin to activate it first."]);
        }

        $customer = DealerCustomerAd::where('dealer_id', $dealerId)
            ->where('id', $request->customer_id)
            ->firstOrFail();

        $device->assigned_customer_id = $customer->id;
        $device->status               = DeviceStatus::AssignedToCustomer->value;
        $device->save();

        if ($dealer) {
            $this->commission->recordEarned($device, $dealer, 'device_assigned');
        }

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

        Audit::record('device.assigned', 'device', $device->shdevice_id, $device->imei_number, [], ['dealer_id' => $device->dealer_id, 'customer' => $customer->name]);

        return back()->with('success', "Device IMEI {$device->imei_number} successfully assigned to {$customer->name}!");
    }

    public function assignDeviceToCustomer(Request $request)
    {
        $request->validate([
            'shdevice_id' => 'required|exists:setup_shalotrack_devices,shdevice_id',
            'customer_id' => 'required|exists:dealer_customer_ads,id',
        ]);

        $dealer   = auth()->user()->dealer;
        $dealerId = $dealer->id ?? null;

        $device = SetupShalotrackDevice::where('dealer_id', $dealerId)
            ->where('shdevice_id', $request->shdevice_id)
            ->firstOrFail();

        if (!empty($device->assigned_customer_id) && $device->assigned_customer_id > 0) {
            return back()->withErrors(['assign' => "Device IMEI {$device->imei_number} is already assigned to another customer!"]);
        }

        // FIX: same guard as assignNewDeviceFromList() above -- see that
        // method's comment for why this matters.
        // Company activates first, dealer sells after: only an Activated, unbound
        // device can be sold. Anything else is refused with the reason.
        if ($device->status !== DeviceStatus::Activated->value || $this->isBound($device)) {
            return back()->withErrors(['assign' => "Device IMEI {$device->imei_number} cannot be assigned -- its status is \"{$device->status}\". Only activated devices that are not yet on a customer account can be sold. If it is \"Not Activated\", ask the admin to activate it first."]);
        }

        $customer = DealerCustomerAd::where('dealer_id', $dealerId)
            ->where('id', $request->customer_id)
            ->firstOrFail();

        if ((int) $customer->no_of_devices <= 0) {
            return back()->withErrors(['assign' => "Customer {$customer->name} has already received all requested devices! (Pending: 0)"]);
        }

        $device->assigned_customer_id = $customer->id;
        $device->status               = DeviceStatus::AssignedToCustomer->value;
        $device->save();

        if ($dealer) {
            $this->commission->recordEarned($device, $dealer, 'device_assigned');
        }

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

        Audit::record('device.assigned', 'device', $device->shdevice_id, $device->imei_number, [], ['dealer_id' => $device->dealer_id, 'customer' => $customer->name]);

        return back()->with('success', "Device IMEI {$device->imei_number} successfully assigned to {$customer->name}!");
    }

    // 💡 1. UNASSIGN: Status -> Pending. (Habai Customer wa mathaka thiya gannawa)
    public function unassignDevice($shdevice_id)
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        $device = SetupShalotrackDevice::where('dealer_id', $dealerId)
            ->where('shdevice_id', $shdevice_id)
            ->firstOrFail();

        if ($this->isBound($device)) {
            return back()->withErrors(['assign' => "Device IMEI {$device->imei_number} is already active on the customer's account (vehicle and subscription). Ask the admin to release it before it is unassigned."]);
        }

        $customerId = $device->assigned_customer_id;

        $device->status = DeviceStatus::PendingRepair->value;
        $device->save();

        // Device is leaving the "sold" state -- reverse whatever commission
        // was earned for it. No-ops safely if it had none (e.g. was never
        // actually assigned in the first place).
        $this->commission->recordReversed($device, 'device_unassigned');

        // Customer ge App account eken device eka ain karanawa, eyaata aluth ekak denna puluwan wenna
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

        Audit::record('device.unassigned', 'device', $device->shdevice_id, $device->imei_number, [], ['dealer_id' => $device->dealer_id]);

        return back()->with('success', "Device IMEI {$device->imei_number} moved to Pending Repair!");
    }

    // 💡 2. REASSIGN: Status -> Assigned. (Parana customer tama apahu denawa)
    public function reassignDevice($shdevice_id)
    {
        $dealer   = auth()->user()->dealer;
        $dealerId = $dealer->id ?? null;

        $device = SetupShalotrackDevice::where('dealer_id', $dealerId)
            ->where('shdevice_id', $shdevice_id)
            ->firstOrFail();

        // Only a repaired device (Pending Repair) goes back. A broken device must be
        // tested first, and a device live on a vehicle must never be moved by the dealer.
        if ($device->status !== DeviceStatus::PendingRepair->value || $this->isBound($device)) {
            return back()->withErrors(['assign' => "Device IMEI {$device->imei_number} cannot be reassigned -- its status is \"{$device->status}\". Only devices in Pending Repair can be reassigned."]);
        }

        $customerId = $device->assigned_customer_id;

        if (!$customerId) {
            $device->status = DeviceStatus::Activated->value;
            $device->save();
            // Back in stock with no sale: reverse any commission still active for it.
            $this->commission->recordReversed($device, 'returned_to_stock_without_customer');
            Audit::record('device.reassigned', 'device', $device->shdevice_id, $device->imei_number, [], ['dealer_id' => $device->dealer_id, 'to' => 'available stock']);
            return back()->with('success', "Device moved to Available Stocks (no customer linked).");
        }

        $device->status = DeviceStatus::AssignedToCustomer->value;
        $device->save();

        // Device is back in the "sold" state -- recordEarned() is
        // idempotent, so this only actually writes a row if there isn't
        // already an active one (e.g. this device was previously unassigned
        // and reversed, and is now genuinely being re-sold).
        if ($dealer) {
            $this->commission->recordEarned($device, $dealer, 'device_reassigned');
        }

        // Customer ge App eke list ekata apahu add karanawa
        $customer = DealerCustomerAd::find($customerId);
        if ($customer) {
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
        }

        Audit::record('device.reassigned', 'device', $device->shdevice_id, $device->imei_number, [], ['dealer_id' => $device->dealer_id, 'to' => 'original customer']);

        return back()->with('success', "Device IMEI {$device->imei_number} reassigned to the original customer successfully!");
    }

    // 💡 3. BROKEN: Status -> Broken. (Meka hadanna ba, Customer ain karanne na history ekata ona nisa)
    public function markDeviceBroken($shdevice_id)
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        $device = SetupShalotrackDevice::where('dealer_id', $dealerId)
            ->where('shdevice_id', $shdevice_id)
            ->firstOrFail();

        if ($this->isBound($device)) {
            return back()->withErrors(['assign' => "Device IMEI {$device->imei_number} is already active on the customer's account. Ask the admin to release it before it is marked broken."]);
        }

        $device->status = DeviceStatus::BrokenDevice->value;
        $device->save();

        // Same reversal as unassignDevice() -- a device that's now broken
        // is no longer a completed sale. No-op if it had no active
        // commission entry (e.g. was broken before ever being assigned).
        $this->commission->recordReversed($device, 'device_broken');

        Audit::record('device.marked_broken', 'device', $device->shdevice_id, $device->imei_number, [], ['dealer_id' => $device->dealer_id]);

        return back()->with('success', "Device IMEI {$device->imei_number} marked as Broken.");
    }

    // 💡 4. TESTING: Broken idan apahu Pending walata
    public function moveToPending($shdevice_id)
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        $device = SetupShalotrackDevice::where('dealer_id', $dealerId)
            ->where('shdevice_id', $shdevice_id)
            ->firstOrFail();

        // Only a Broken device goes back for testing. Anything else (sold, or live on a
        // vehicle) would change a status the gateway relies on, so it is refused.
        if ($device->status !== DeviceStatus::BrokenDevice->value || $this->isBound($device)) {
            return back()->withErrors(['assign' => "Device IMEI {$device->imei_number} cannot be moved to Pending Repair -- its status is \"{$device->status}\". Only Broken devices can be sent back for testing."]);
        }

        $device->status = DeviceStatus::PendingRepair->value;
        $device->save();

        Audit::record('device.moved_to_pending', 'device', $device->shdevice_id, $device->imei_number, [], ['dealer_id' => $device->dealer_id]);

        return back()->with('success', "Device IMEI {$device->imei_number} moved back to Pending Repair for testing!");
    }

    // Route existed (dealer.customer-ad.edit) but this method never did —
    // hitting it threw "Call to undefined method". Scoped to the
    // authenticated dealer exactly like destroyCustomerAd(), so one
    // dealer can never read another dealer's lead by guessing an id.
    public function editCustomerAd($id)
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        $customerAd = DealerCustomerAd::where('dealer_id', $dealerId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'id'            => $customerAd->id,
            'name'          => $customerAd->name,
            'email'         => $customerAd->email,
            'contact'       => $customerAd->contact,
            'nic_or_id'     => $customerAd->nic_or_id,
            'no_of_devices' => $customerAd->no_of_devices,
            'imei_numbers'  => $customerAd->imei_numbers,
            'address'       => $customerAd->address,
        ]);
    }

    public function destroyCustomerAd($id)
    {
        $dealerId  = auth()->user()->dealer->id ?? null;
        $customerAd = DealerCustomerAd::where('dealer_id', $dealerId)
            ->where('id', $id)
            ->firstOrFail();

        // Devices still point at this customer (sold, pending repair or broken). Deleting
        // the lead would orphan them, so the dealer must unassign them first.
        if (SetupShalotrackDevice::where('assigned_customer_id', $customerAd->id)->exists()) {
            return back()->withErrors(['assign' => "{$customerAd->name} still has devices assigned. Unassign them before deleting this customer."]);
        }

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