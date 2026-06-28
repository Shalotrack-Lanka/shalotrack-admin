<?php

namespace App\Http\Controllers\Admin\Complains_Enquiries;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TroubleshootController extends Controller
{
    public function index()
    {
        return view('admin.complains_Enquiries.troubleshoot');
    }
}