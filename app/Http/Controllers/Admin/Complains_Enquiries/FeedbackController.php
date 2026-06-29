<?php

namespace App\Http\Controllers\Admin\Complains_Enquiries;

use App\Http\Controllers\Controller;

class FeedbackController extends Controller
{
    public function index()
    {
        return view('admin.Complains_Enquiries.feedback');
    }
}