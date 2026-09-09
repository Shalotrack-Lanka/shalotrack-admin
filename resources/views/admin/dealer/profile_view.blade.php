@extends('layouts.admin')

@section('title', $dealer->full_name . ' — Dealer Profile')

@section('content')

<div class="space-y-6" x-data="{ editOpen: false }">

    {{-- ── Flash messages ──────────────────────────────────────────────────── --}}
    @if(session('success'))
        <div class="bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 text-sm px-4 py-3 rounded shadow-sm">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 text-red-700 text-sm px-4 py-3 rounded shadow-sm">
            <p class="font-bold mb-1">Fix these errors before saving:</p>
            <ul class="list-disc list-inside space-y-0.5 ml-4">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Header card ─────────────────────────────────────────────────────── --}}
    <div class="bg-white p-6 border border-gray-100 rounded-2xl shadow-sm">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">

            <div class="flex items-center gap-4">
                {{-- Avatar initials --}}
                <div class="w-14 h-14 rounded-full bg-cyan-600 text-white flex items-center justify-center text-xl font-bold flex-shrink-0">
                    {{ strtoupper(substr($dealer->full_name ?? '?', 0, 1)) }}
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">{{ $dealer->full_name }}</h1>
                    <p class="text-xs text-gray-500 mt-0.5">
                        {{ ucfirst(str_replace('_', ' ', $dealer->dealer_status ?? '—')) }}
                        &middot;
                        {{ ucfirst(str_replace('_', ' ', $dealer->region ?? '—')) }}
                    </p>
                </div>
                @if($dealer->status === 'active')
                    <span class="px-2.5 py-1 rounded-full bg-green-50 text-green-700 border border-green-200 text-xs font-bold">Active</span>
                @else
                    <span class="px-2.5 py-1 rounded-full bg-gray-100 text-gray-500 border border-gray-200 text-xs font-bold">Archived</span>
                @endif
            </div>

            <div class="flex gap-2 flex-wrap">
                {{-- Toggle active / archived --}}
                <form action="{{ route('admin.dealer.toggle-status', $dealer->id) }}" method="POST"
                      onsubmit="return confirm('{{ $dealer->status === 'active' ? 'Deactivate' : 'Activate' }} this dealer?');">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                        class="px-4 py-2 rounded-lg text-sm font-semibold shadow-sm
                        {{ $dealer->status === 'active'
                            ? 'bg-red-50 text-red-700 border border-red-200 hover:bg-red-100'
                            : 'bg-green-50 text-green-700 border border-green-200 hover:bg-green-100' }}">
                        {{ $dealer->status === 'active' ? 'Deactivate Dealer' : 'Activate Dealer' }}
                    </button>
                </form>

                {{-- Toggle edit panel — this replaces the old disabled stub --}}
                <button @click="editOpen = !editOpen"
                    :class="editOpen ? 'bg-cyan-600 text-white border-cyan-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                    class="px-4 py-2 rounded-lg text-sm font-semibold border shadow-sm transition-colors">
                    <span x-text="editOpen ? 'Close Edit' : 'Edit Dealer'"></span>
                </button>

                {{-- Back to dealer management --}}
                <a href="{{ route('admin.dealer-management') }}"
                   class="px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-600 border border-gray-200 hover:bg-gray-200">
                    ← Back
                </a>
            </div>
        </div>
    </div>

    {{-- ── Edit panel (Alpine toggle) ──────────────────────────────────────── --}}
    {{--
        Opens inline on the same page — no redirect, no separate route to remember.
        Two-tab layout mirrors the original 3-step create form but Step 3
        (documents) is excluded here — file re-uploads don't belong on an
        edit form unless you're building a dedicated doc-management section.
    --}}
    <div x-show="editOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-cloak
         x-data="{ tab: 'basic' }"
         class="bg-white p-6 border border-cyan-100 rounded-2xl shadow-sm">

        <div class="flex items-center justify-between mb-5">
            <h2 class="font-bold text-gray-800 text-sm uppercase tracking-wide">Edit Dealer Details</h2>
            <div class="flex gap-2">
                <button type="button" @click="tab = 'basic'"
                    :class="tab === 'basic' ? 'bg-cyan-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-3 py-1 rounded-lg text-xs font-semibold transition-colors">
                    Basic Info
                </button>
                <button type="button" @click="tab = 'business'"
                    :class="tab === 'business' ? 'bg-cyan-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-3 py-1 rounded-lg text-xs font-semibold transition-colors">
                    Business & Access
                </button>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.dealer.profile.update', $dealer->id) }}" class="space-y-5">
            @csrf
            @method('PUT')

            {{-- ── Tab 1: Basic info ──────────────────────────────────────── --}}
            <div x-show="tab === 'basic'" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Full Name <span class="text-red-500">*</span></label>
                        <input type="text" name="full_name" value="{{ old('full_name', $dealer->full_name) }}" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Qualification</label>
                        <input type="text" name="qualification" value="{{ old('qualification', $dealer->qualification) }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Dealer Type <span class="text-red-500">*</span></label>
                        <select name="dealer_status" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none bg-white">
                            @foreach(['lbc' => 'LBC','distributor' => 'Distributor','retailer' => 'Retailer','dsa' => 'DSA','ba' => 'BA','csa' => 'CSA','lt_point' => 'LT Point'] as $val => $label)
                                <option value="{{ $val }}" {{ old('dealer_status', $dealer->dealer_status) === $val ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Region <span class="text-red-500">*</span></label>
                        <select name="region" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none bg-white">
                            @foreach(['central' => 'Central','colombo' => 'Colombo','eastern' => 'Eastern','gampaha' => 'Gampaha','north_central' => 'North Central','north_western' => 'North Western','northern' => 'Northern','sabaragamuwa' => 'Sabaragamuwa','southern' => 'Southern','uva' => 'Uva','western' => 'Western'] as $val => $label)
                                <option value="{{ $val }}" {{ old('region', $dealer->region) === $val ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Country</label>
                        <input type="text" name="country" value="{{ old('country', $dealer->country) }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Pin Code</label>
                        <input type="text" name="pin_code" value="{{ old('pin_code', $dealer->pin_code) }}"
                               placeholder="4–10 digits"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Address</label>
                        <textarea name="address" rows="2"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">{{ old('address', $dealer->address) }}</textarea>
                    </div>
                </div>
            </div>

            {{-- ── Tab 2: Business & access ────────────────────────────────── --}}
            <div x-show="tab === 'business'" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Contact Email</label>
                        <input type="email" name="contact_email" value="{{ old('contact_email', $dealer->contact_email) }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Network</label>
                        <select name="network"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none bg-white">
                            <option value="">— Not Set —</option>
                            <option value="dialog_axiata" {{ old('network', $dealer->network) === 'dialog_axiata' ? 'selected' : '' }}>Dialog Axiata</option>
                            <option value="mobitel_sri_lanka" {{ old('network', $dealer->network) === 'mobitel_sri_lanka' ? 'selected' : '' }}>Mobitel Sri Lanka</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">User ID (Login Reference)</label>
                        <input type="text" name="login_id" value="{{ old('login_id', $dealer->login_id) }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Security Deposit (Rs.)</label>
                        <input type="number" step="0.01" min="0" name="security_deposit"
                               value="{{ old('security_deposit', $dealer->security_deposit) }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Deposit Date</label>
                        <input type="date" name="deposit_date"
                               value="{{ old('deposit_date', optional($dealer->deposit_date)->format('Y-m-d')) }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Income Tax PAN</label>
                        <input type="text" name="tax_pan" value="{{ old('tax_pan', $dealer->tax_pan) }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">CST No</label>
                        <input type="text" name="cst_no" value="{{ old('cst_no', $dealer->cst_no) }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">VAT TIN No</label>
                        <input type="text" name="vat_tin" value="{{ old('vat_tin', $dealer->vat_tin) }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">GST / PAN No</label>
                        <input type="text" name="gst_pan" value="{{ old('gst_pan', $dealer->gst_pan) }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 outline-none">
                    </div>
                </div>
            </div>

            <div class="flex gap-3 pt-3 border-t border-gray-100">
                <button type="submit"
                        class="bg-cyan-600 hover:bg-cyan-700 text-white font-semibold px-5 py-2 rounded-lg text-sm shadow-sm transition-colors">
                    Save Changes
                </button>
                <button type="button" @click="editOpen = false"
                        class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium px-5 py-2 rounded-lg text-sm shadow-sm transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>

    {{-- ── Performance stat cards ───────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Assigned Devices</div>
            <div class="text-2xl font-bold text-gray-800">{{ $assignedDevices->count() }}</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Total Stock Transferred</div>
            <div class="text-2xl font-bold text-gray-800">{{ $totalDevicesTransferred }}</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm opacity-50">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Registered Customers</div>
            <div class="text-sm text-gray-400 mt-1">Not linked yet</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm opacity-50">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Active Vehicles</div>
            <div class="text-sm text-gray-400 mt-1">Not linked yet</div>
        </div>
    </div>

    {{-- ── Detail columns ───────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Left: Contact + Activity + Reports --}}
        <div class="lg:col-span-1 space-y-6">

            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">Contact Details</h2>
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Email</div>
                        <div class="text-gray-700">{{ $dealer->contact_email ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Region</div>
                        <div class="text-gray-700">{{ ucfirst(str_replace('_', ' ', $dealer->region ?? '—')) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Country</div>
                        <div class="text-gray-700">{{ $dealer->country ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Pin Code</div>
                        <div class="text-gray-700">{{ $dealer->pin_code ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Login ID (Ref)</div>
                        <div class="text-gray-700 font-mono text-xs">{{ $dealer->login_id ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Network</div>
                        <div class="text-gray-700">{{ ucfirst(str_replace('_', ' ', $dealer->network ?? '—')) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Security Deposit</div>
                        <div class="text-gray-700">
                            {{ $dealer->security_deposit ? 'Rs. ' . number_format($dealer->security_deposit, 2) : '—' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Member Since</div>
                        <div class="text-gray-700">{{ $dealer->created_at->format('d M Y') }}</div>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">Recent Activity</h2>
                @forelse($recentActivity as $item)
                    <div class="text-xs text-gray-600 border-l-2 border-cyan-200 pl-3 pb-3">
                        <div class="text-gray-400">{{ $item['date']->format('d M Y, h:i A') }}</div>
                        <div>{{ $item['text'] }}</div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400">No activity yet.</p>
                @endforelse
            </div>

            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-2">Quick Links</h2>
                <a href="{{ route('admin.stock-in-report') }}" class="block text-xs text-cyan-600 hover:underline mb-1.5">→ Stock In Report</a>
                <a href="{{ route('admin.credit-invoice-report') }}" class="block text-xs text-cyan-600 hover:underline mb-1.5">→ Credit Invoice Report</a>
                <a href="{{ route('admin.dealer.stock_transfer') }}" class="block text-xs text-cyan-600 hover:underline">→ Stock Transfer</a>
            </div>
        </div>

        {{-- Right: Assigned devices + Stock transfers + Placeholder --}}
        <div class="lg:col-span-2 space-y-6">

            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">
                    Assigned Devices ({{ $assignedDevices->count() }})
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 text-gray-500 uppercase">
                            <tr>
                                <th class="p-2">IMEI</th>
                                <th class="p-2">Device Category</th>
                                <th class="p-2">Status</th>
                                <th class="p-2">Assigned</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($assignedDevices as $device)
                                <tr>
                                    <td class="p-2 font-mono text-gray-700">{{ $device->imei_number }}</td>
                                    <td class="p-2">{{ $device->device_category }}</td>
                                    <td class="p-2">
                                        <span class="px-1.5 py-0.5 rounded text-xs font-semibold
                                            {{ $device->status === 'Activated' ? 'bg-green-50 text-green-700' : 'bg-yellow-50 text-yellow-700' }}">
                                            {{ $device->status }}
                                        </span>
                                    </td>
                                    <td class="p-2 text-gray-400 whitespace-nowrap">
                                        {{ optional($device->allocated_at)->format('d M Y') ?? optional($device->created_at)->format('d M Y') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="p-4 text-center text-gray-400">No devices assigned.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">
                    Stock Transfer History ({{ $transfers->count() }} transfers · {{ $totalDevicesTransferred }} total units)
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 text-gray-500 uppercase">
                            <tr>
                                <th class="p-2">Date</th>
                                <th class="p-2">Device Category / Type</th>
                                <th class="p-2 text-right">Qty</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($transfers as $transfer)
                                <tr>
                                    <td class="p-2 whitespace-nowrap">{{ $transfer->created_at->format('d M Y') }}</td>
                                    <td class="p-2">{{ $transfer->device_category }}</td>
                                    <td class="p-2 text-right font-bold text-gray-700">{{ $transfer->quantity }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="p-4 text-center text-gray-400">No stock transfers yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-dashed border-gray-300 shadow-sm">
                <h2 class="font-bold text-gray-500 text-sm mb-2">Registered Customers & Vehicles</h2>
                <p class="text-xs text-gray-400">
                    Not available yet — there is currently no link between Dealers and Customers/Vehicles
                    in the system. Customer data lives on the ShaloTrack API's database, which doesn't
                    carry a dealer_id on any Customer or Vehicle record. This needs a schema change on
                    the API side before this section can show real data.
                </p>
            </div>

        </div>
    </div>

</div>

@endsection