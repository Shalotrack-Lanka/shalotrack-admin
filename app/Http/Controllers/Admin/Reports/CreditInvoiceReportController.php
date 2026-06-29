<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CreditInvoiceReportController extends Controller
{
    public function index()
    {
        return view('admin.reports.credit_invoice_report');
    }
}