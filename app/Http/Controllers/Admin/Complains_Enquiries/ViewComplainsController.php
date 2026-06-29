<?php

namespace App\Http\Controllers\Admin\Complains_Enquiries;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ViewComplainsController extends Controller
{
    public function index()
    {
        return view('admin.Complains_Enquiries.view_complains');
    }
}