@php
    $tabs = [
        ['Overview', route('admin.billing.home'), request()->routeIs('admin.billing.home', 'admin.billing.statement')],
        ['Invoices', route('admin.billing.invoices.index'), request()->routeIs('admin.billing.invoices.*', 'admin.billing.credit-notes.*')],
        ['Payments', route('admin.billing.receipts.index'), request()->routeIs('admin.billing.receipts.*')],
        ['Settings', route('admin.billing.settings'), request()->routeIs('admin.billing.settings')],
    ];
@endphp
<div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
    <div>
        <h1 class="text-2xl font-black text-blue-950">{{ $heading ?? 'Billing' }}</h1>
        <p class="text-xs text-slate-500 mt-0.5">{{ $sub ?? 'What distributors and dealers owe the company for devices, and what has been paid.' }}</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        @can('billing.invoice.issue')
            <a href="{{ route('admin.billing.invoices.new') }}" class="px-4 py-2 rounded-xl bg-blue-600 text-white text-xs font-bold hover:bg-blue-700">+ New invoice</a>
        @endcan
        @can('billing.receipt.record')
            <a href="{{ route('admin.billing.receipts.new') }}" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700">+ Record payment</a>
        @endcan
    </div>
</div>
<div class="flex flex-wrap gap-1 bg-slate-100 p-1 rounded-xl w-fit">
    @foreach($tabs as [$label, $url, $on])
        <a href="{{ $url }}" class="px-4 py-1.5 rounded-lg text-xs font-bold {{ $on ? 'bg-white text-blue-900 shadow-sm' : 'text-slate-600 hover:text-blue-900' }}">{{ $label }}</a>
    @endforeach
</div>
@include('distributor.partials.alerts')
