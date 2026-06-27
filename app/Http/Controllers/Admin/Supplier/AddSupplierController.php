<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;

class AddSupplierController extends Controller
{
    public function index()
    {
        return view('admin.supplier.add_supplier');
    }
}