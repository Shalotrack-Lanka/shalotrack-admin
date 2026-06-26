<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;

class FeatureController extends Controller
{
    public function index()
    {
        return view('admin.master_pages.features');
    }
}