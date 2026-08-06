<?php

namespace App\Http\Controllers\Admin\AdminPanel;

use App\Http\Controllers\Controller;
use App\Models\DeviceType;
use App\Models\Feature;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
}