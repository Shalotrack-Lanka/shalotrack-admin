@extends('layouts.admin')

@section('title', 'Dealer Stock Transfer')

@section('content')
<div class="space-y-6">
    
    @if(session('success'))
        <div class="p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-xs font-bold">
            {{ session('success') }}
        </div>
    @endif
    
    @if ($errors->any())
        <div class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-xs font-bold">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
            <h3 class="text-base font-bold text-gray-800">Transfer Stock to Dealer</h3>
        </div>
        <div class="p-6">
            <form action="{{ route('admin.dealer.stock_transfer.store') }}" method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end text-sm">
                @csrf
                
                <div class="md:col-span-1">
                <label class="block mb-1 font-semibold text-gray-700">Select Stock Type</label>
                   <select id="device_type" name="device_type_id" required
                        class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">

                    <option value="" disabled selected>
                        -- Select Device Category / Type --
                    </option>

                    @forelse($deviceTypes as $type)
                        <option value="{{ $type->id }}">
                            {{ $type->device_category }} with {{ $type->model }}
                        </option>
                    @empty
                        <option value="" disabled>
                            No device types available
                        </option>
                    @endforelse

                </select>
                

                <div class="md:col-span-1">
                    <label class="block mb-1 font-semibold text-gray-700">
                        Select Supplier
                    </label>

                <select id="supplier"
                        name="supplier_id"
                        required
                        class="w-full rounded-lg border-gray-300 h-10 text-xs">

                    <option value="">-- Select Supplier --</option>

                </select>
                </div>

                <div class="md:col-span-1">
                    <label class="block mb-1 font-semibold text-gray-700">
                        Select Dealer
                    </label>

                    <select name="dealer_id" required
                        class="w-full rounded-lg border-gray-300 h-10 focus:ring-blue-500 focus:border-blue-500 text-xs">

                        <option value="" selected disabled>
                            -- Select Dealer --
                        </option>

                        @foreach($dealers as $dealer)
                            <option value="{{ $dealer->id }}">
                                {{ $dealer->full_name }}
                            </option>
                        @endforeach

                    </select>
                </div>

                <div class="md:col-span-1">
                    <label class="block mb-1 font-semibold text-gray-700">Quantity</label>
                    <input type="number" name="quantity" min="1" required placeholder="Ex: 50" class="w-full rounded-lg border-gray-300 h-10 focus:ring-blue-500 text-xs">
                </div>

                <div class="md:col-span-1 flex gap-2">
                    <button type="submit" class="w-full bg-[#17a2b8] hover:bg-[#138496] text-white px-4 h-10 rounded-lg font-bold shadow-sm transition">Transfer</button>
                </div>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
            <h3 class="text-base font-bold text-gray-800">Transferred Stock History</h3>
        </div>
        <div class="p-6">
            <div class="border border-gray-200 rounded-lg overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-700 uppercase">
                        <tr>
                            <th class="p-4">Date</th>
                            <th class="p-4">Dealer Name</th>
                            <th class="p-4">Device Model</th>
                            <th class="p-4 text-center">Qty Transferred</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white text-sm font-medium text-gray-700">
                        @forelse($transfers as $transfer)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-4 text-xs text-gray-500">{{ $transfer->created_at->format('Y-m-d h:i A') }}</td>
                                <td class="p-4 font-bold">{{ $transfer->dealer->full_name ?? '-' }}</td>
                                <td class="p-4">{{ $transfer->stock->deviceType->model ?? '-' }}</td>
                                <td class="p-4 text-center font-bold text-blue-600 bg-blue-50">{{ $transfer->quantity }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="p-8 text-center text-gray-400">No stock transfers found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

<script>

document.addEventListener("DOMContentLoaded", function(){

    const device =
        document.getElementById("device_type");

    const supplier =
        document.getElementById("supplier");

    device.addEventListener("change", function(){

        supplier.innerHTML =
            '<option>Loading...</option>';

        fetch('/admin/dealer/suppliers/' + this.value)

        .then(response => response.json())

        .then(function(data){

            supplier.innerHTML =
                '<option value="">Select Supplier</option>';

            data.forEach(function(item){

                supplier.innerHTML +=

                `<option value="${item.id}">
                    ${item.name}
                </option>`;

            });

        });

    });

});

</script>
