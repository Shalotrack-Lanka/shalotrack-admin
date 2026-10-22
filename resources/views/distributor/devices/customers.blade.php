@extends('layouts.distributor')

@section('title', 'My Customers')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">
    @include('distributor.partials.alerts')

    <div>
        <h1 class="text-2xl font-black text-slate-900">Customers</h1>
        <p class="text-sm text-slate-500">Your own customers and, read-only, the customers your dealers registered.</p>
    </div>

    <form method="POST" action="{{ route('distributor.customers.store') }}" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 grid sm:grid-cols-4 gap-3 items-end">
        @csrf
        <div class="sm:col-span-2"><label for="name" class="block text-xs font-bold text-slate-600 mb-1">Customer name</label><input id="name" name="name" value="{{ old('name') }}" required maxlength="255" class="w-full rounded-xl border-slate-300 text-sm"></div>
        <div><label for="contact" class="block text-xs font-bold text-slate-600 mb-1">Phone (07XXXXXXXX)</label><input id="contact" name="contact" value="{{ old('contact') }}" required maxlength="10" class="w-full rounded-xl border-slate-300 text-sm"></div>
        <div><label for="email" class="block text-xs font-bold text-slate-600 mb-1">Email</label><input id="email" type="email" name="email" value="{{ old('email') }}" class="w-full rounded-xl border-slate-300 text-sm"></div>
        <div class="sm:col-span-4"><button class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700">Register my customer</button></div>
    </form>

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600"><tr><th class="p-4">Customer</th><th class="p-4">Phone</th><th class="p-4">Email</th><th class="p-4">Registered by</th><th class="p-4">Devices</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($leads as $l)
                    <tr class="hover:bg-slate-50">
                        <td class="p-4 font-semibold">{{ $l->name }}</td>
                        <td class="p-4">{{ $l->contact }}</td>
                        <td class="p-4">{{ $l->email ?: '—' }}</td>
                        <td class="p-4">{{ $l->dealer_id === $me ? 'Me' : ($names[$l->dealer_id] ?? '—') }}</td>
                        <td class="p-4">{{ count($l->imei_numbers ?? []) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-10 text-center text-slate-400">No customers yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-slate-100">{{ $leads->links() }}</div>
    </div>
</div>
@endsection