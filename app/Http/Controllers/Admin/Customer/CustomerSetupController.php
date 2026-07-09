<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;

class CustomerSetupController extends Controller
{
    public function index()
    {
        return view('admin.customer.customer_setup');
    }
}