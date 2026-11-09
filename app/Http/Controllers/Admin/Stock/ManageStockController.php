<?php

namespace App\Http\Controllers\Admin\Stock;

use App\Support\Input;
use App\Http\Controllers\Controller;
use App\Models\DeviceType;
use App\Models\Stock;
use App\Services\Audit;
use App\Services\Delivery\SupplierDeliveryService;
use App\Services\Supplier\SupplierCatalogue;
use App\Models\StockTransferLedger;
use App\Models\Supplier;
use App\Imports\StockImport;
use App\Exports\StockImportTemplateExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;

class ManageStockController extends Controller
{
    public function index()
    {
        $stocks = Stock::with('deviceType')->orderBy('device_type_id')->get();
        $stockMap = $stocks->pluck('company_available_stock', 'device_type_id');
        $ledgerEntries = StockTransferLedger::with('stock.deviceType')
            ->orderByDesc('stocked_in_date')
            ->orderByDesc('id')
            ->get();
        $deviceTypes = DeviceType::orderBy('device_category')->orderBy('model')->get();
        $suppliers = Supplier::where('status', 'Active')->with('products')->orderBy('name')->get();

        // Scanned device deliveries waiting to be added to stock, grouped by supplier (empty while the feature is off).
        $openDeliveries = SupplierDeliveryService::isEnabled()
            ? app(SupplierDeliveryService::class)->openDeviceDeliveries()->map(fn ($d) => [
                'id'             => $d->id,
                'supplier_id'    => $d->supplier_id,
                'device_type_id' => $d->device_type_id,
                'name'           => trim(($d->deviceType->device_category ?? '') . ' ' . ($d->deviceType->model ?? '')),
                'units'          => $d->units_count,
                'reference'      => $d->reference,
                'date'           => $d->received_on?->format('Y-m-d'),
                'order'          => $d->purchaseOrder?->invoice_number,
            ])->groupBy('supplier_id')->map->values()
            : collect();

        return view('admin.stock.manage_stock', compact('stocks', 'stockMap', 'ledgerEntries', 'deviceTypes', 'suppliers', 'openDeliveries') + ['deliveriesOn' => SupplierDeliveryService::isEnabled()]);
    }

