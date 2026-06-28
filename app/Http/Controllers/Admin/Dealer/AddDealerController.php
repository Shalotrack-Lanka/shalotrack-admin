<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AddDealerController extends Controller
{
    public function index()
    {
        return view('admin.dealer.add_dealer');
    }
}