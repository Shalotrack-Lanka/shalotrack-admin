<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\StockTransferLedger;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\Supplier\PurchaseOrders;
use App\Services\Supplier\SupplierPayments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SupplierProfileController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN-FACING: view a specific supplier's full profile by ID.
    // ─────────────────────────────────────────────────────────────────────────

    public function showForAdmin(int $id)
    {
        $supplier = Supplier::with('products')->findOrFail($id);

        $stockHistory = StockTransferLedger::with('stock.deviceType')
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('created_at')
            ->get();

        $invoices = SupplierInvoice::where('supplier_id', $supplier->id)
            ->with('items.product')
            ->latest('invoice_date')
            ->latest('id')
            ->get();

        // Units actually received = what the stock-in ledger recorded. (The supplier's product qty is what is still
        // ON ORDER, so summing it showed 0 after everything had been received.)
        $totalStockReceived = (int) $stockHistory->sum('stock_in');

        // A cancelled purchase order is kept for the record but is not money owed to the supplier.
        $live = $invoices->reject(fn ($i) => $i->isCancelled());
        $totalInvoiceValue  = $live->sum('grand_total');
        $totalInvoices      = $live->count();

        $orders = app(PurchaseOrders::class);
        $orderSummaries = $invoices->mapWithKeys(fn ($i) => [$i->id => $orders->summary($i)]);
        $progress = $orders->progressByProduct((int) $supplier->id);

        // What has been paid: per order, and what is still owed to this supplier overall.
        $paymentsReady = SupplierPayments::ready();
        $money = app(SupplierPayments::class)->forOrders($invoices);
        $orderMoney = $money['orders'];
        $owedCents = $money['owed_cents'];
        $paidCents = $money['paid_cents'];
        $paymentList = $paymentsReady
            ? SupplierPayment::where('supplier_id', $supplier->id)->with('purchaseOrder')->orderByDesc('paid_on')->orderByDesc('id')->get()
            : collect();

        $recentActivity = $stockHistory->take(5)->map(function ($entry) {
            return [
                'date' => $entry->created_at,
                'text' => "Received {$entry->stock_in} units of {$entry->device_category_type}",
            ];
        })->values();

        return view('admin.supplier.supplier_profile_admin', compact(
            'supplier',
            'stockHistory',
            'invoices',
            'totalStockReceived',
            'totalInvoiceValue',
            'totalInvoices',
            'recentActivity',
            'orderSummaries',
            'progress',
            'paymentsReady',
            'orderMoney',
            'owedCents',
            'paidCents',
            'paymentList'
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SUPPLIER-FACING: the logged-in supplier edits their OWN profile.
    // ─────────────────────────────────────────────────────────────────────────

    public function edit()
    {
        $admin    = auth()->user();
        $supplier = $admin->supplier; 

        return view('admin.supplier.profile', compact('admin', 'supplier'));
    }

    public function update(Request $request)
    {
        $admin    = auth()->user();
        $supplier = $admin->supplier;

        if (!$supplier) {
            return back()->withErrors([
                'supplier' => "Your account isn't linked to a supplier record — contact an administrator.",
            ]);
        }

        $validated = $request->validate([
            'full_name' => 'required|string|max:255', 
            'name'      => 'required|string|max:255', 
            'phone_number' => [
                'nullable',
                'regex:/^[+]?[0-9\s\-\(\)]{7,20}$/',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('suppliers', 'email')->ignore($supplier->id),
            ],
            // Free text on purpose (not the 'url' rule): suppliers enter websites without http:// too.
            'website'      => 'nullable|string|max:255',
            'address'      => 'nullable|string|max:500',
            'country'      => 'nullable|string|max:100',
            'state'        => 'nullable|string|max:100',
            'gstin_number' => 'nullable|string|max:50',
        ], [
            'phone_number.regex' => 'Phone number must contain only digits, spaces, hyphens, parentheses, or a leading +.',
            'email.unique'       => 'This email address is already registered to another supplier.',
        ]);

        $supplier->update([
            'name'         => $validated['name'],
            'email'        => $validated['email'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'website'      => $validated['website'] ?? null,
            'address'      => $validated['address'] ?? null,
            'country'      => $validated['country'] ?? null,
            'state'        => $validated['state'] ?? null,
            'gstin_number' => $validated['gstin_number'] ?? null,
        ]);

        $admin->update([
            'full_name' => $validated['full_name'],
        ]);

        if ($request->filled('new_password')) {
            $request->validate([
                'current_password' => 'required|string|max:255',
                'new_password'     => ['required', 'string', 'max:' . \App\Support\PasswordPolicy::MAX_LENGTH, 'confirmed', \App\Support\PasswordPolicy::rule()],
            ], [
                'new_password.min' => 'New password must be at least 8 characters.',
            ]);

            if (!Hash::check($request->current_password, $admin->password)) {
                return back()
                    ->withErrors(['current_password' => 'Your current password is incorrect.'])
                    ->withInput();
            }

            $admin->update([
                'password' => Hash::make($request->new_password),
            ]);
        }

        return back()->with('success', 'Profile updated successfully.');
    }
}