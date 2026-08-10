<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('customerSetupData', () => ({
        sidebarOpen: false,
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
        },

        toggleStatus(customerId, isChecked) {
            const newStatus = isChecked ? 'verified' : 'not_verified';

            fetch(`/admin/customer/setup/${customerId}/status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ cus_status: newStatus })
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('active-customers-panel').innerHTML = data.active_html;
                document.getElementById('inactive-customers-panel').innerHTML = data.inactive_html;
            })
            .catch(err => console.error('Status update failed:', err));
        }
    }));
});
</script>

</body>
</html>
