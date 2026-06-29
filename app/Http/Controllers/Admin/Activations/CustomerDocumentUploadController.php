<?php

namespace App\Http\Controllers\Admin\Activations;

use App\Http\Controllers\Controller;

class CustomerDocumentUploadController extends Controller
{
    public function index()
    {
        return view('admin.activations.customer_document_upload');
    }
}