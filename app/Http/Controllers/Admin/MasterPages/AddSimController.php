<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;

class AddSimController extends Controller
{
    public function index()
    {
        return view('admin.master_pages.add_sim');
    }
}