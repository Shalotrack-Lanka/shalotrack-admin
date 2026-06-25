<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-white">

<div class="flex h-screen">

    <!-- Sidebar -->
    <aside
    class="w-72 h-screen bg-[#0B1B3F] text-white fixed left-0 top-0 overflow-y-auto">


       <div class="p-5 border-b border-blue-800 flex flex-col items-center text-center">

     <div class="w-20 h-20 rounded-full bg-white flex items-center justify-center overflow-hidden mb-3 shadow-md">
        <svg width="60" height="60" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
            <circle cx="100" cy="100" r="98" fill="#0B1B3F"/>
            <circle cx="100" cy="78" r="32" fill="#FFFFFF"/>
            <path d="M40 170c0-38 26-62 60-62s60 24 60 62c0 8-6 8-6 8H46s-6 0-6-8z" fill="#FFFFFF"/>
        </svg>
    </div>

    <h1 class="text-3xl font-bold">ShaloTrack</h1>
    <p class="text-sm text-gray-300">Admin Portal</p>

</div>

    
       <!-- <div class="p-4">
            <div class="bg-white/10 p-3 rounded-lg backdrop-blur-sm">
                <div class="font-semibold text-white">
                    {{ Auth::user()->full_name }}
                </div>

                <div class="text-sm text-gray-300">
                    {{ Auth::user()->role }}
                </div>
            </div>
        </div>

        <nav class="px-3 pb-5">

            <a href="{{ route('admin.dashboard') }}"
               class="block p-3 rounded text-white hover:bg-blue-900">
                Dashboard
            </a> -->

            <!-- MASTER PAGES -->
            <div x-data="{open:false}">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between p-3 text-white hover:bg-blue-900 rounded">

                    <span>Master Pages</span>
                    <svg :class="open ? 'rotate-180' : ''"
                 class="w-4 h-4 transition-transform duration-200"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm">

                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Products</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Features</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition duration-200">Price Groups</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition duration-200">Price Group Details</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition duration-200">Change Products</a>

                </div>
            </div>

            <!-- SUPPLIERS -->
            <div x-data="{open:false}">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between p-3 text-white hover:bg-blue-900 rounded">

                    <span>Suppliers</span>
                    <svg :class="open ? 'rotate-180' : ''"
                 class="w-4 h-4 transition-transform duration-200"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm">

                    <a href="#" class="block py-2 text-white hover:bg-blue-900 transition">Add Supplier</a>
                    <a href="#" class="block py-2 text-white hover:bg-blue-900 transition">Purchase Records</a>
                    <a href="#" class="block py-2 text-white hover:bg-blue-900 transition">Upload Purchase Stock</a>

                </div>
            </div>

            <!-- DEALERS -->
            <div x-data="{open:false}">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between p-3 text-white hover:bg-blue-900 rounded">

                    <span>Dealers</span>
                    <svg :class="open ? 'rotate-180' : ''"
                 class="w-4 h-4 transition-transform duration-200"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm">

                    <a href="#" class="block py-2 text-white hover:bg-blue-900">Add Dealer</a>
                    <a href="#" class="block py-2 text-white hover:bg-blue-900">Distributor</a>
                    <a href="#" class="block py-2 text-white hover:bg-blue-900">LBC</a>
                    <a href="#" class="block py-2 text-white hover:bg-blue-900">Retailer</a>

                </div>
            </div>

            <!-- DEVICE MANAGEMENT -->
            <div x-data="{open:false}">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between p-3 text-white hover:bg-blue-900 rounded">

                    <span>Device Management</span>
                   <svg :class="open ? 'rotate-180' : ''"
                 class="w-4 h-4 transition-transform duration-200"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm">

                    <a href="#" class="block py-2 text-white hover:text-gray-300">Device List</a>
                    <a href="#" class="block py-2 text-white hover:text-gray-300">SIM Activation</a>
                    <a href="#" class="block py-2 text-white hover:text-gray-300">Testing Devices</a>

                </div>
            </div>

            <!-- ACTIVATIONS -->
            <a href="#" class="block p-3 rounded text-white hover:bg-blue-900">
                Activations
            </a>

            <!-- COMPLAINTS -->
            <div x-data="{open:false}">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between p-3 text-white hover:bg-blue-900 rounded">

                    <span>Complaints & Enquiries</span>
                    <svg :class="open ? 'rotate-180' : ''"
                 class="w-4 h-4 transition-transform duration-200"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm">

                    <a href="#" class="block py-2 text-white hover:text-gray-300">App Comments</a>
                    <a href="#" class="block py-2 text-white hover:text-gray-300">Support Tickets</a>

                </div>
            </div>

            <!-- TROUBLESHOOTING -->
            <div x-data="{open:false}">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between p-3 text-white hover:bg-blue-900 rounded">

                    <span>Troubleshooting</span>
                    <svg :class="open ? 'rotate-180' : ''"
                 class="w-4 h-4 transition-transform duration-200"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm">

                    <a href="#" class="block py-2 text-white hover:text-gray-300">Search IMEI</a>
                    <a href="#" class="block py-2 text-white hover:text-gray-300">Search Mobile No</a>
                    <a href="#" class="block py-2 text-white hover:text-gray-300">Search SIM No</a>
                    <a href="#" class="block py-2 text-white hover:text-gray-300">Send Commands</a>
                    <a href="#" class="block py-2 text-white hover:text-gray-300">Reboot Device</a>

                </div>
            </div>

            <a href="#" class="block p-3 rounded text-white hover:bg-blue-900">
                Reports
            </a>

            <a href="#" class="block p-3 rounded text-white hover:bg-blue-900">
                User Management
            </a>

        </nav>

    </aside>

    <!-- Main Content -->
    <div class="flex-1  bg-white min-h-screen pl-72">

        <header class="bg-white shadow-lg px-6 py-4 flex items-center justify-between">

    <h2 class="text-2xl font-bold">
        @yield('title')
    </h2>

    <!-- User dropdown -->
    <div x-data="{open:false}" class="relative">

        <button
            @click="open=!open"
            @click.outside="open=false"
            class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-100 transition">

            <div class="w-9 h-9 rounded-full bg-[#0B1B3F] text-white flex items-center justify-center font-semibold text-sm">
                {{ strtoupper(substr(Auth::user()->full_name, 0, 1)) }}
            </div>

            <span class="font-medium text-gray-700">{{ Auth::user()->full_name }}</span>

            <svg :class="open ? 'rotate-180' : ''"
                 class="w-4 h-4 text-gray-500 transition-transform duration-200"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>

        </button>

        <div x-show="open"
             x-transition
             class="absolute right-0 mt-2 w-48 bg-white border border-gray-200 rounded-lg shadow-lg z-50">

            <div class="px-4 py-3 border-b border-gray-100">
                <p class="font-semibold text-gray-800">{{ Auth::user()->full_name }}</p>
                <p class="text-sm text-gray-500">{{ Auth::user()->role }}</p>
            </div>

            <a href="{{ route('admin.profile') }}"
               class="block px-4 py-2 text-gray-700 hover:bg-gray-100">
                Profile
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button
                    type="submit"
                    class="w-full text-left px-4 py-2 text-red-600 hover:bg-gray-100">
                    Logout
                </button>
            </form>

        </div>

    </div>

