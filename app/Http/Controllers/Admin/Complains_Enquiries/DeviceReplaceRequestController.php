<?php

namespace App\Http\Controllers\Admin\Complains_Enquiries;

use App\Http\Controllers\Controller;

class DeviceReplaceRequestController extends Controller
{
    public function index()
    {
        return view('admin.Complains_Enquiries.device_replace_request');
    }
}