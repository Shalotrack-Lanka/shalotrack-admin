<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DealerLedgerController extends Controller
{
    public function index()
    {
        return view('admin.dealer.dealer_ledger');
    }
}