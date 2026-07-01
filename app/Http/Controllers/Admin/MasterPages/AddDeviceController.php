<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;

class AddDeviceController extends Controller
{
    public function index()
    {
        return view('admin.master_pages.add_device');
    }
}