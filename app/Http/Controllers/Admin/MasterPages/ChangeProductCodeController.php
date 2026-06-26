<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ChangeProductCodeController extends Controller
{
    public function index()
    {
        return view('admin.master_pages.change_product_code');
    }
}