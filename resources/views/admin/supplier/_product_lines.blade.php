{{--
    The supplier's product list, the same picker in the Add and Edit forms.
    A line is either a DEVICE TYPE (sent as products[i][device_type_id], so it is stock-tracked, can be ordered,
    scanned in and stocked in) or another product such as a SIM package (sent as products[i][product_name]).
    Devices come only from Add Device Types: a device can no longer be typed as free text and end up unlinked.

    $initial: [['choice' => 'dt:5' | 'name:Dialog SIM', 'price' => .., 'qty' => ..], ...]
--}}
@php
    $lineSeed = collect($initial ?? [])->values()->map(fn ($l, $i) => $l + ['key' => $i + 1, 'newName' => ''])->all();
@endphp
<div class="md:col-span-2 bg-gray-50 p-4 rounded-xl border border-gray-200 mt-2"
     x-data="{
         lines: @js($lineSeed),
         next: 1000,
         init() { if (this.lines.length === 0) this.add(); },
         add() { this.lines.push({ key: this.next++, choice: '', newName: '', price: '', qty: '' }); },
         remove(i) { this.lines.splice(i, 1); },
         isDevice(l) { return (l.choice || '').startsWith('dt:'); },
         typeId(l) { return this.isDevice(l) ? l.choice.slice(3) : ''; },
         nameOf(l) { return l.choice === '__new' ? l.newName : ((l.choice || '').startsWith('name:') ? l.choice.slice(5) : ''); }
     }">
    <h4 class="font-bold mb-1 text-gray-700">Products this supplier supplies</h4>
    <p class="text-[11px] text-gray-500 mb-3">
        Only devices on this list can be ordered, scanned in on a delivery and added to stock from this supplier. Devices come from
        <a href="{{ route('admin.add-device-type') }}" class="text-blue-600 underline">Add Device Types</a>.
        SIM cards: add an other product with the word SIM in its name (for example "Mobitel SIM card"); only then can SIMs be ordered from and received from this supplier. The quantity here is only the usual order size: what can be received is limited by the purchase order.
        A product that an open purchase order or a waiting delivery still needs cannot be removed.
    </p>
    <template x-for="(l, i) in lines" :key="l.key">
        <div class="flex flex-wrap md:flex-nowrap gap-2 items-end mb-3">
            <div class="flex-1 w-full">
                <label class="block mb-1 text-[10px]">Product</label>
                <select required x-model="l.choice" class="w-full rounded-lg border-gray-300 h-9 shadow-sm text-xs bg-white">
                    <option value="">Select product...</option>
                    <optgroup label="Devices (stock-tracked)">
                        @foreach($deviceTypes as $type)
                            <option value="dt:{{ $type->id }}">{{ $type->device_category }} {{ $type->model }}</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="Other products (SIM cards, services...)">
                        @foreach($otherProducts as $p)
                            <option value="name:{{ $p->product_name }}">{{ $p->product_name }}</option>
                        @endforeach
                        <option value="__new">+ New other product...</option>
                    </optgroup>
                </select>
                <input type="text" x-show="l.choice === '__new'" x-cloak x-model="l.newName" :required="l.choice === '__new'" maxlength="255"
                       placeholder="e.g. Mobitel SIM card (include the word SIM)" class="mt-1 w-full rounded-lg border-gray-300 h-9 shadow-sm text-xs">
                {{-- What is submitted: a device type id, or a product name. --}}
                <template x-if="isDevice(l)"><input type="hidden" :name="`products[${i}][device_type_id]`" :value="typeId(l)"></template>
                <template x-if="!isDevice(l)"><input type="hidden" :name="`products[${i}][product_name]`" :value="nameOf(l)"></template>
            </div>
            <div class="w-1/4 md:w-28">
                <label class="block mb-1 text-[10px]">Agreed unit price</label>
                <input type="number" step="0.01" min="0" x-model="l.price" :name="`products[${i}][price]`" class="w-full rounded-lg border-gray-300 h-9 shadow-sm text-xs" placeholder="0.00">
            </div>
            <div class="w-1/4 md:w-24">
                <label class="block mb-1 text-[10px]" title="Fills in the quantity on a new purchase order. Stock is never taken from this number.">Usual order qty</label>
                <input type="number" step="1" min="0" x-model="l.qty" :name="`products[${i}][qty]`" class="w-full rounded-lg border-gray-300 h-9 shadow-sm text-xs" placeholder="0">
            </div>
            <div class="w-1/4 md:w-32">
                <label class="block mb-1 text-[10px] text-gray-500">Order value</label>
                <div class="h-9 px-3 flex items-center bg-gray-200 border border-gray-300 rounded-lg text-xs font-bold text-gray-700 overflow-hidden">
                    Rs. <span x-text="( (l.price || 0) * (l.qty || 0) ).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})" class="ml-1"></span>
                </div>
            </div>
            <button type="button" @click="remove(i)" class="bg-red-50 text-red-600 h-9 px-3 rounded-lg border border-red-200 w-auto font-bold hover:bg-red-100">X</button>
        </div>
    </template>
    <button type="button" @click="add" class="text-xs text-blue-600 font-bold mt-1">+ Add Another Product</button>
</div>
