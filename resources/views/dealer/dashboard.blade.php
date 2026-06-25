<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Dealer</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-white">

<div class="flex h-screen">

    <!-- Sidebar -->
    <aside class="w-72 h-screen bg-[#0B1B3F] text-white fixed left-0 top-0 overflow-y-auto">

        <div class="p-5 border-b border-blue-800 flex flex-col items-center text-center">

            <div class="w-20 h-20 rounded-full bg-white flex items-center justify-center overflow-hidden mb-3 shadow-md">
                <svg width="60" height="60" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="100" cy="100" r="98" fill="#0B1B3F"/>
                    <circle cx="100" cy="78" r="32" fill="#FFFFFF"/>
                    <path d="M40 170c0-38 26-62 60-62s60 24 60 62c0 8-6 8-6 8H46s-6 0-6-8z" fill="#FFFFFF"/>
                </svg>
            </div>

            <h1 class="text-3xl font-bold">ShaloTrack</h1>
            <p class="text-sm text-gray-300">Dealer Portal</p>

        </div>

        <nav class="px-3 pb-5 mt-2">

            <!-- DASHBOARD -->
            <a href="{{ route('dealer.dashboard') }}"
               class="block p-3 rounded text-white hover:bg-blue-900">
                Dashboard
            </a>

            <!-- CUSTOMERS -->
            <div x-data="{open:false}">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                    <span>Customers</span>
                    <svg :class="open ? 'rotate-180' : ''"
                         class="w-4 h-4 transition-transform duration-200"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm">
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Customer List</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Add Customer</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Customer Details</a>
                </div>
            </div>

            <!-- ACTIVATIONS -->
            <div x-data="{open:false}">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                    <span>Activations</span>
                    <svg :class="open ? 'rotate-180' : ''"
                         class="w-4 h-4 transition-transform duration-200"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm">
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">New Activation</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Activation List</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Pending Activations</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">SIM Activation</a>
                </div>
            </div>

            <!-- SALES -->
            <div x-data="{open:false}">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                    <span>Sales</span>
                    <svg :class="open ? 'rotate-180' : ''"
                         class="w-4 h-4 transition-transform duration-200"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm">
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">New Sale</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Sales History</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Sales Summary</a>
                </div>
            </div>

            <!-- REPORTS -->
            <div x-data="{open:false}">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                    <span>Reports</span>
                    <svg :class="open ? 'rotate-180' : ''"
                         class="w-4 h-4 transition-transform duration-200"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm">
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Daily Report</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Monthly Report</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Sales Report</a>
                    <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Activation Report</a>
                </div>
            </div>

        </nav>

    </aside>

    <!-- Main Content -->
    <div class="flex-1 ml-72 bg-white min-h-screen">

        <header class="bg-white shadow-lg px-6 py-4 flex items-center justify-between">

            <h2 class="text-2xl font-bold">
                @yield('title')
            </h2>

            <!-- User Dropdown -->
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

                    <a href="{{ route('dealer.profile') }}"
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

        <main class="p-6">
            @yield('content')
        </main>

    </div>

</div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

</body>
</html>