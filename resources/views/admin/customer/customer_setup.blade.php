<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Customer Setup</title>

    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="customerSetupData()">

<div class="flex h-screen overflow-hidden">

    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 space-y-6">

            @if(session('success'))
                <div class="bg-green-100 text-green-800 border border-green-300 rounded-lg px-4 py-3 text-sm font-semibold">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="bg-red-100 text-red-800 border border-red-300 rounded-lg px-4 py-3 text-sm font-semibold">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div id="active-customers-panel">
                @include('admin.customer._active_table')
            </div>

            <div id="inactive-customers-panel">
                @include('admin.customer._inactive_table')
            </div>

        </main>
    </div>
</div>

{{-- EDIT MODAL --}}
<div x-show="editModal" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div @click.outside="editModal = false" class="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden">

        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-800" x-text="editing?.full_name"></h3>
            <button @click="editModal = false" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>

        <form :action="editing ? '/admin/customer/setup/' + editing.customer_id : ''" method="POST" enctype="multipart/form-data" class="p-6 space-y-4 text-sm">
            @csrf
            @method('PUT')

            <div>
                <label class="block mb-1 font-semibold text-gray-700">IMEI Number</label>
                <input type="text" name="imei_number" x-bind:value="editing?.imei_number" class="w-full rounded-lg border-gray-300 h-9">
            </div>

            <div>
                <label class="block mb-1 font-semibold text-gray-700">SIM Number</label>
                <input type="text" name="sim_number" x-bind:value="editing?.sim_number" class="w-full rounded-lg border-gray-300 h-9">
            </div>

            <div>
                <label class="block mb-1 font-semibold text-gray-700">Device Type</label>
                <input type="text" name="device_type" x-bind:value="editing?.device_type" class="w-full rounded-lg border-gray-300 h-9">
            </div>

            <div>
                <label class="block mb-1 font-semibold text-gray-700">Payment Status</label>
                <select name="payment_status" x-model="editing.payment_status" class="w-full rounded-lg border-gray-300 h-9">
                    <option value="not_paid">Not Paid</option>
                    <option value="paid">Paid</option>
                </select>
            </div>

            <div x-show="editing?.payment_status === 'paid'">
                <label class="block mb-1 font-semibold text-gray-700">Subscription Period</label>
                <select name="subscription_period" class="w-full rounded-lg border-gray-300 h-9">
                    <option value="">Select period</option>
                    <option value="3_months">3 Months</option>
                    <option value="6_months">6 Months</option>
                    <option value="1_year">1 Year</option>
                    <option value="3_years">3 Years</option>
                </select>
            </div>

            <div x-show="editing?.payment_status === 'paid'">
                <label class="block mb-1 font-semibold text-gray-700">Bank Invoice</label>
                <input type="file" name="bank_invoice" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-xs">
                <p class="text-xs text-gray-400 mt-1" x-show="editing?.bank_invoice_path">An invoice is already on file. Only upload a new one to replace it.</p>
            </div>

            <div class="pt-2 flex justify-end gap-3">
                <button type="button" @click="editModal = false" class="px-4 py-2 rounded-lg border border-gray-300 text-gray-700 font-semibold text-sm">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-blue-900 hover:bg-blue-800 text-white font-semibold text-sm">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('customerSetupData', () => ({
        sidebarOpen: false,
        editModal: false,
        editing: null,
        refreshing: false,
        
        refreshTables() {
            // Prevent spam-clicking
            if (this.refreshing) return; 
            
            this.refreshing = true;
            
            fetch("{{ route('admin.customer-setup.refresh') }}", {
                headers: { 
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => res.json())
            .then(data => {
                // Swap the HTML in the background
                document.getElementById('active-customers-panel').innerHTML = data.active_html;
                document.getElementById('inactive-customers-panel').innerHTML = data.inactive_html;
            })
            .catch(err => console.error('Refresh failed:', err))
            .finally(() => {
                // Always reset the button state, even if it fails
                this.refreshing = false;
            });
        }
    }));
});
</script>

</body>
</html>