    public function store(Request $request, SupplierDeliveryService $deliveries)
    {
        $deliveriesOn = SupplierDeliveryService::isEnabled();

        $request->validate([
            'device_type_id' => 'bail|required|integer|min:1|exists:device_types,id',
            // Only an Active supplier (the screen lists only those); bail keeps text / huge ids away from the database.
            'supplier_id'    => ['bail', 'required', 'integer', 'min:1', \Illuminate\Validation\Rule::exists('suppliers', 'id')->where('status', 'Active')],
            // With delivery scanning on, stock comes ONLY from a scanned delivery: its quantity is the number of units
            // actually received (never a typed number), so no more can be added than the supplier really delivered.
            'delivery_id'    => $deliveriesOn ? ['required', 'integer', 'min:1'] : ['nullable', 'integer', 'min:1'],
            'stock_in'       => $deliveriesOn
                ? ['nullable', 'integer', 'min:1', 'max:1000000']
                : ['required', 'integer', 'min:1', 'max:1000000'],
        ], [
            'delivery_id.required' => 'Choose the scanned delivery to add. Stock is added from what was received (Supplier Management -> Receive delivery), so it always matches what the supplier actually delivered.',
        ]);

        $deliveryId = $deliveriesOn ? (int) $request->input('delivery_id') : null;

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $deliveries, $deliveriesOn, $deliveryId) {
            
            $deviceType = DeviceType::findOrFail((int) $request->device_type_id);
            $supplier = Supplier::findOrFail((int) $request->supplier_id);

            // Stock can only come in from a supplier that supplies this device (its product list), the same rule
            // as purchase orders and scanned deliveries.
            if (! app(SupplierCatalogue::class)->supplies($supplier->id, $deviceType->id)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'device_type_id' => SupplierCatalogue::notSuppliedMessage($supplier->name, trim("{$deviceType->device_category} {$deviceType->model}")),
                ]);
            }

            $deviceLabel = trim("{$deviceType->device_category} {$deviceType->model}");

            // Quantity: typed by hand, or (when a scanned delivery is chosen) the number of units scanned.
            $qty = (int) $request->stock_in;
            $delivery = null;
            if ($deliveriesOn) {
                // The quantity is the delivery's scanned units; whatever was typed is ignored.
                ['delivery' => $delivery, 'qty' => $qty] = $deliveries->claimForStockIn((int) $request->supplier_id, $deviceType->id, $deliveryId);
            }

            // Insert data only into the columns that exist on the table
            $stock = \App\Models\Stock::firstOrCreate(
                ['device_type_id' => $deviceType->id],
                ['company_available_stock' => 0]
            );

            // Re-read the row under a lock. Registering a device (DeviceRegistrationService) decrements this same
            // row under lockForUpdate; without the lock here two writers could read the same count and one
            // update would silently overwrite the other (lost stock units).
            $stock = \App\Models\Stock::whereKey($stock->getKey())->lockForUpdate()->firstOrFail();

            $stock->device_category_type = $deviceLabel;
            $stock->company_available_stock = ($stock->company_available_stock ?? 0) + $qty;
            $stock->updated_at = now(); 
            $stock->save();

            // The supplier keeps the product on its list: the list says what the supplier SUPPLIES, not what is on
            // order (purchase orders say that). Removing it here made the supplier "stop supplying" the device after
            // the first stock-in, which then blocked its next purchase order and delivery.

            $ledger = \App\Models\StockTransferLedger::create([
                'stock_id'              => $stock->id,
                'device_category_type'  => $deviceLabel,
                'supplier_id'           => $supplier->id,
                'supplier'              => $supplier->name,
                'stock_in'              => $qty,
                'description'           => $delivery ? trim('Scanned delivery #' . $delivery->id . ($delivery->reference ? ' (' . $delivery->reference . ')' : '')) : null,
                'stocked_in_date'       => now()->toDateString(),
            ]);

            // Same transaction as the stock increase: the delivery is now in stock and can never be added twice.
            if ($delivery) {
                $deliveries->markStocked($delivery, $ledger->id);
            }
        });

        return redirect()->back()->with('success', 'Stock was added to the system successfully!');
    }

    public function importStock(Request $request)
    {
        $request->validate([
            // 'txt' because the server reports every real .csv as text/plain; 'extensions' keeps the file NAME honest.
            'excel_file' => 'required|file|mimes:xlsx,csv,xls,txt|extensions:xlsx,csv,xls|max:10240',
        ]);

        DB::beginTransaction();

        try {
            $import = new \App\Imports\StockImport();
            \Maatwebsite\Excel\Facades\Excel::import($import, $request->file('excel_file'));

            if (method_exists($import, 'failures') && count($import->failures()) > 0) {
                // All or nothing: rows that were fine are rolled back too, so fixing the file and uploading it
                // again can never add the same stock twice.
                DB::rollBack();

                $errorMessages = ['Nothing was imported. Fix the rows below and upload the whole file again.'];
                foreach ($import->failures() as $failure) {
                    foreach ($failure->errors() as $error) {
                        $errorMessages[] = "Row {$failure->row()}: {$error}";
                    }
                }
                return redirect()->back()->withErrors($errorMessages);
            }

            DB::commit();

            return redirect()->back()->with([
                'import_success_count' => count($import->created ?? []),
                'import_total_stock_in' => $import->totalStockIn ?? 0,
                'success'              => 'Excel data was imported into the system successfully!'
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            // FIX: replaced a live dd() -- any exception during import
            // (a malformed file, a row that fails a DB constraint, etc.)
            // used to hard-crash the whole page with a raw dump instead
            // of giving the user something they can act on.
            \Log::error('Stock import failed', ['message' => $e->getMessage()]);

            // The exception text is for the log only (it can hold SQL and file paths).
            return redirect()->back()->withErrors([
                'excel_file' => 'Import failed and nothing was imported. Check the file against the template and try again.',
            ]);
        }
    }

    public function updateLedgerDescription(Request $request, StockTransferLedger $ledger)
    {
        $validated = $request->validate([
            'description' => 'nullable|string|max:1000',
        ]);
        $ledger->update(['description' => $validated['description'] ?? null]);
        return redirect()->back()->with('success', 'Description saved.');
    }

    public function destroyLedger(StockTransferLedger $ledger)
    {
        // History only: the row is soft-deleted (recoverable) and the stock count is not touched.
        $snapshot = $ledger->only(['stock_id', 'supplier', 'stock_in', 'description', 'stocked_in_date']);
        $label    = $ledger->stock_in . ' units, ' . ($ledger->supplier ?: 'no supplier') . ', ' . optional($ledger->stocked_in_date)->format('Y-m-d');
        $ledgerId = $ledger->id;

        $ledger->delete();

        Audit::record(
            'ledger.stock_deleted',
            'stock_ledger',
            $ledgerId,
            $label,
            collect($snapshot)->map(fn ($v) => ['from' => $v, 'to' => null])->all(),
        );

        return redirect()->back()->with('success', 'Ledger record removed.');
    }

    public function generateReport(Request $request)
    {
        $type = Input::choice($request->query('type'), ['stock', 'ledger'], 'stock');

        if ($type === 'stock') {
            $data = Stock::with('deviceType')->orderBy('device_type_id')->get();
            $title = 'Company Available Stock Report';
        } else {
            $data = StockTransferLedger::with('stock.deviceType')
                ->orderByDesc('stocked_in_date')
                ->orderByDesc('id')
                ->get();
            $title = 'Stock Transfer Ledger Report';
        }

        $logoPath = public_path('images/logo.png');
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $typeImg = pathinfo($logoPath, PATHINFO_EXTENSION);
            $dataImg = file_get_contents($logoPath);
            $logoBase64 = 'data:image/' . $typeImg . ';base64,' . base64_encode($dataImg);
        }

        $pdf = Pdf::loadView('admin.stock.raw_stock_report_pdf', compact('data', 'title', 'type', 'logoBase64'))
            ->setPaper('a4', $type === 'ledger' ? 'landscape' : 'portrait');

        return $pdf->stream(strtolower(str_replace(' ', '_', $title)) . '.pdf');
    }

    public function downloadImportTemplate()
    {
        return Excel::download(
            new StockImportTemplateExport(),
            'stock_import_template.xlsx'
        );
    }

    public function getSupplierProducts($id)
    {
        try {
            $supplier = \App\Models\Supplier::with('products')->find($id);
            
            if (!$supplier) {
                return response()->json(['success' => false, 'message' => 'Supplier not found']);
            }

            $productsData = [];
            foreach ($supplier->products as $product) {
                if (! $product->device_type_id) {
                    continue; // not stock-tracked (e.g. a SIM package); its id is not a device type id
                }
                $productsData[] = [
                    'id'   => $product->device_type_id,
                    'name' => $product->product_name,
                    'qty'  => $product->pivot->qty ?? 1, 
                ];
            }

            return response()->json(['success' => true, 'products' => $productsData]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('getSupplierProducts failed', ['supplier_id' => $id, 'error' => $e->getMessage()]);
            // Never return the raw exception text (can contain SQL / paths).
            return response()->json(['success' => false, 'message' => 'Could not load this supplier\'s products.'], 500);
        }
    }
}