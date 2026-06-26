<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        /* ── LIGHT MODE (default) ── */
        body                          { background:#f1f5f9; color:#1e293b; }
        .main-content                 { background:#f1f5f9; }
        .header-bar                   { background:#ffffff; border-bottom:1px solid #e2e8f0; }
        .card                         { background:#ffffff; border:1px solid #e2e8f0; color:#1e293b; }
        .card h3                      { color:#1e293b; }
        .table-head tr                { background:#f1f5f9; }
        .table-head th                { color:#1e293b; border-bottom:1px solid #e2e8f0; }
        .table-body td                { color:#1e293b; border-bottom:1px solid #e2e8f0; }
        .table-body tr:hover          { background:#f8fafc; }
        .username-text                { color:#374151; }
        .dropdown-menu                { background:#ffffff; border:1px solid #e2e8f0; }
        .dropdown-item                { color:#374151; }
        .dropdown-item:hover          { background:#f1f5f9; }
        .dropdown-info p              { color:#1e293b; }
        .dropdown-info span           { color:#6b7280; }
        .page-title                   { color:#1e293b; }
        .toggle-btn                   { background:#f1f5f9; border:1px solid #e2e8f0; }
        .chart-card                   { background:#ffffff; border:1px solid #e2e8f0; }
        .chart-card h3                { color:#1e293b; }

        /* ── DARK MODE ── */
        .dark body                    { background:#0f172a; color:#f1f5f9; }
        .dark .main-content           { background:#0f172a; }
        .dark .header-bar             { background:#1e293b; border-bottom:1px solid #334155; }
        .dark .card                   { background:#1e293b; border:1px solid #334155; color:#f1f5f9; }
        .dark .card h3                { color:#f1f5f9; }
        .dark .table-head tr          { background:#0f172a; }
        .dark .table-head th          { color:#f1f5f9; border-bottom:1px solid #334155; }
        .dark .table-body td          { color:#f1f5f9; border-bottom:1px solid #334155; }
        .dark .table-body tr:hover    { background:#334155; }
        .dark .username-text          { color:#f1f5f9; }
        .dark .dropdown-menu          { background:#1e293b; border:1px solid #334155; }
        .dark .dropdown-item          { color:#f1f5f9; }
        .dark .dropdown-item:hover    { background:#334155; }
        .dark .dropdown-info p        { color:#f1f5f9; }
        .dark .dropdown-info span     { color:#94a3b8; }
        .dark .page-title             { color:#f1f5f9; }
        .dark .toggle-btn             { background:#334155; border:1px solid #475569; }
        .dark .chart-card             { background:#1e293b; border:none; }
        .dark .chart-card h3          { color:#ffffff; }
    </style>
</head>
<body x-data="{ sidebarOpen: false }"> <!-- Added Alpine state for Mobile Menu Toggle -->

<div class="flex h-screen overflow-hidden"> <!-- Prevent double scrollbars -->

    @include('partials.sidebars.admin')

    @include('partials.header')
   
            <!-- Statistics Cards (Responsive Grid: 1 col on mobile, 2 on tablet, 4 on desktop) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8 max-w-7xl mx-auto">
                <div class="bg-gradient-to-r from-blue-900 to-blue-700 text-white rounded-xl shadow-md p-4 hover:-translate-y-1 transition duration-300">
                    <p class="text-blue-100 text-sm md:text-base">Total Customers</p>
                    <h2 class="text-3xl md:text-4xl font-bold mt-2">1,245</h2>
                    <p class="text-xs md:text-sm mt-2 text-blue-200">+12% this month</p>
                </div>
                <div class="bg-gradient-to-r from-green-600 to-green-500 text-white rounded-xl shadow-md p-4 hover:-translate-y-1 transition duration-300">
                    <p class="text-green-100 text-sm md:text-base">Active Devices</p>
                    <h2 class="text-3xl md:text-4xl font-bold mt-2">987</h2>
                    <p class="text-xs md:text-sm mt-2 text-green-100">Online Now</p>
                </div>
                <div class="bg-gradient-to-r from-orange-500 to-orange-400 text-white rounded-xl shadow-md p-4 hover:-translate-y-1 transition duration-300">
                    <p class="text-orange-100 text-sm md:text-base">Monthly Revenue</p>
                    <h2 class="text-3xl md:text-4xl font-bold mt-2">LKR 450K</h2>
                    <p class="text-xs md:text-sm mt-2 text-orange-100">+8% Growth</p>
                </div>
                <div class="bg-gradient-to-r from-red-600 to-red-500 text-white rounded-xl shadow-md p-4 hover:-translate-y-1 transition duration-300">
                    <p class="text-red-100 text-sm md:text-base">Support Tickets</p>
                    <h2 class="text-3xl md:text-4xl font-bold mt-2">24</h2>
                    <p class="text-xs md:text-sm mt-2 text-red-100">Needs Attention</p>
                </div>
            </div>

            <!-- Charts (Responsive Grid: 1 col on mobile/tablet, 2 on desktop) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="chart-card rounded-xl shadow-lg p-4 md:p-6">
                    <h3 class="font-bold text-base md:text-lg mb-4">Customer Growth</h3>
                    <div class="h-[250px] md:h-[280px]">
                        <canvas id="customerChart"></canvas>
                    </div>
                </div>
                <div class="chart-card rounded-2xl shadow-lg p-4 md:p-6">
                    <h3 class="font-bold text-base md:text-lg mb-4">Device Status</h3>
                    <div class="h-[250px] md:h-[280px]">
                        <canvas id="deviceChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Customers Table -->
            <div class="card rounded-2xl shadow-lg p-4 md:p-6 mb-6">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4">
                    <h3 class="font-bold text-lg md:text-xl">Recent Customers</h3>
                    <button class="w-full sm:w-auto bg-blue-900 text-white px-4 py-2 rounded-lg hover:bg-blue-800 transition text-sm">
                        View All
                    </button>
                </div>
                <div class="overflow-x-auto rounded-lg">
                    <table class="w-full min-w-[500px]"> <!-- Set min-width to ensure no squeezing -->
                        <thead class="table-head">
                            <tr>
                                <th class="text-left p-3 md:p-4 text-sm">Customer</th>
                                <th class="text-left p-3 md:p-4 text-sm">Device</th>
                                <th class="text-left p-3 md:p-4 text-sm">Package</th>
                                <th class="text-left p-3 md:p-4 text-sm">Status</th>
                            </tr>
                        </thead>
                        <tbody class="table-body">
                            <tr>
                                <td class="p-3 md:p-4 text-sm">Kasun Perera</td>
                                <td class="p-3 md:p-4 text-sm">ST1001</td>
                                <td class="p-3 md:p-4 text-sm">Premium</td>
                                <td class="p-3 md:p-4 text-sm"><span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-medium">Active</span></td>
                            </tr>
                            <tr>
                                <td class="p-3 md:p-4 text-sm">Nuwan Silva</td>
                                <td class="p-3 md:p-4 text-sm">ST1002</td>
                                <td class="p-3 md:p-4 text-sm">Basic</td>
                                <td class="p-3 md:p-4 text-sm"><span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-xs font-medium">Pending</span></td>
                            </tr>
                            <tr>
                                <td class="p-3 md:p-4 text-sm">Amal Fernando</td>
                                <td class="p-3 md:p-4 text-sm">ST1003</td>
                                <td class="p-3 md:p-4 text-sm">Premium</td>
                                <td class="p-3 md:p-4 text-sm"><span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-medium">Active</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Chart.js -->
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
            grid:     dark ? 'rgba(255,255,255,0.3)' : 'rgba(0,0,0,0.1)',
            border:   dark ? '#ffffff' : '#1e293b',
            line:     dark ? '#60a5fa' : '#0B1B3F',
            lineFill: dark ? 'rgba(96,165,250,0.15)' : 'rgba(11,27,63,0.1)',
        };
    }

    function buildCharts() {
        const c = chartColors();

        Chart.defaults.color       = c.text;
        Chart.defaults.borderColor = c.grid;

        if (customerChartInstance) customerChartInstance.destroy();
        if (deviceChartInstance)   deviceChartInstance.destroy();

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
                    tension: 0.4,
                    pointBackgroundColor: c.line,
                    pointBorderColor:     c.border,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: c.text, font: { size: 12 }, boxWidth: 15, padding: 10 }
                    },
                    tooltip: {
                        titleColor: '#ffffff',
                        bodyColor:  '#ffffff',
                        backgroundColor: 'rgba(0,0,0,0.75)',
                        borderColor: 'rgba(255,255,255,0.2)',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        ticks:  { color: c.text, font: { size: 11 } },
                        grid:   { color: c.grid, lineWidth: 1 },
                        border: { color: c.border, width: 1 }
                    },
                    y: {
                        ticks:  { color: c.text, font: { size: 11 } },
                        grid:   { color: c.grid, lineWidth: 1 },
                        border: { color: c.border, width: 1 }
                    }
                }
            }
        });

        deviceChartInstance = new Chart(document.getElementById('deviceChart'), {
            type: 'doughnut',
            data: {
                labels: ['Active','Inactive','Testing'],
                datasets: [{
                    data: [70,20,10],
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
                        labels: {
                            color: c.text,
                            font: { size: 12 },
                            padding: 15,
                            usePointStyle: true,
                            pointStyleWidth: 10
                        }
                    },
                    tooltip: {
                        titleColor: '#ffffff',
                        bodyColor:  '#ffffff',
                        backgroundColor: 'rgba(0,0,0,0.75)',
                        borderColor: 'rgba(255,255,255,0.2)',
                        borderWidth: 1
                    }
                },
                cutout: '65%'
            }
        });
    }
</script>

<!-- Dark Mode Script -->
<script>
    const toggle   = document.getElementById('themeToggle');
    const moonIcon = document.getElementById('moonIcon');
    const sunIcon  = document.getElementById('sunIcon');
    const html     = document.documentElement;

    function applyTheme(dark) {
        if (dark) {
            html.classList.add('dark');
            moonIcon.classList.add('hidden');
            sunIcon.classList.remove('hidden');
        } else {
            html.classList.remove('dark');
            moonIcon.classList.remove('hidden');
            sunIcon.classList.add('hidden');
        }
        buildCharts();
    }

    applyTheme(localStorage.getItem('theme') === 'dark');

    toggle.addEventListener('click', () => {
        const nowDark = !html.classList.contains('dark');
        localStorage.setItem('theme', nowDark ? 'dark' : 'light');
        applyTheme(nowDark);
    });
</script>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>