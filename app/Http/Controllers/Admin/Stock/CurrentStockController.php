<?php

namespace App\Http\Controllers\Admin\Stock;

use App\Http\Controllers\Controller;

class CurrentStockController extends Controller
{
    public function index()
    {
        return view('admin.stock.current_stock');
    }
}