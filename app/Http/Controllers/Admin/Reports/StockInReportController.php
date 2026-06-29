<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StockInReportController extends Controller
{
    public function index()
    {
        return view('admin.reports.stock_in_report');
    }
}