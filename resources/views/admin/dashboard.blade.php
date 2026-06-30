<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Dashboard</title>

    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        /* ── LIGHT MODE (default) ── */
        body { background:#f1f5f9; color:#1e293b; }
        .main-content { background:#f1f5f9; }
        .header-bar { background:#ffffff; border-bottom:1px solid #e2e8f0; }
        .card { background:#ffffff; border:1px solid #e2e8f0; color:#1e293b; }
        .card h3 { color:#1e293b; }
        .table-head tr { background:#f1f5f9; }
        .table-head th { color:#1e293b; border-bottom:1px solid #e2e8f0; }
        .table-body td { color:#1e293b; border-bottom:1px solid #e2e8f0; }
        .table-body tr:hover { background:#f8fafc; }
        .username-text { color:#374151; }
        .dropdown-menu { background:#ffffff; border:1px solid #e2e8f0; }
        .dropdown-item { color:#374151; }
        .dropdown-item:hover { background:#f1f5f9; }
        .dropdown-info p { color:#1e293b; }
        .dropdown-info span { color:#6b7280; }
        .page-title { color:#1e293b; }
        .toggle-btn { background:#f1f5f9; border:1px solid #e2e8f0; }
        .chart-card { background:#ffffff; border:1px solid #e2e8f0; }
        .chart-card h3 { color:#1e293b; }

        /* ── DARK MODE ── */
        .dark body { background:#0f172a; color:#f1f5f9; }
        .dark .main-content { background:#0f172a; }
        .dark .header-bar { background:#1e293b; border-bottom:1px solid #334155; }
        .dark .card { background:#1e293b; border:1px solid #334155; color:#f1f5f9; }
        .dark .card h3 { color:#f1f5f9; }
        .dark .table-head tr { background:#0f172a; }
        .dark .table-head th { color:#f1f5f9; border-bottom:1px solid #334155; }
        .dark .table-body td { color:#f1f5f9; border-bottom:1px solid #334155; }
        .dark .table-body tr:hover { background:#334155; }
        .dark .username-text { color:#f1f5f9; }
        .dark .dropdown-menu { background:#1e293b; border:1px solid #334155; }
        .dark .dropdown-item { color:#f1f5f9; }
        .dark .dropdown-item:hover { background:#334155; }
        .dark .dropdown-info p { color:#f1f5f9; }
        .dark .dropdown-info span { color:#94a3b8; }
        .dark .page-title { color:#f1f5f9; }
        .dark .toggle-btn { background:#334155; border:1px solid #475569; }
        .dark .chart-card { background:#1e293b; border:none; }
        .dark .chart-card h3 { color:#ffffff; }
    </style>
</head>
<body x-data="{ sidebarOpen: false }" class="font-sans antialiased transition-colors duration-200">

<div class="flex h-screen overflow-hidden"> @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col h-screen overflow-y-auto main-content">

        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 w-full max-w-full">
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8 w-full">
                <div class="bg-gradient-to-r from-blue-900 to-blue-700 text-white rounded-xl shadow-md p-5 hover:-translate-y-1 transition duration-300">
                    <p class="text-blue-100 text-sm font-medium">Total Customers</p>
                    <h2 class="text-3xl md:text-4xl font-bold mt-2">1,245</h2>
                    <p class="text-xs md:text-sm mt-2 text-blue-200">+12% this month</p>
                </div>
                <div class="bg-gradient-to-r from-green-600 to-green-500 text-white rounded-xl shadow-md p-5 hover:-translate-y-1 transition duration-300">
                    <p class="text-green-100 text-sm font-medium">Active Devices</p>
                    <h2 class="text-3xl md:text-4xl font-bold mt-2">987</h2>
                    <p class="text-xs md:text-sm mt-2 text-green-100">Online Now</p>
                </div>
                <div class="bg-gradient-to-r from-orange-500 to-orange-400 text-white rounded-xl shadow-md p-5 hover:-translate-y-1 transition duration-300">
                    <p class="text-orange-100 text-sm font-medium">Monthly Revenue</p>
                    <h2 class="text-3xl md:text-4xl font-bold mt-2">LKR 450K</h2>
                    <p class="text-xs md:text-sm mt-2 text-orange-100">+8% Growth</p>
                </div>
                <div class="bg-gradient-to-r from-red-600 to-red-500 text-white rounded-xl shadow-md p-5 hover:-translate-y-1 transition duration-300">
                    <p class="text-red-100 text-sm font-medium">Support Tickets</p>
                    <h2 class="text-3xl md:text-4xl font-bold mt-2">24</h2>
                    <p class="text-xs md:text-sm mt-2 text-red-100">Needs Attention</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 w-full">
                <div class="chart-card rounded-xl shadow-md p-4 md:p-6 w-full">
                    <h3 class="font-bold text-base md:text-lg mb-4">Customer Growth</h3>
                    <div class="h-[250px] md:h-[280px] w-full relative">
                        <canvas id="customerChart"></canvas>
                    </div>
                </div>
                <div class="chart-card rounded-xl shadow-md p-4 md:p-6 w-full">
                    <h3 class="font-bold text-base md:text-lg mb-4">Device Status</h3>
                    <div class="h-[250px] md:h-[280px] w-full relative">
                        <canvas id="deviceChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="card rounded-xl shadow-md p-4 md:p-6 mb-6 w-full">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-5">
                    <h3 class="font-bold text-lg md:text-xl">Recent Customers</h3>
                    <button class="w-full sm:w-auto bg-blue-900 hover:bg-blue-800 text-white px-5 py-2 rounded-lg transition text-sm font-semibold shadow-sm">
                        View All
                    </button>
                </div>
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-shrink w-full">
                    <table class="w-full min-w-[600px] text-left text-sm border-collapse">
                        <thead class="table-head font-bold">
                            <tr>
                                <th class="p-3 md:p-4">Customer</th>
                                <th class="p-3 md:p-4">Device</th>
                                <th class="p-3 md:p-4">Package</th>
                                <th class="p-3 md:p-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-medium">
                            <tr>
                                <td class="p-3 md:p-4 font-semibold">Kasun Perera</td>
                                <td class="p-3 md:p-4 font-mono">ST1001</td>
                                <td class="p-3 md:p-4">Premium</td>
                                <td class="p-3 md:p-4 text-center"><span class="bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 px-3 py-1 rounded-full text-xs font-bold">Active</span></td>
                            </tr>
                            <tr>
                                <td class="p-3 md:p-4 font-semibold">Nuwan Silva</td>
                                <td class="p-3 md:p-4 font-mono">ST1002</td>
                                <td class="p-3 md:p-4">Basic</td>
                                <td class="p-3 md:p-4 text-center"><span class="bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 px-3 py-1 rounded-full text-xs font-bold">Pending</span></td>
                            </tr>
                            <tr>
                                <td class="p-3 md:p-4 font-semibold">Amal Fernando</td>
                                <td class="p-3 md:p-4 font-mono">ST1003</td>
                                <td class="p-3 md:p-4">Premium</td>
                                <td class="p-3 md:p-4 text-center"><span class="bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 px-3 py-1 rounded-full text-xs font-bold">Active</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    let customerChartInstance = null;
    let deviceChartInstance   = null;

    function isDark() {
        return document.documentElement.classList.contains('dark');
    }

    function chartColors() {
        const dark = isDark();
        return {
            text:     dark ? '#ffffff' : '#1e293b',
            grid:     dark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)',
            border:   dark ? '#334155' : '#e2e8f0',
            line:     dark ? '#38bdf8' : '#0B1B3F',
            lineFill: dark ? 'rgba(56,189,248,0.1)' : 'rgba(11,27,63,0.05)',
        };
    }

    function buildCharts() {
        const c = chartColors();

        Chart.defaults.color       = c.text;
        Chart.defaults.borderColor = c.grid;

        if (customerChartInstance) customerChartInstance.destroy();
        if (deviceChartInstance)   deviceChartInstance.destroy();

        // 1. Line Chart Construction
        customerChartInstance = new Chart(document.getElementById('customerChart'), {
            type: 'line',
            data: {
                labels: ['Jan','Feb','Mar','Apr','May','Jun'],
                datasets: [{
                    label: 'Customers',
                    data: [100,150,220,350,500,700],
                    borderColor:          c.line,
                    backgroundColor:      c.lineFill,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: c.line,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        ticks:  { color: c.text, font: { size: 11 } },
                        grid:   { display: false }
                    },
                    y: {
                        ticks:  { color: c.text, font: { size: 11 } },
                        grid:   { color: c.grid }
                    }
                }
            }
        });

        // 2. Doughnut Chart Construction
        deviceChartInstance = new Chart(document.getElementById('deviceChart'), {
            type: 'doughnut',
            data: {
                labels: ['Active','Inactive','Testing'],
                datasets: [{
                    data: [70, 20, 10],
                    backgroundColor: ['#16a34a','#f59e0b','#dc2626'],
                    borderColor:     isDark() ? '#1e293b' : '#ffffff',
                    borderWidth: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            color: c.text,
                            font: { size: 12 },
                            padding: 15,
                            usePointStyle: true,
                        }
                    }
                },
                cutout: '70%'
            }
        });
    }

    // Initialize charts on window load execution
    window.addEventListener('DOMContentLoaded', () => {
        buildCharts();
    });
</script>

<script>
    const html = document.documentElement;

    function applyTheme(dark) {
        if (dark) {
            html.classList.add('dark');
        } else {
            html.classList.remove('dark');
        }
        // Rebuild charts to catch color switch variants dynamically
        if (typeof buildCharts === 'function') {
            setTimeout(buildCharts, 50);
        }
    }

    // Set layout initialization based on stored profile state parameters
    applyTheme(localStorage.getItem('theme') === 'dark');

    // Listener hook for modern custom theme button clicks
    document.addEventListener('click', (e) => {
        const target = e.target.closest('#themeToggle');
        if (target) {
            const nowDark = !html.classList.contains('dark');
            localStorage.setItem('theme', nowDark ? 'dark' : 'light');
            applyTheme(nowDark);
        }
    });
</script>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>