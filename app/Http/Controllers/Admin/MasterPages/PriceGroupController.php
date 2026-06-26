<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PriceGroupController extends Controller
{
    public function index()
    {
        return view('admin.master_pages.price_group');
    }
}