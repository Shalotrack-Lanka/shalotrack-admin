@extends('layouts.admin')

@section('content')
<div x-data="invoiceSystem()" class="max-w-6xl mx-auto space-y-6">

    <div class="mb-4">
        <h2 class="text-2xl font-black text-slate-800">Purchase Order / Supplier Invoice</h2>
        <p class="text-slate-500 text-sm">Create a new purchase order and update your stocks.</p>
    </div>

    {{-- Success/Error Messages --}}
    @if(session('success'))
        <div class="p-4 bg-emerald-50 text-emerald-700 rounded-xl font-bold border border-emerald-200">
            ✅ {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="p-4 bg-red-50 text-red-700 rounded-xl font-bold border border-red-200">
            ⚠️ Please fix the errors below and try again.
        </div>
    @endif

    <form action="{{ route('admin.supplier-invoice.store') }}" method="POST" @submit="validateForm">
        @csrf

        {{-- 1. Select Supplier Section --}}
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 mb-6">
            <label class="block text-sm font-bold text-slate-700 mb-2">Select Supplier to Generate Invoice :</label>
            <select name="supplier_id" 
                    x-model="supplierId" 
                    @change="fetchSupplierData" 
                    required
                    class="w-full border-slate-300 rounded-xl focus:ring-blue-500 focus:border-blue-500 text-sm p-3 bg-slate-50">
                <option value="">-- Click to Select Registered Supplier --</option>
                @foreach($suppliers as $sup)
                    <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                @endforeach
            </select>
            
            <div x-show="isLoading" class="text-sm text-blue-600 mt-2 font-bold animate-pulse" style="display: none;">
                Loading supplier data...
            </div>
        </div>

        {{-- 2. Supplier Details Section --}}
        <div x-show="supplierDetails" x-cloak class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 mb-6 transition-all" style="display: none;">
            <h3 class="font-bold text-slate-800 mb-4 border-b pb-2">Supplier Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="block text-xs text-slate-400 font-bold uppercase">Supplier Name</span>
                    <span class="font-bold text-slate-800" x-text="supplierDetails?.name || '-'"></span>
                </div>
                <div>
                    <span class="block text-xs text-slate-400 font-bold uppercase">Email Address</span>
                    <span class="font-medium text-slate-700" x-text="supplierDetails?.email || '-'"></span>
                </div>
                <div>
                    <span class="block text-xs text-slate-400 font-bold uppercase">Mobile / Phone</span>
                    <span class="font-medium text-slate-700" x-text="supplierDetails?.phone_number || '-'"></span>
                </div>
                <div>
                    <span class="block text-xs text-slate-400 font-bold uppercase">Tax / VAT Reg. No.</span>
                    <span class="font-medium text-slate-700" x-text="supplierDetails?.gstin_number || '-'"></span>
                </div>
                <div class="md:col-span-4 mt-2">
                    <span class="block text-xs text-slate-400 font-bold uppercase">Physical Address</span>
                    <span class="font-medium text-slate-700" x-text="supplierDetails?.address || '-'"></span>
                </div>
            </div>
        </div>

        {{-- 3. Order Items Table Section --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden mb-6">
            <div class="bg-slate-50 px-6 py-4 border-b border-slate-200 flex justify-between items-center">
                <h3 class="font-bold text-slate-800">Order Items List</h3>
                <button type="button" @click="addItem" class="bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold py-2 px-4 rounded-lg shadow-sm transition cursor-pointer">
                    + Add Item Row
                </button>
            </div>

            <div class="overflow-x-auto p-4">
                <table class="w-full text-left border-collapse text-sm">
                    <thead class="text-xs uppercase text-slate-500 bg-slate-50">
                        <tr>
                            <th class="p-3 border-b">Product Type</th>
                            <th class="p-3 border-b w-1/4">Product Item</th>
                            <th class="p-3 border-b w-24">Qty</th>
                            <th class="p-3 border-b w-32">Unit Price</th>
                            <th class="p-3 border-b w-24">Disc (%)</th>
                            <th class="p-3 border-b w-24">Face Value</th>
                            <th class="p-3 border-b w-32 text-right">Net Amount</th>
                            <th class="p-3 border-b text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, index) in items" :key="index">
                            <tr class="hover:bg-slate-50 transition border-b border-slate-100">
                                <td class="p-2">
                                    <select x-model="item.type" :name="`items[${index}][type]`" required class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                        <option value="Hardware">Hardware</option>
                                        <option value="Service">Service</option>
                                        <option value="Software">Software</option>
                                    </select>
                                </td>
                                <td class="p-2">
                                    <select x-model="item.product_id" :name="`items[${index}][product_id]`" @change="productSelected(item)" required class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                        <option value="">-- Select Product --</option>
                                        <template x-for="prod in availableProducts" :key="prod.id">
                                            <option :value="prod.id" x-text="prod.product_name"></option>
                                        </template>
                                    </select>
                                </td>
                                <td class="p-2">
                                    <input type="number" x-model.number="item.order_qty" :name="`items[${index}][order_qty]`" min="1" required class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                </td>
                                <td class="p-2">
                                    <input type="number" step="0.01" x-model.number="item.unit_price" :name="`items[${index}][unit_price]`" min="0" required class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                </td>
                                <td class="p-2">
                                    <input type="number" step="0.01" x-model.number="item.discount" :name="`items[${index}][discount]`" min="0" class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                </td>
                                <td class="p-2">
                                    <input type="number" step="0.01" x-model.number="item.face_value" :name="`items[${index}][face_value]`" min="0" class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                </td>
                                <td class="p-2 text-right font-bold text-slate-800">
                                    Rs. <span x-text="calculateRowTotal(item).toFixed(2)"></span>
                                </td>
                                <td class="p-2 text-center">
                                    <button type="button" @click="removeItem(index)" class="text-red-500 hover:text-red-700 bg-red-50 p-2 rounded-lg transition font-bold cursor-pointer">
                                        ✕
                                    </button>
                                </td>
                            </tr>
                        </template>

                        <tr x-show="items.length === 0">
                            <td colspan="8" class="p-8 text-center text-slate-400 italic">
                                No items added yet. Click "+ Add Item Row" to start.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Grand Total Section --}}
            <div class="bg-blue-50/50 p-6 border-t border-slate-200 flex justify-end items-center gap-6">
                <div class="text-right">
                    <span class="block text-xs font-bold text-slate-500 uppercase tracking-widest">Grand Total Summary</span>
                    <span class="text-3xl font-black text-blue-700">Rs. <span x-text="grandTotal.toFixed(2)"></span></span>
                </div>
            </div>
        </div>

        {{-- Submit Button --}}
        <div class="flex justify-end">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-8 rounded-xl shadow-md transition text-lg cursor-pointer">
                💾 Save Purchase Order
            </button>
        </div>

    </form>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('invoiceSystem', () => ({
        supplierId: '{{ $supplierId ?? "" }}',
        supplierDetails: null,
        availableProducts: [],
        items: [],
        isLoading: false,

        init() {
            if(this.supplierId) {
                this.fetchSupplierData();
            }
        },

        fetchSupplierData() {
            if (!this.supplierId) {
                this.supplierDetails = null;
                this.availableProducts = [];
                this.items = [];
                return;
            }
            this.isLoading = true;
            
            fetch(`{{ url('admin/supplier/get-supplier-data') }}/${this.supplierId}`)
                .then(res => res.json())
                .then(data => {
                    this.supplierDetails = data.supplier;
                    this.availableProducts = data.products || [];
                    this.items = []; // වෙනත් supplier කෙනෙක් තේරූ විට පරණ items මකා දමයි
                    this.isLoading = false;
                })
                .catch(err => {
                    console.error('Error fetching data:', err);
                    alert('Error fetching supplier data. Please check your network or route.');
                    this.isLoading = false;
                });
        },

        addItem() {
            if(!this.supplierId) {
                alert('Please select a supplier first!');
                return;
            }
            this.items.push({
                product_id: '',
                type: 'Hardware',
                order_qty: 1,
                unit_price: 0,
                discount: 0,
                face_value: 0
            });
        },

        removeItem(index) {
            this.items.splice(index, 1);
        },

        productSelected(item) {
            let prod = this.availableProducts.find(p => p.id == item.product_id);
            if (prod) {
                item.unit_price = prod.price || 0;
                item.discount = prod.discount || 0;
            }
        },

        calculateRowTotal(item) {
            let lineTotal = (Number(item.order_qty) || 0) * (Number(item.unit_price) || 0);
            let discAmt = lineTotal * ((Number(item.discount) || 0) / 100);
            return lineTotal - discAmt + Number(item.face_value || 0);
        },

        get grandTotal() {
            return this.items.reduce((total, item) => {
                return total + this.calculateRowTotal(item);
            }, 0);
        },

        validateForm(e) {
            if (this.items.length === 0) {
                e.preventDefault();
                alert('⚠️ Please add at least one item to the order before saving.');
            }
        }
    }));
});
</script>
@endsection