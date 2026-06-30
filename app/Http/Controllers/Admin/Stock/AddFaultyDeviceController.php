<?php

namespace App\Http\Controllers\Admin\Stock;

use App\Http\Controllers\Controller;

class AddFaultyDeviceController extends Controller
{
    public function index()
    {
        return view('admin.stock.add_faulty_device');
    }
}