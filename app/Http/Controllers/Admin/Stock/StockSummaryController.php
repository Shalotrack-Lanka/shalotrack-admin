<?php

namespace App\Http\Controllers\Admin\Stock;

use App\Http\Controllers\Controller;

class StockSummaryController extends Controller
{
    public function index()
    {
        return view('admin.stock.stock_summary');
    }
}