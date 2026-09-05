<?php

namespace App\Http\Controllers\Admin\Stock;

use App\Http\Controllers\Controller;
use App\Models\DeviceType;
use App\Models\Stock;
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

        // device_type_id => current Company Available Stock, so the "Add Raw
        // Devices" form can show a live Total Available preview before the
        // stock is actually saved.
        $stockMap = $stocks->pluck('company_available_stock', 'device_type_id');

        $ledgerEntries = StockTransferLedger::with('stock.deviceType')
            ->orderByDesc('stocked_in_date')
            ->orderByDesc('id')
            ->get();

        $deviceTypes = DeviceType::orderBy('device_category')->orderBy('model')->get();
        $suppliers = Supplier::where('status', 'Active')->orderBy('name')->get();

        return view('admin.stock.manage_stock', compact('stocks', 'stockMap', 'ledgerEntries', 'deviceTypes', 'suppliers'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_type_id' => 'required|exists:device_types,id',
            'supplier_id'    => 'required|exists:suppliers,id',
            'stock_in'       => 'required|integer|min:1',
        ]);

        DB::transaction(function () use ($validated) {
            $deviceType = DeviceType::findOrFail($validated['device_type_id']);
            $deviceLabel = "{$deviceType->device_category} with {$deviceType->model}";

            $stock = Stock::firstOrCreate(
                ['device_type_id' => $validated['device_type_id']],
                ['company_available_stock' => 0]
            );

            $stock->device_category_type = $deviceLabel;
            $stock->company_available_stock += $validated['stock_in'];
            $stock->save();

            $supplier = Supplier::findOrFail($validated['supplier_id']);

            StockTransferLedger::create([
                'stock_id'              => $stock->id,
                'device_category_type'  => $deviceLabel,
                'supplier_id'           => $supplier->id,
                'supplier'              => $supplier->name,
                'stock_in'              => $validated['stock_in'],
                'stocked_in_date'       => now()->toDateString(),
            ]);
        });

        return redirect()->back()->with('success', 'Stock saved successfully.');
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
        $ledger->delete();

        return redirect()->back()->with('success', 'Ledger record removed.');
    }

    public function generateReport(Request $request)
    {
        $type = $request->query('type', 'stock');

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

        // convert logo to base64 to embed in PDF
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

    /**
     * Serves a blank .xlsx with the exact columns StockImport expects,
     * plus one worked example row.
     */
    public function downloadImportTemplate()
    {
        return Excel::download(
            new StockImportTemplateExport(),
            'stock_import_template.xlsx'
        );
    }

    /**
     * Bulk version of store() — one row per stock-in entry, each creating
     * its own StockTransferLedger record exactly like a single submission
     * would. A row for a device type/supplier that doesn't exist is
     * skipped and reported, not fatal to the rest of the file.
     */
    public function importStock(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,csv|max:10240',
        ]);

        $import = new StockImport();

        Excel::import($import, $request->file('excel_file'));

        return redirect()
            ->back()
            ->with([
                'import_success_count' => count($import->created),
                'import_total_stock_in' => $import->totalStockIn,
                'import_failures'      => $import->failures(),
                'import_errors'        => $import->errors(),
            ]);
    }
}