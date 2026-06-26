<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;

class ProductController extends Controller
{
    public function index()
    {
        return view('admin.master_pages.products');
    }
}