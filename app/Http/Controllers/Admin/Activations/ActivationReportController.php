<?php

namespace App\Http\Controllers\Admin\Activations;

use App\Http\Controllers\Controller;

class ActivationReportController extends Controller
{
    public function index()
    {
        return view('admin.activations.activation_report');
    }
}