</header>

        <main class="px-10 p-6">
            @yield('content')
        </main>


         
     <!-- Dashboard Statistics -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8 max-w-7xl mx-auto px-4">
    <div class="bg-gradient-to-r from-blue-900 to-blue-700 text-white rounded-xl shadow-md p-4 hover:-translate-y-1 transition duration-300">        <p class="text-blue-100">Total Customers</p>
        <h2 class="text-4xl font-bold mt-2">1,245</h2>
        <p class="text-sm mt-2 text-blue-200">+12% this month</p>
    </div>

    <div class="bg-gradient-to-r from-green-600 to-green-500 text-white rounded-xl shadow-md p-4 hover:-translate-y-1 transition duration-300">
        <p class="text-green-100">Active Devices</p>
        <h2 class="text-4xl font-bold mt-2">987</h2>
        <p class="text-sm mt-2 text-green-100">Online Now</p>
    </div>

    <div class="bg-gradient-to-r from-orange-500 to-orange-400 text-white rounded-xl shadow-md p-4 hover:-translate-y-1 transition duration-300">
        <p class="text-orange-100">Monthly Revenue</p>
        <h2 class="text-4xl font-bold mt-2">LKR 450K</h2>
        <p class="text-sm mt-2 text-orange-100">+8% Growth</p>
     </div>

    <div class="bg-gradient-to-r from-red-600 to-red-500 text-white rounded-xl shadow-md p-4 hover:-translate-y-1 transition duration-300">
        <p class="text-red-100">Support Tickets</p>
        <h2 class="text-4xl font-bold mt-2">24</h2>
        <p class="text-sm mt-2 text-red-100">Needs Attention</p>
    </div>

