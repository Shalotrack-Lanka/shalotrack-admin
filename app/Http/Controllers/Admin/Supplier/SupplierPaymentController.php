<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\Supplier\SupplierPayments;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Record (and void) what has been paid to a supplier against a purchase order. ADMIN only (the route group
 * enforces it). The rules (no overpaying, no payment on a cancelled order, void instead of delete) live in
 * App\Services\Supplier\SupplierPayments.
 */
class SupplierPaymentController extends Controller
{
    public function store(Request $request, int $id, SupplierPayments $payments)
    {
        abort_unless(SupplierPayments::ready(), 404);
        $supplier = Supplier::findOrFail($id);

        $data = $request->validate([
            'supplier_invoice_id' => ['bail', 'required', 'integer', 'min:1', Rule::exists('supplier_invoices', 'id')->where('supplier_id', $supplier->id)],
            // Text or a huge number is refused here (bail), before it can reach the database.
            'amount'              => ['bail', 'required', 'numeric', 'min:0.01', 'max:9999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'paid_on'             => ['required', 'date_format:Y-m-d'],
            'method'              => ['required', Rule::in(array_keys(SupplierPayments::METHODS))],
            'reference'           => ['nullable', 'string', 'max:100'],
            'note'                => ['nullable', 'string', 'max:500'],
        ], [
            'supplier_invoice_id.exists' => 'Choose one of this supplier\'s purchase orders.',
            'amount.regex'               => 'The amount can have at most two decimals.',
        ]);

        $adminId = $request->user()?->getAuthIdentifier();

        $payment = $payments->record(
            (int) $data['supplier_invoice_id'],
            $data['amount'],
            $data['paid_on'],
            $data['method'],
            $data['reference'] ?? null,
            $data['note'] ?? null,
            $adminId !== null ? (string) $adminId : null,
        );

        return redirect()->route('admin.supplier.profile.show', $supplier->id)
            ->with('success', 'Payment of Rs. ' . number_format((float) $payment->amount, 2) . ' recorded.');
    }

    public function void(Request $request, int $id, int $paymentId, SupplierPayments $payments)
    {
        abort_unless(SupplierPayments::ready(), 404);

        $payment = SupplierPayment::where('supplier_id', $id)->findOrFail($paymentId);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        $adminId = $request->user()?->getAuthIdentifier();
        $payments->void($payment->id, trim($data['reason']), $adminId !== null ? (string) $adminId : null);

        return redirect()->route('admin.supplier.profile.show', $id)->with('success', 'Payment voided. It stays on record with your reason.');
    }
}
