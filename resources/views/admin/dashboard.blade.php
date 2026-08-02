<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Dashboard</title>

    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</head>
<body x-data="{ sidebarOpen: false }" class="font-sans antialiased bg-slate-50 text-slate-800 transition-colors duration-200">

<div class="flex h-screen overflow-hidden"> 
    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col h-screen overflow-y-auto main-content">

        @include('partials.header')

        <main class="p-4 md:p-8 flex-1 w-full max-w-7xl mx-auto">
            
            <!-- Welcome Section -->
            <div class="mb-8">
                <h1 class="text-2xl md:text-3xl font-extrabold text-slate-800 tracking-tight">Dashboard Overview</h1>
                <p class="text-slate-500 text-sm mt-1">Here's a quick summary of your system's current status.</p>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10 w-full">
                <!-- Total Devices -->
                <div class="relative bg-gradient-to-br from-blue-600 to-indigo-700 text-white rounded-2xl shadow-lg shadow-blue-500/30 p-6 overflow-hidden group hover:-translate-y-1 transition-all duration-300">
                    <div class="absolute -right-4 -bottom-4 opacity-20 group-hover:scale-110 group-hover:-rotate-12 transition-transform duration-500">
                        <svg class="w-24 h-24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                    </div>
                    <p class="text-blue-100 text-sm font-semibold uppercase tracking-wider mb-1">Total Devices</p>
                    <h2 class="text-3xl md:text-5xl font-extrabold">{{ $totalDevices }}</h2>
                </div>
                
                <!-- Active Devices -->
                <div class="relative bg-gradient-to-br from-emerald-500 to-teal-600 text-white rounded-2xl shadow-lg shadow-emerald-500/30 p-6 overflow-hidden group hover:-translate-y-1 transition-all duration-300">
                    <div class="absolute -right-4 -bottom-4 opacity-20 group-hover:scale-110 group-hover:-rotate-12 transition-transform duration-500">
                        <svg class="w-24 h-24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <p class="text-emerald-100 text-sm font-semibold uppercase tracking-wider mb-1">Active Devices</p>
                    <h2 class="text-3xl md:text-5xl font-extrabold">{{ $activatedDevices }}</h2>
                </div>

                <!-- Total Suppliers -->
                <div class="relative bg-gradient-to-br from-amber-500 to-orange-600 text-white rounded-2xl shadow-lg shadow-orange-500/30 p-6 overflow-hidden group hover:-translate-y-1 transition-all duration-300">
                    <div class="absolute -right-4 -bottom-4 opacity-20 group-hover:scale-110 group-hover:-rotate-12 transition-transform duration-500">
                        <svg class="w-24 h-24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    </div>
                    <p class="text-orange-100 text-sm font-semibold uppercase tracking-wider mb-1">Total Suppliers</p>
                    <h2 class="text-3xl md:text-5xl font-extrabold">{{ $totalSuppliers }}</h2>
                </div>

                <!-- Total Dealers -->
                <div class="relative bg-gradient-to-br from-rose-500 to-red-600 text-white rounded-2xl shadow-lg shadow-red-500/30 p-6 overflow-hidden group hover:-translate-y-1 transition-all duration-300">
                    <div class="absolute -right-4 -bottom-4 opacity-20 group-hover:scale-110 group-hover:-rotate-12 transition-transform duration-500">
                        <svg class="w-24 h-24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    </div>
                    <p class="text-red-100 text-sm font-semibold uppercase tracking-wider mb-1">Total Dealers</p>
                    <h2 class="text-3xl md:text-5xl font-extrabold">{{ $totalDealers }}</h2>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8 w-full">
                <!-- Line Chart -->
                <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-slate-100 p-5 md:p-6 w-full relative">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="font-extrabold text-lg md:text-xl text-slate-800">Customer Growth</h3>
                        <span class="bg-blue-50 text-blue-600 px-3 py-1 rounded-full text-xs font-bold">This Year</span>
                    </div>
                    <div class="h-[250px] md:h-[300px] w-full relative">
                        <canvas id="customerChart"></canvas>
                    </div>
                </div>

                <!-- Doughnut Chart -->
                <div class="lg:col-span-1 bg-white rounded-2xl shadow-sm border border-slate-100 p-5 md:p-6 w-full relative">
                    <h3 class="font-extrabold text-lg md:text-xl text-slate-800 mb-6">Device Status</h3>
                    <div class="h-[250px] md:h-[300px] w-full relative flex justify-center items-center">
                        <canvas id="deviceChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Customers Table -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 w-full overflow-hidden">
                <div class="p-5 md:p-6 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white">
                    <div>
                        <h3 class="font-extrabold text-lg md:text-xl text-slate-800">Recent Customers</h3>
                        <p class="text-slate-500 text-xs mt-1">Latest customer registrations and their device status.</p>
                    </div>
                    <button class="w-full sm:w-auto bg-slate-900 hover:bg-slate-800 text-white px-5 py-2.5 rounded-xl transition text-sm font-semibold shadow-sm">
                        View All Customers
                    </button>
                </div>
                
                <div class="overflow-x-auto w-full">
                    <table class="w-full min-w-[600px] text-left text-sm border-collapse whitespace-nowrap">
                        <thead class="bg-slate-50/80 text-slate-500 font-semibold text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-4">Customer Name</th>
                                <th class="px-6 py-4">Device IMEI</th>
                                <th class="px-6 py-4">Package</th>
                                <th class="px-6 py-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @forelse($recentCustomers as $c)
                                <tr class="hover:bg-slate-50/80 transition-colors duration-200">
                                    <td class="px-6 py-4 font-bold text-slate-900">{{ $c->full_name }}</td>
                                    <td class="px-6 py-4 font-mono text-slate-500 text-xs">{{ $c->imei_number ?? '-' }}</td>
                                    <td class="px-6 py-4 font-medium">{{ $c->subscription_period ?? '-' }}</td>
                                    <td class="px-6 py-4 text-center">
                                        @if($c->payment_status === 'paid')
                                            <span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 px-3 py-1 rounded-full text-xs font-bold shadow-sm">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 bg-amber-50 text-amber-700 border border-amber-200 px-3 py-1 rounded-full text-xs font-bold shadow-sm">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Pending
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center text-slate-400 bg-slate-50/50">
                                        <div class="flex flex-col items-center justify-center">
                                            <svg class="w-10 h-10 mb-3 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                                            No customers synced yet.
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
    let customerChartInstance = null;
    let deviceChartInstance   = null;

    function buildCharts() {
        const ctxLineElement = document.getElementById('customerChart');
        if(!ctxLineElement) return;

        const ctxLine = ctxLineElement.getContext('2d');
        
        // Create a beautiful gradient for the line chart
        let gradientFill = ctxLine.createLinearGradient(0, 0, 0, 300);
        gradientFill.addColorStop(0, 'rgba(59, 130, 246, 0.4)'); // Blue
        gradientFill.addColorStop(1, 'rgba(59, 130, 246, 0.0)');

        Chart.defaults.font.family = "'Inter', 'sans-serif'";
        Chart.defaults.color = '#64748b';

        if (customerChartInstance) customerChartInstance.destroy();
        if (deviceChartInstance)   deviceChartInstance.destroy();

        // 1. Line Chart Construction
        customerChartInstance = new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: @json($customerGrowthLabels),
                datasets: [{
                    label: 'Customers',
                    data: @json($customerGrowthData),
                    borderColor: '#3b82f6', // Tailwind blue-500
                    backgroundColor: gradientFill,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4, // Smooth curves
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#3b82f6',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#3b82f6',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        padding: 12,
                        titleFont: { size: 13 },
                        bodyFont: { size: 14, weight: 'bold' },
                        displayColors: false,
                        cornerRadius: 8
                    }
                },
                scales: {
                    x: {
                        ticks:  { color: '#94a3b8', font: { size: 12 } },
                        grid:   { display: false },
                        border: { display: false }
                    },
                    y: {
                        ticks:  { color: '#94a3b8', font: { size: 12 }, padding: 10 },
                        grid:   { color: '#f1f5f9', borderDash: [5, 5] },
                        border: { display: false },
                        beginAtZero: true
                    }
                }
            }
        });

        // 2. Doughnut Chart Construction
        const ctxDoughnut = document.getElementById('deviceChart');

        deviceChartInstance = new Chart(ctxDoughnut, {
            type: 'doughnut',
            data: {
                labels: ['Activated', 'Not Activated', 'Temporarily Stopped'],
                datasets: [{
                    data: [
                        {{ $activatedDevices }},
                        {{ $pendingDevices }},
                        {{ $stoppedDevices }}
                    ],
                    backgroundColor: [
                        '#10b981',   // Emerald
                        '#f59e0b',   // Amber
                        '#ef4444'    // Red
                    ],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: 10
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 12, weight: '500' }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        padding: 12,
                        bodyFont: { size: 13 },
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                return ' ' + context.label + ": " + context.raw;
                            }
                        }
                    }
                },
                cutout: '70%' // Makes it look thinner and more modern
            }
        });
    }

    // Initialize charts on window load execution
    window.addEventListener('DOMContentLoaded', () => {
        buildCharts();
    });
</script>

</body>
</html>