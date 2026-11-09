<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\DeviceType;
use App\Models\Feature;
use App\Models\Stock;
use App\Imports\DeviceTypesImport;
use App\Exports\DeviceTypeImportTemplateExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class AddDeviceTypeController extends Controller
{
    public function index()
    {
        $deviceTypes = DeviceType::latest()->get();
        $features = Feature::orderBy('name')->get();

        // How each device type is linked to the rest of the system, so a missing link is visible here:
        // which suppliers supply it, how much is in company stock, and whether it has an approved price.
        $suppliersByType = DB::table('supplier_products')
            ->join('products', 'products.id', '=', 'supplier_products.product_id')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_products.supplier_id')
            ->whereNotNull('products.device_type_id')
            ->orderBy('suppliers.name')
            ->get(['products.device_type_id', 'suppliers.name'])
            ->groupBy('device_type_id')
            ->map(fn ($rows) => $rows->pluck('name')->unique()->values());
        $stockByType = Stock::pluck('company_available_stock', 'device_type_id');
        $pricedTypes = Schema::hasTable('device_prices')
            ? DB::table('device_prices')->pluck('device_type_id')->flip()
            : collect();

        return view('admin.master_pages.add_device_type', compact('deviceTypes', 'features', 'suppliersByType', 'stockByType', 'pricedTypes'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_category' => ['required', 'string', 'max:255'],
            'model'           => 'required|string|in:Basic,Plus,Customize',
            'protocol'        => 'required|string|max:255',
            'features'        => 'nullable|array|max:100',
            'features.*'      => ['bail', 'integer', 'min:1', 'exists:features,id'],
        ]);

        $category = trim(preg_replace('/\s+/', ' ', $validated['device_category']));

        // "Shalotrack V5" and "shalotrack  v5 " are the same device: transfers, invoices and prices find a device
        // type by its name, so two types that differ only in capitals or spaces could never be told apart.
        if (self::nameTaken($category, $validated['model'])) {
            return back()->withInput()->withErrors(['device_category' => 'This exact Device Category + Model combination already exists.']);
        }

        DB::transaction(function () use ($validated, $category) {
            $type = DeviceType::create([
                'device_category' => $category,
                'model'           => $validated['model'],
                'protocol'        => trim($validated['protocol']),
                // Stored as strings, the same as the Excel import, so every reader sees one format.
                'features'        => array_map('strval', $validated['features'] ?? []),
            ]);

            // Its company stock row starts at 0, so the new device shows on Manage Stock straight away.
            Stock::firstOrCreate(
                ['device_type_id' => $type->id],
                ['company_available_stock' => 0, 'device_category_type' => trim($type->device_category . ' ' . $type->model)]
            );
        });

        return redirect()
            ->back()
            ->with('success', 'Device Type added. Next: put it on a supplier\'s product list (Supplier Management) and set its price (Device Prices).');
    }

    /** True when a device type with this category and model already exists, ignoring capitals and extra spaces. */
    public static function nameTaken(string $category, string $model): bool
    {
        $category = mb_strtolower(trim(preg_replace('/\s+/', ' ', $category)));
        $model = mb_strtolower(trim($model));

        return DeviceType::query()->get(['device_category', 'model'])->contains(
            fn ($t) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $t->device_category))) === $category
                && mb_strtolower(trim($t->model)) === $model
        );
    }

    public function storeFeature(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:features,name',
        ], [
            'name.unique' => 'This feature already exists.',
        ]);

        Feature::create($validated);

        return redirect()->back()->with('success', 'Feature added successfully!');
    }

    /**
     * Serves a blank .xlsx with the exact columns DeviceTypesImport
     * expects, plus one worked example row.
     */
    public function downloadImportTemplate()
    {
        return Excel::download(
            new DeviceTypeImportTemplateExport(),
            'device_type_import_template.xlsx'
        );
    }

    /**
     * Bulk version of store() — same category+model uniqueness rule, same
     * model:in:Basic,Plus,Customize restriction, same features handling.
     * One bad row (duplicate combo, unknown feature name) is skipped and
     * reported, not fatal to the rest of the file
     */
    public function importDeviceTypes(Request $request)
    {
        $request->validate([
            // 'txt' is needed because the server reports every real .csv file as text/plain; 'extensions' then makes sure the file NAME is still .xlsx or .csv.
            'excel_file' => 'required|file|mimes:xlsx,csv,txt|extensions:xlsx,csv|max:10240',
        ]);

        $import = new DeviceTypesImport();

        Excel::import($import, $request->file('excel_file'));

        return redirect()
            ->back()
            ->with([
                'import_success_count' => count($import->created),
                'import_failures'      => $import->failures(),
            ]);
    }
}