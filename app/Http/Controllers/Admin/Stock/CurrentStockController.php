<?php

namespace App\Http\Controllers\Admin\Stock;

use App\Http\Controllers\Controller;
use App\Models\DeviceType;
use App\Models\Stock;
use Illuminate\Http\Request;

class CurrentStockController extends Controller
{
    public function index(Request $request)
    {
        $deviceTypes = DeviceType::orderBy('device_category')->orderBy('model')->get();

        $query = Stock::with('deviceType');

        if ($request->filled('device_type_id')) {
            $query->where('device_type_id', $request->device_type_id);
        }

        if ($request->has('missing_stock')) {
            $query->where('company_available_stock', 0);
        }

        $stocks = $query->latest()->get();

        return view('admin.stock.current_stock', compact('stocks', 'deviceTypes'));
    }
}