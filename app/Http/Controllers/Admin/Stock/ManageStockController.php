<?php

namespace App\Http\Controllers\Admin\Stock; // නිවැරදි Namespace එක

use App\Http\Controllers\Controller; // Controller Base එක extend කිරීමට
use App\Models\Stock;
use Illuminate\Http\Request;

class ManageStockController extends Controller
{
    // 1. Stock Summary පිටුව සහ Table එකට දත්ත පෙන්වීම
    public function index()
    {
        $stocks = Stock::latest()->get();
        // ඔයාගේ views වල structure එක අනුව admin/stock/manage-stock.blade.php ලෙස view එකක් තිබිය යුතුයි
        return view('admin.stock.manage_stock', compact('stocks'));
    }

    // 2. අලුත් Stock එකක් Database එකට Save කිරීම
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_name' => 'required|string|max:255',
            'product_model' => 'required|string|max:255',
            'stock_in' => 'required|integer|min:0',
            'company_available_stock' => 'required|integer|min:0',
            'dealer_available_stock' => 'required|integer|min:0',
            'sold_to_customer' => 'required|integer|min:0',
        ]);

        Stock::create($validated);

        return redirect()->back()->with('success', 'Stock Record Added Successfully!');
    }

    // 3. දැනට තියෙන Stock එකක් Edit කර Update කිරීම
    public function update(Request $request, Stock $stock)
    {
        $validated = $request->validate([
            'product_name' => 'required|string|max:255',
            'product_model' => 'required|string|max:255',
            'stock_in' => 'required|integer|min:0',
            'company_available_stock' => 'required|integer|min:0',
            'dealer_available_stock' => 'required|integer|min:0',
            'sold_to_customer' => 'required|integer|min:0',
        ]);

        $stock->update($validated);

        return redirect()->back()->with('success', 'Stock Record Updated Successfully!');
    }

    // 4. Excel/CSV විදිහට Data Download කිරීමේ Action එක
    public function download(Stock $stock)
    {
        $fileName = "Stock_" . str_replace(' ', '_', $stock->product_name) . ".csv";
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $callback = function() use($stock) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Product Name', 'Product Model', 'Stock In', 'Company Available', 'Dealer Available', 'Sold To Customer']);
            fputcsv($file, [$stock->product_name, $stock->product_model, $stock->stock_in, $stock->company_available_stock, $stock->dealer_available_stock, $stock->sold_to_customer]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}