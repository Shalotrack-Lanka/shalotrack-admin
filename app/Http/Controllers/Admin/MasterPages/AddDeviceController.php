<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\DeviceType;

class AddDeviceController extends Controller
{
    public function index()
    {
        $deviceTypes = DeviceType::orderBy('device_category')
            ->orderBy('model')
            ->get();

        return view('admin.master_pages.add_device', compact('deviceTypes'));
    }
}