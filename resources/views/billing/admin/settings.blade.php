@extends('layouts.admin')
@section('title', 'Billing settings')
@section('content')
<div class="space-y-5 max-w-4xl">
    @include('billing.admin._nav', ['heading' => 'Billing settings', 'sub' => 'Company details printed on every invoice, tax, payment terms and the approval switch. Every change is recorded in the audit log.'])
    <form method="POST" action="{{ route('admin.billing.settings.update') }}" class="space-y-5">
        @csrf @method('PUT')
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 grid md:grid-cols-2 gap-4">
            <div class="md:col-span-2"><h2 class="font-black text-blue-950">Company (the seller on every invoice)</h2></div>
            <div><label class="text-[11px] font-black uppercase text-slate-500">Registered legal name *</label><input name="legal_name" required maxlength="200" value="{{ old('legal_name', $company->legal_name ?? '') }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
            <div><label class="text-[11px] font-black uppercase text-slate-500">Tax / VAT / registration number</label><input name="tax_number" maxlength="60" value="{{ old('tax_number', $company->tax_number ?? '') }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
            <div class="md:col-span-2"><label class="text-[11px] font-black uppercase text-slate-500">Address *</label><textarea name="address" required rows="2" maxlength="500" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2">{{ old('address', $company->address ?? '') }}</textarea></div>
            <div><label class="text-[11px] font-black uppercase text-slate-500">Phone</label><input name="phone" maxlength="40" value="{{ old('phone', $company->phone ?? '') }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
            <div><label class="text-[11px] font-black uppercase text-slate-500">Email</label><input type="email" name="email" maxlength="150" value="{{ old('email', $company->email ?? '') }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
            <div class="md:col-span-2"><label class="text-[11px] font-black uppercase text-slate-500">Bank details printed on invoices (bank, branch, account name and number)</label><textarea name="bank_details" rows="3" maxlength="1000" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2">{{ old('bank_details', $company->bank_details ?? '') }}</textarea></div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 grid md:grid-cols-2 gap-4">
            <div class="md:col-span-2"><h2 class="font-black text-blue-950">Tax</h2><p class="text-xs text-slate-500">Leave off until your accountant confirms the rate. The rate used is frozen on each invoice when it is issued.</p></div>
            <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="tax_enabled" value="1" @checked(old('tax_enabled', $s['tax_enabled']) == '1') class="rounded"> Add tax to invoices</label>
            <div class="grid grid-cols-2 gap-3"><div><label class="text-[11px] font-black uppercase text-slate-500">Label</label><input name="tax_label" maxlength="30" value="{{ old('tax_label', $s['tax_label']) }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
                <div><label class="text-[11px] font-black uppercase text-slate-500">Rate %</label><input name="tax_rate" inputmode="decimal" value="{{ old('tax_rate', $s['tax_rate']) }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2">@error('tax_rate')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror</div></div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 grid md:grid-cols-2 gap-4">
            <div class="md:col-span-2"><h2 class="font-black text-blue-950">Terms and controls</h2></div>
            <div><label class="text-[11px] font-black uppercase text-slate-500">Payment due after (days)</label><input type="number" name="payment_terms_days" min="0" max="365" required value="{{ old('payment_terms_days', $s['payment_terms_days']) }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="approval_required" value="1" @checked(old('approval_required', $s['approval_required']) == '1') class="rounded mt-1"><span><b>Payments need a second person's approval</b><br><span class="text-xs text-slate-500">Turn on when the Finance role joins: the person who records a payment can never approve it.</span></span></label>
            <div class="md:col-span-2"><label class="text-[11px] font-black uppercase text-slate-500">Footer text on invoices</label><input name="invoice_footer" maxlength="500" value="{{ old('invoice_footer', $s['invoice_footer']) }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
        </div>
        @if($errors->any() && ! $errors->has('tax_rate'))<div class="bg-red-50 text-red-800 text-sm rounded-xl p-3">{{ $errors->first() }}</div>@endif
        <button class="px-6 py-2.5 rounded-xl bg-blue-950 text-white font-black text-sm">Save settings</button>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-3">
        <h2 class="font-black text-blue-950">Integrity check</h2>
        <p class="text-xs text-slate-500">Re-computes the tamper-proof chain over every invoice, credit note and payment. Finds any document that was changed, removed or numbered out of order.</p>
        <form method="POST" action="{{ route('admin.billing.verify') }}">@csrf<button class="px-4 py-2 rounded-xl bg-slate-100 text-slate-800 text-xs font-bold">Run integrity check</button></form>
        @if($verify)
            @if(empty($verify['problems']))<p class="text-sm text-emerald-700 font-bold">All documents are intact (checked {{ $verify['at'] }}).</p>
            @else<div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-xl p-3"><p class="font-black">{{ count($verify['problems']) }} problem(s) found:</p><ul class="list-disc ml-5 mt-1">@foreach($verify['problems'] as $p)<li>{{ $p }}</li>@endforeach</ul></div>@endif
        @endif
    </div>
</div>
@endsection
