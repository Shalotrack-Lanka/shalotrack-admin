<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\DeviceType;
use App\Models\Supplier;
use App\Models\SupplierDelivery;
use App\Services\Delivery\ScanBatch;
use App\Services\Delivery\SupplierDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Receive a delivery from a supplier by scanning the IMEIs (devices) or ICCIDs (SIMs) on the boxes.
 * ADMIN only (the route group enforces it) and OFF until SUPPLIER_DELIVERIES_ENABLED=true: while the flag is
 * off every route answers 404 as if it did not exist. This screen RECORDS the delivery; the stock count changes
 * when that delivery is chosen on Manage Stock (ManageStockController::store), never from here.
 */
class SupplierDeliveryController extends Controller
{
    public function create(int $id)
    {
        $this->ensureEnabled();

        $supplier = Supplier::findOrFail($id);

        $deliveries = SupplierDelivery::where('supplier_id', $supplier->id)
            ->with('deviceType')
            ->withCount('units')
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        return view('admin.supplier.receive_delivery', [
            'supplier'    => $supplier,
            'deviceTypes' => DeviceType::orderBy('device_category')->orderBy('model')->get(),
            'deliveries'  => $deliveries,
            'maxScans'    => ScanBatch::MAX,
        ]);
    }

    public function store(Request $request, int $id, SupplierDeliveryService $service): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'kind'           => ['required', Rule::in([SupplierDelivery::KIND_DEVICE, SupplierDelivery::KIND_SIM])],
            'device_type_id' => ['bail', 'nullable', 'integer', 'min:1'],
            'reference'      => ['nullable', 'string', 'max:100'],
            'note'           => ['nullable', 'string', 'max:500'],
            'scans'          => ['required', 'array', 'min:1', 'max:' . ScanBatch::MAX],
            'scans.*'        => ['required', 'string', 'max:32'],
        ]);

        $adminId = $request->user()?->getAuthIdentifier();

        $result = $service->receive(
            $data['kind'],
            $id,
            isset($data['device_type_id']) ? (int) $data['device_type_id'] : null,
            $data['scans'],
            $data['reference'] ?? null,
            $data['note'] ?? null,
            $adminId !== null ? (string) $adminId : null,
        );

        return response()->json([
            'delivery_id' => $result['delivery']?->id,
            'accepted'    => count($result['accepted']),
            'rejected'    => $result['rejected'],
        ]);
    }

    /** Cancel a delivery scanned by mistake (only before it is added to stock) so its units can be rescanned. */
    public function void(Request $request, int $id, int $deliveryId, SupplierDeliveryService $service)
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        $adminId = $request->user()?->getAuthIdentifier();
        $service->void($id, $deliveryId, trim($data['reason']), $adminId !== null ? (string) $adminId : null);

        return redirect()->route('admin.suppliers.deliveries', $id)->with('success', 'Delivery #' . $deliveryId . ' was cancelled. Its units can be scanned again.');
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('supplier_deliveries.enabled'), 404);
    }
}
