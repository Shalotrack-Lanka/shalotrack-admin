<?php

namespace App\Http\Controllers\Admin\AdminPanel;

use App\Http\Controllers\Controller;
use App\Models\DeviceType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AddDeviceTypeController extends Controller
{
    public function index()
    {
        $deviceTypes = DeviceType::latest()->get();

        return view('admin.admin_panel.add_device_type', compact('deviceTypes'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_category' => [
                'required',
                'string',
                'max:255',
                Rule::unique('device_types', 'device_category')
                    ->where(fn ($query) => $query->where('model', $request->model)),
            ],
            'model'    => 'required|string|max:255',
            'protocol' => 'required|string|max:255',
        ], [
            'device_category.unique' => 'This exact Device Category + Model combination already exists.',
        ]);

        DeviceType::create($validated);

        return redirect()->back()->with('success', 'Device Type added successfully!');
    }
}