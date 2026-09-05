<?php

namespace App\Http\Controllers\Admin\AdminPanel;

use App\Http\Controllers\Controller;
use App\Models\DeviceType;
use App\Models\Feature;
use App\Imports\DeviceTypesImport;
use App\Exports\DeviceTypeImportTemplateExport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class AddDeviceTypeController extends Controller
{
    public function index()
    {
        $deviceTypes = DeviceType::latest()->get();
        $features = Feature::orderBy('name')->get();

        return view('admin.admin_panel.add_device_type', compact('deviceTypes', 'features'));
    }

    public function store(Request $request)
{
    $validated = $request->validate([
        'device_category' => [
            'required',
            'string',
            'max:255',

            Rule::unique('device_types', 'device_category')
                ->where(
                    fn ($query) =>
                    $query->where('model', $request->model)
                ),
        ],

        'model' => 'required|string|in:Basic,Plus,Customize',

        'protocol' => 'required|string|max:255',

        'features' => 'nullable|array',

        'features.*' => 'exists:features,id',

    ], [

        'device_category.unique' =>
            'This exact Device Category + Model combination already exists.',

    ]);

    DeviceType::create([

        'device_category' =>
            $validated['device_category'],

        'model' =>
            $validated['model'],

        'protocol' =>
            $validated['protocol'],

        'features' =>
            $validated['features'] ?? [],

    ]);

    return redirect()
        ->back()
        ->with(
            'success',
            'Device Type added successfully!'
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
     * reported, not fatal to the rest of the file.
     */
    public function importDeviceTypes(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,csv|max:10240',
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