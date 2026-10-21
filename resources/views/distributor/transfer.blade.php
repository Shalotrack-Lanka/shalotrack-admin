@extends('layouts.distributor')

@section('title', 'Transfer to a dealer')

@section('content')
<div class="space-y-6">

    @if (session('success'))
        <div class="rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm px-4 py-3">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm px-4 py-3">
            @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-4 border-b border-gray-100">
            <h1 class="text-lg font-bold text-slate-900">Transfer devices to a dealer</h1>
            <p class="text-xs text-slate-500">Pass devices from your own stock to one of your dealers. A transfer cannot be edited afterwards: the company handles corrections.</p>
        </div>

        @if ($stock->isEmpty())
            <p class="p-6 text-sm text-slate-500">You have no available stock to transfer. Devices appear here once the company transfers them to you.</p>
        @elseif ($dealers->isEmpty())
            <p class="p-6 text-sm text-slate-500">You have no active dealers yet. Ask the company to attach dealers to you.</p>
        @else
            <form method="POST" action="{{ route('distributor.transfer.store') }}" class="p-5 space-y-5"
                  onsubmit="return confirm('Transfer the selected devices to this dealer? This cannot be edited afterwards.')">
                @csrf
                <div class="max-w-sm">
                    <label for="dealer_id" class="block text-sm font-semibold text-slate-700 mb-1">Dealer</label>
                    <select id="dealer_id" name="dealer_id" required class="w-full rounded-lg border-gray-300 text-sm">
                        <option value="" disabled selected>-- Select dealer --</option>
                        @foreach ($dealers as $d)
                            <option value="{{ $d->id }}" @selected(old('dealer_id') == $d->id)>{{ $d->full_name }}</option>
                        @endforeach
                    </select>
                </div>

                @foreach ($stock as $category => $devices)
                    <fieldset class="border border-gray-200 rounded-lg">
                        <legend class="px-2 ml-3 text-sm font-semibold text-slate-700">{{ $category }} ({{ $devices->count() }})</legend>
                        <div class="px-4 pb-2">
                            <label class="text-xs text-slate-500 cursor-pointer">
                                <input type="checkbox" class="mr-1" onclick="this.closest('fieldset').querySelectorAll('input[name=&quot;device_ids[]&quot;]').forEach(c => c.checked = this.checked)">
                                Select all in this category
                            </label>
                        </div>
                        <div class="px-4 pb-4 grid sm:grid-cols-2 lg:grid-cols-3 gap-2 max-h-64 overflow-y-auto">
                            @foreach ($devices as $dev)
                                <label class="flex items-center gap-2 text-xs font-mono bg-gray-50 rounded px-2 py-1.5">
                                    <input type="checkbox" name="device_ids[]" value="{{ $dev->shdevice_id }}" @checked(is_array(old('device_ids')) && in_array($dev->shdevice_id, old('device_ids')))>
                                    <span>{{ $dev->imei_number }}</span>
                                    <span class="text-slate-400">{{ $dev->sim_number }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach

                <button type="submit" class="rounded-lg bg-[#0B1B3F] text-white text-sm font-semibold px-5 py-2.5 hover:opacity-90">Transfer selected devices</button>
            </form>
        @endif
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100"><h2 class="font-bold text-slate-900">Sent to my dealers</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-slate-600"><tr>
                    <th class="p-3">Date &amp; time</th><th class="p-3">Dealer</th><th class="p-3">Category</th><th class="p-3 text-center">Devices</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($sent as $t)
                        <tr>
                            <td class="p-3 text-xs text-slate-500">{{ $t->created_at->local()->format('Y-m-d h:i A') }}</td>
                            <td class="p-3 font-semibold">{{ $t->dealer->full_name ?? '-' }}</td>
                            <td class="p-3">{{ $t->device_category }}</td>
                            <td class="p-3 text-center font-bold">{{ $t->quantity }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-6 text-center text-slate-500">Nothing sent yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100"><h2 class="font-bold text-slate-900">Received from the company</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-slate-600"><tr>
                    <th class="p-3">Date &amp; time</th><th class="p-3">Category</th><th class="p-3 text-center">Devices</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($received as $t)
                        <tr>
                            <td class="p-3 text-xs text-slate-500">{{ $t->created_at->local()->format('Y-m-d h:i A') }}</td>
                            <td class="p-3">{{ $t->device_category }}</td>
                            <td class="p-3 text-center font-bold">{{ $t->quantity }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="p-6 text-center text-slate-500">Nothing received yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection