<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Models\ActivatedDevice;
use App\Services\Audit;
use App\Services\RenewalRequestsApi;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Customer renewal requests (bank slip submitted from the mobile app). ADMIN only.
 *
 * This page never writes a subscription. Staff update the device in Device management as usual
 * (the single writer of subscription data); Approve here only closes the request, and it refuses
 * unless that device row really was updated for the requested period. So "approved" can never
 * mean "nothing changed".
 */
class RenewalRequestController extends Controller
{
    public function __construct(private RenewalRequestsApi $api)
    {
    }

    public function index(Request $request)
    {
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, RenewalRequestsApi::STATUSES, true) ? $status : 'PendingReview';

        $rows = $this->api->list($status);

        $devices = $rows === null ? collect() : ActivatedDevice::query()
            ->whereIn('imei_number', collect($rows)->pluck('imeiNumber')->filter()->unique()->all())
            ->get()
            ->keyBy('imei_number');

        $items = [];
        foreach ($rows ?? [] as $row) {
            $device = $devices->get($row['imeiNumber'] ?? '');
            $check = $this->deviceCheck($row, $device);

            $items[] = [
                'row'    => $row,
                'device' => $device,
                'ready'  => $check === null,
                'why'    => $check,
            ];
        }

        return view('renewal-requests.index', [
            'available' => $rows !== null,
            'items'     => $items,
            'status'    => $status,
            'statuses'  => RenewalRequestsApi::STATUSES,
        ]);
    }

    /** Streams the slip through an authenticated route; it never has a public URL. */
    public function slip(string $id)
    {
        $slip = $this->api->slip($id);
        abort_if($slip === null, 404, 'This slip is not available.');

        Audit::record('renewal.slip_viewed', 'renewal_request', $id, null, [], []);

        return response($slip['body'], 200, [
            'Content-Type'           => $slip['type'],
            'Content-Disposition'    => 'inline; filename="slip"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }

    public function decide(Request $request, string $id)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason'   => ['nullable', 'string', 'max:300', 'required_if:decision,reject'],
        ], [
            'reason.required_if' => 'Say why the renewal is being rejected: the customer will see this.',
        ]);

        // Never trust the browser for what is being approved: re-read the pending request from the API.
        $rows = $this->api->list('PendingReview');
        if ($rows === null) {
            return back()->withErrors(['api' => 'The mobile API could not be reached. Nothing was changed.']);
        }

        $row = collect($rows)->firstWhere('renewalRequestId', $id);
        if (! $row) {
            return back()->withErrors(['api' => 'This request is no longer waiting for review (the customer may have cancelled it, or someone else handled it).']);
        }

        $payload = ['decision' => $data['decision'], 'decidedBy' => (string) (auth()->user()->full_name ?: auth()->user()->username ?: auth()->id())];

        if ($data['decision'] === 'approve') {
            $device = ActivatedDevice::where('imei_number', $row['imeiNumber'] ?? '')->first();
            if ($why = $this->deviceCheck($row, $device)) {
                return back()->withErrors(['api' => "Not approved: {$why}"]);
            }
            $payload['slipSha256'] = (string) ($row['slipSha256'] ?? '');
        } else {
            $payload['reason'] = $data['reason'];
        }

        $result = $this->api->decide($id, $payload);
        if (! $result['ok']) {
            return back()->withErrors(['api' => $result['message']]);
        }

        $action = $data['decision'] === 'approve' ? 'renewal.approved' : 'renewal.rejected';
        Audit::record($action, 'vehicle', $row['vehicleId'] ?? null, $row['vehicleNumber'] ?? null,
            $data['decision'] === 'reject' ? ['reason' => ['from' => null, 'to' => $data['reason']]] : [],
            ['imei' => $row['imeiNumber'] ?? null, 'duration' => $row['durationModel'] ?? null, 'request_id' => $id]);

        return back()->with('success', $data['decision'] === 'approve'
            ? "Renewal approved for {$row['vehicleNumber']}. The customer was notified."
            : "Renewal rejected for {$row['vehicleNumber']}. The customer was notified.");
    }

    /**
     * Null when the device row really reflects this renewal; otherwise why it does not (shown to staff).
     * Requires: same device and vehicle, Paid, the requested period, ends in the future, and was
     * saved after the slip arrived (so an old, untouched row cannot satisfy it by coincidence).
     */
    private function deviceCheck(array $row, ?ActivatedDevice $device): ?string
    {
        if (! $device) {
            return 'this device is not bound to a customer in the admin portal.';
        }
        if (! empty($row['vehicleId']) && $device->vehicle_id !== null && strcasecmp((string) $device->vehicle_id, (string) $row['vehicleId']) !== 0) {
            return 'this device is now bound to a different vehicle than the one in the request.';
        }
        if ($device->payment_status !== 'Paid') {
            return 'the device is not marked Paid yet. Update it in Device management first.';
        }
        if (($row['durationModel'] ?? null) !== $device->subscription_model) {
            return "the device shows \"{$device->subscription_model}\" but the customer asked for \"" . ($row['durationModel'] ?? '?') . '". Update it in Device management first.';
        }
        if (! $device->subscription_end_date || $device->subscription_end_date->isPast()) {
            return 'the device subscription has no future end date. Update it in Device management first.';
        }
        if (! empty($row['slipUploadedAt']) && $device->updated_at && $device->updated_at->lt(Carbon::parse($row['slipUploadedAt']))) {
            return 'the device has not been updated since the customer sent the slip. Update it in Device management first.';
        }

        return null;
    }
}