</div>
    

        <!-- Charts Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 px-4">

        <div class="bg-white rounded-xl shadow-lg p-6 hover:shadow-2xl transition duration-300">
            <h3 class="font-bold text-lg text-gray-700 mb-4">
                Customer Growth
            </h3>
            <div class="h-[280px]">
            <canvas id="customerChart"></canvas>
            </div>

        </div>

        <div class="bg-white rounded-2xl shadow-lg p-6 hover:shadow-2xl transition duration-300">
            <h3 class="font-bold text-lg text-gray-700 mb-4">
                Device Status
            </h3>
            <div class="h-[280px]">
                <canvas id="deviceChart"></canvas>
            </div>
        </div>

    </div>

     <!-- Recent Customers -->
   <div class="bg-white rounded-2xl shadow-lg p-6">

    <div class="flex justify-between items-center mb-4">

        <h3 class="font-bold text-xl text-gray-800">
            Recent Customers
        </h3>

        <button class="bg-blue-900 text-white px-4 py-2 rounded-lg hover:bg-blue-800 transition">
            View All
        </button>

    </div>

    <div class="overflow-x-auto">

        <table class="w-full">

            <thead>
                <tr class="bg-gray-100">
                    <th class="text-left p-4">Customer</th>
                    <th class="text-left p-4">Device</th>
                    <th class="text-left p-4">Package</th>
                    <th class="text-left p-4">Status</th>
                </tr>
            </thead>

            <tbody>

                <tr class="border-b hover:bg-gray-50 transition">
                    <td class="p-4">Kasun Perera</td>
                    <td>ST1001</td>
                    <td>Premium</td>
                    <td>
                        <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm">
                            Active
                        </span>
                    </td>
                </tr>

                <tr class="border-b hover:bg-gray-50 transition">
                    <td class="p-4">Nuwan Silva</td>
                    <td>ST1002</td>
                    <td>Basic</td>
                    <td>
                        <span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-sm">
                            Pending
                        </span>
                    </td>
                </tr>

                <tr class="hover:bg-gray-50 transition">
                    <td class="p-4">Amal Fernando</td>
                    <td>ST1003</td>
                    <td>Premium</td>
                    <td>
                        <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm">
                            Active
                        </span>
                    </td>
                </tr>

            </tbody>

        </table>

    </div>

</div>

    </div>

    

</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>

new Chart(document.getElementById('customerChart'), {
    type: 'line',
    data: {
        labels: ['Jan','Feb','Mar','Apr','May','Jun'],
        datasets: [{
            label: 'Customers',
            data: [100,150,220,350,500,700],
            borderColor: '#0B1B3F',
            backgroundColor: 'rgba(11,27,63,0.15)',
            fill: true,
            tension: 0.4
        }]
    }
});

new Chart(document.getElementById('deviceChart'), {
    type: 'doughnut',
    data: {
        labels: ['Active','Inactive','Testing'],
        datasets: [{
            data: [70,20,10],
            backgroundColor: [
                '#16a34a',
                '#f59e0b',
                '#dc2626'
            ]
        }]
    }
});

</script>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

</body>
</html>