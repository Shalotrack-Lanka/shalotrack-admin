<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Dealer Commission Rates</title>

    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-gray-50/50">

<div class="flex h-screen overflow-hidden">
    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col h-screen overflow-y-auto main-content">
        @include('partials.header')

        <main class="p-4 md:p-8 flex-1 w-full max-w-5xl mx-auto">

            {{-- ⚠️ NOT PRODUCTION READY: the ledger these rates feed has no
                 payment-status gate and no historical backfill -- both
                 pending a client conversation. See DealerCommissionService's
                 class docblock before treating dealer commission totals as
                 final. --}}
            <div class="mb-5 bg-amber-50 border-l-4 border-amber-500 text-amber-800 text-sm px-4 py-3 rounded shadow-sm">
                <strong>Not production-ready:</strong> commission recorded here has no payment-status check and no historical backfill yet — both are pending a client decision. Don't treat dealer commission totals as final until this notice is removed.
            </div>

            <div class="mb-6">
                <h1 class="text-2xl md:text-[28px] font-extrabold text-slate-900 tracking-tight">Dealer Commission Rates</h1>
                <p class="text-slate-500 text-sm mt-1">
                    Rate per device sold, by dealer tier and device type. The most specific match wins
                    (exact tier + exact type &gt; tier only &gt; type only &gt; network default).
                    A device sale that matches nothing configured here falls back to LKR 1,000 —
                    that fallback is not a real configured rate, it just keeps the system working
                    until you set one up.
                </p>
            </div>

            @if(session('success'))
                <div class="mb-5 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 text-sm px-4 py-3 rounded shadow-sm">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-5 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm px-4 py-3 rounded shadow-sm">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ================= ADD / UPDATE RATE ================= --}}
            <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 mb-6">
                <h2 class="font-bold text-slate-900 mb-4">Set a Rate</h2>
                <form method="POST" action="{{ route('admin.dealer.commission-rates.store') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
                    @csrf
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Dealer Tier</label>
                        <select name="dealer_status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white">
                            <option value="">All tiers</option>
                            @foreach($tiers as $tier)
                                <option value="{{ $tier }}">{{ ucfirst($tier) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Device Type</label>
                        <select name="device_type_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white">
                            <option value="">All device types</option>
                            @foreach($deviceTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->device_category }} ({{ $type->model }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Rate (LKR / device)</label>
                        <input type="number" step="0.01" min="0" name="rate" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <button type="submit" class="w-full bg-cyan-600 hover:bg-cyan-700 text-white font-semibold px-4 py-2 rounded-lg text-sm transition-colors shadow-sm">
                            Save Rate
                        </button>
                    </div>
                </form>
                <p class="text-xs text-slate-400 mt-3">
                    Saving a combo that already has a rate updates it in place — new sales use the new rate
                    immediately, past ledger entries keep the rate they were earned at.
                </p>
            </div>

            {{-- ================= CONFIGURED RATES ================= --}}
            <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
                <div class="p-5 border-b border-gray-100">
                    <h2 class="font-bold text-slate-900">Configured Rates</h2>
                </div>
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50/80 text-gray-600 font-semibold text-xs uppercase tracking-wider">
                        <tr>
                            <th class="px-5 py-3">Dealer Tier</th>
                            <th class="px-5 py-3">Device Type</th>
                            <th class="px-5 py-3 text-right">Rate (LKR)</th>
                            <th class="px-5 py-3 w-16"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($rates as $rate)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-5 py-3 font-semibold text-slate-800">
                                    {{ $rate->dealer_status ? ucfirst($rate->dealer_status) : 'All tiers' }}
                                </td>
                                <td class="px-5 py-3 text-slate-600">
                                    {{ $rate->deviceType ? $rate->deviceType->device_category . ' (' . $rate->deviceType->model . ')' : 'All device types' }}
                                </td>
                                <td class="px-5 py-3 text-right font-bold text-slate-900">
                                    {{ number_format($rate->rate, 2) }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <form method="POST" action="{{ route('admin.dealer.commission-rates.destroy', $rate->id) }}" onsubmit="return confirm('Remove this rate? Matching sales will fall through to the next most specific rate.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-bold">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-10 text-center text-slate-400 text-sm">
                                    No rates configured yet — every sale is currently using the LKR 1,000 unconfigured fallback.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </main>
    </div>
</div>

</body>
</html>