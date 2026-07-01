<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;

class SupplierInvoiceController extends Controller
{
    public function index()
    {
        return view('admin.supplier.supplier_invoice');
    }
}