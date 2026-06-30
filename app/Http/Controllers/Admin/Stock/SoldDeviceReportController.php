<?php

namespace App\Http\Controllers\Admin\Stock;

use App\Http\Controllers\Controller;

class SoldDeviceReportController extends Controller
{
    public function index()
    {
        return view('admin.stock.sold_device_report');
    }
}