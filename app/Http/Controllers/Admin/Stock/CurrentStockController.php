<?php

namespace App\Http\Controllers\Admin\Stock;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use Illuminate\Http\Request;

class CurrentStockController extends Controller
{
    public function index(Request $request)
    {
        // 1. Dropdowns වලට ඕන කරන අද්විතීය (Unique) Product Types සහ Names ටික Database එකෙන් ගන්නවා
        $productTypes = Stock::select('product_name') // ඔයාගේ DB එකේ කැටගරි එක සේව් වෙන්නේ product_name/model අනුව නම්
                            ->distinct()
                            ->pluck('product_name');

        // 2. Filter Logic එක ක්‍රියාත්මක කිරීම
        $query = Stock::query();

        // Product Type එකක් select කරලා search කළොත්
        if ($request->filled('product_type')) {
            $query->where('product_name', $request->product_type);
        }

        // Product Model එකක් select කරලා search කළොත්
        if ($request->filled('product_model')) {
            $query->where('product_model', $request->product_model);
        }

        // Checkboxes වල status එක අනුව (උදාහරණයක් ලෙස zero stock හෝ යම් කොන්දේසියක්)
        if ($request->has('missing_stock')) {
            $query->where('company_available_stock', 0); // Available stock එක 0 ඒවා පමණක්
        }

        $stocks = $query->latest()->get();

        // 3. View එකට දත්ත යැවීම (views/admin/stock/current_stock.blade.php)
        return view('admin.stock.current_stock', compact('stocks', 'productTypes'));
    }
}