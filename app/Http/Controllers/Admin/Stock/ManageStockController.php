<?php

namespace App\Http\Controllers\Admin\Stock;

use App\Http\Controllers\Controller;
use App\Models\DeviceType;
use App\Models\Stock;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManageStockController extends Controller
{
    public function index()
    {
        $stocks = Stock::with(['deviceType', 'supplier'])
            ->orderByDesc('sort_order')
            ->get();

        // The single newest row per device type is "current" — everything
        // else sharing that device_type_id is history and gets flagged.
        $latestIdPerType = Stock::selectRaw('MAX(id) as id')
            ->groupBy('device_type_id')
            ->pluck('id');

        $stockRows = $stocks->map(fn ($stock) => [
            'id'                      => $stock->id,
            'device_type_id'          => $stock->device_type_id,
            'supplier_id'             => $stock->supplier_id,
            'stock_in'                => $stock->stock_in,
            'company_available_stock' => $stock->company_available_stock,
            'dealer_transferred'      => $stock->dealer_transferred ?? 0, // <--- මේක අලුතින් එකතු කළා
            'description'             => $stock->description,
            'updated_at'              => optional($stock->updated_at)->format('Y-m-d H:i'),
            'is_superseded'           => ! $latestIdPerType->contains($stock->id),
        ])->values();

        $deviceTypes = DeviceType::orderBy('device_category')->orderBy('model')->get();
        $suppliers = Supplier::where('status', 'Active')->orderBy('name')->get();

        return view('admin.stock.manage_stock', compact('stockRows', 'deviceTypes', 'suppliers'));
    }

    public function store(Request $request)
{
    $validated = $request->validate([
        'device_type_id'          => 'required|exists:device_types,id',
        'supplier_id'             => 'required|exists:suppliers,id',
        'stock_in'                => 'required|integer|min:1',
        'company_available_stock' => 'required|integer|min:1',
    ]);

    DB::transaction(function () use ($validated) {

        // මේ Device + Supplier එක කලින් තියෙනවද?
        $stock = Stock::where('device_type_id', $validated['device_type_id'])
            ->where('supplier_id', $validated['supplier_id'])
            ->first();

        if ($stock) {

            // Existing row update කරනවා
            $stock->stock_in += $validated['stock_in'];

            $stock->company_available_stock += $validated['company_available_stock'];

            $stock->total_available =
                $stock->stock_in +
                $stock->company_available_stock;

            $stock->save();

        } else {

            // අලුත් row එකක් create කරනවා
            Stock::create([

                'device_type_id' => $validated['device_type_id'],

                'supplier_id' => $validated['supplier_id'],

                'stock_in' => $validated['stock_in'],

                'company_available_stock' => $validated['company_available_stock'],

                'dealer_transferred' => 0,

                'total_available' =>
                    $validated['stock_in'] +
                    $validated['company_available_stock'],

                'sort_order' =>
                    (Stock::max('sort_order') ?? 0) + 1,

            ]);

        }

    });

    return redirect()->back()->with(
        'success',
        'Stock saved successfully.'
    );
}

    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'rows'                            => 'present|array',
            'rows.*.id'                       => 'required|integer|exists:stocks,id',
            'rows.*.device_type_id'           => 'required|exists:device_types,id',
            'rows.*.supplier_id'              => 'required|exists:suppliers,id',
            'rows.*.stock_in'                 => 'required|integer',
            'rows.*.company_available_stock'  => 'required|integer',
            'rows.*.description'              => 'nullable|string|max:1000',
            'rows.*.sort_order'               => 'required|integer',
            'deleted_ids'                     => 'array',
            'deleted_ids.*'                   => 'integer|exists:stocks,id',
        ]);

        $deletedIds = $validated['deleted_ids'] ?? [];

        DB::transaction(function () use ($validated, $deletedIds) {
            if (! empty($deletedIds)) {
                Stock::whereIn('id', $deletedIds)->delete();
            }

            foreach ($validated['rows'] as $row) {
                // Guard against a row that was deleted client-side but
                // still made it into the rows[] payload somehow.
                if (in_array($row['id'], $deletedIds, true)) {
                    continue;
                }

                // මෙතන dealer_transferred එක update කරන්නේ නෑ, මොකද ඒක Transfer පිටුවෙන් විතරයි වෙනස් වෙන්නේ.
                Stock::where('id', $row['id'])->update([
                    'device_type_id'          => $row['device_type_id'],
                    'supplier_id'             => $row['supplier_id'],
                    'stock_in'                => $row['stock_in'],
                    'company_available_stock' => $row['company_available_stock'],
                    'total_available'         => $row['stock_in'] + $row['company_available_stock'],
                    'description'             => $row['description'] ?? null,
                    'sort_order'              => $row['sort_order'],
                ]);
            }
        });

        return redirect()->back()->with('success', 'Stock records updated successfully!');
    }
}