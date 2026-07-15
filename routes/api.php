<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// All routes here automatically start with http://127.0.0.1:8000/api/...

Route::middleware(['auth.firebase'])->group(function () {
    
    // Test endpoint to verify the token and return the CustomerAd model
    Route::get('/Customers/me', function (Request $request) {
        return response()->json([
            'status' => 'success',
            'customer' => $request->user() 
        ]);
    });

    // You will add the rest of your GpsTracking endpoints here later...
});