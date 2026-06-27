<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;

class AddProductPoController extends Controller
{
    public function index()
    {
        return view('admin.supplier.add_product_po');
    }
}