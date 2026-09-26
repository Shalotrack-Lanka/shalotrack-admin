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
        $request->validate([
            'device_type_id' => 'required',
            'supplier_id'    => 'required|exists:suppliers,id',
            'stock_in'       => 'required|integer|min:1',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            
            $inputId = $request->device_type_id;
            $deviceType = \App\Models\DeviceType::find($inputId);

            if (!$deviceType) {
                $product = \App\Models\Product::find($inputId);
                if ($product && $product->device_type_id) {
                    $deviceType = \App\Models\DeviceType::find($product->device_type_id);
                }
            }

            if (!$deviceType) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'device_type_id' => 'මෙම භාණ්ඩයට අදාළ Device Type එකක් සම්බන්ධ කර නොමැත!'
                ]);
            }

            $deviceLabel = trim("{$deviceType->device_category} {$deviceType->model}");

            // මෙතැනයි වෙනස: Table එකේ තියෙන Columns වලට විතරක් දත්ත දැමීම
            $stock = \App\Models\Stock::firstOrCreate(
                ['device_type_id' => $deviceType->id],
                ['company_available_stock' => 0]
            );

            $stock->device_category_type = $deviceLabel;
            $stock->company_available_stock = ($stock->company_available_stock ?? 0) + $request->stock_in;
            $stock->updated_at = now(); 
            $stock->save();

            // අනෙකුත් සියලුම විස්තර Ledger එකට දැමීම
            $supplier = \App\Models\Supplier::findOrFail($request->supplier_id);

            \App\Models\StockTransferLedger::create([
                'stock_id'              => $stock->id,
                'device_category_type'  => $deviceLabel,
                'supplier_id'           => $supplier->id,
                'supplier'              => $supplier->name,
                'stock_in'              => $request->stock_in,
                'stocked_in_date'       => now()->toDateString(),
            ]);
        });

        return redirect()->back()->with('success', 'Stock saved successfully and Date updated!');
    }

    public function importStock(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,csv,xls|max:10240',
        ]);

        try {
            $import = new \App\Imports\StockImport();
            \Maatwebsite\Excel\Facades\Excel::import($import, $request->file('excel_file'));

            if (method_exists($import, 'failures') && count($import->failures()) > 0) {
                $errorMessages = [];
                foreach ($import->failures() as $failure) {
                    foreach ($failure->errors() as $error) {
                        $errorMessages[] = "Row {$failure->row()}: {$error}";
                    }
                }
                return redirect()->back()->withErrors($errorMessages);
            }

            return redirect()->back()->with([
                'import_success_count' => count($import->created ?? []),
                'import_total_stock_in' => $import->totalStockIn ?? 0,
                'success'              => 'Excel දත්ත සාර්ථකව පද්ධතියට එකතු කරන ලදී!'
            ]);

        } catch (\Exception $e) {
            dd($e->getMessage());
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
                $productsData[] = [
                    'id'   => $product->device_type_id ? $product->device_type_id : $product->id,
                    'name' => $product->product_name,
                    'qty'  => $product->pivot->qty ?? 1, 
                ];
            }

            return response()->json(['success' => true, 'products' => $productsData]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}