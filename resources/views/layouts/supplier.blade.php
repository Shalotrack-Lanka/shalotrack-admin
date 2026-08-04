<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Supplier</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-50">

<div class="flex h-screen">

    <!-- Sidebar -->
    <aside class="w-72 h-screen bg-blue-950 text-white fixed left-0 top-0 overflow-y-auto border-r-4 border-orange-500">

        <div class="p-6 border-b border-blue-800 flex flex-col items-center text-center">
            <div class="w-16 h-16 rounded-full bg-white flex items-center justify-center overflow-hidden mb-3 shadow-md">
                <svg width="44" height="44" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="100" cy="100" r="98" fill="#0B1B3F"/>
                    <circle cx="100" cy="78" r="32" fill="#FFFFFF"/>
                    <path d="M40 170c0-38 26-62 60-62s60 24 60 62c0 8-6 8-6 8H46s-6 0-6-8z" fill="#FFFFFF"/>
                </svg>
            </div>
            <h1 class="text-2xl font-extrabold tracking-tight">ShaloTrack</h1>
            <p class="text-xs text-blue-300 uppercase tracking-widest font-bold mt-1">Supplier Portal</p>
        </div>

        <nav class="px-3 pb-5 mt-4">
            <a href="{{ route('supplier.dashboard') }}"
               class="block p-3 rounded-xl text-white hover:bg-blue-900 font-semibold text-sm transition-colors">
                Dashboard
            </a>
            <a href="{{ route('supplier.profile') }}"
               class="block p-3 rounded-xl text-white hover:bg-blue-900 font-semibold text-sm transition-colors">
                Profile
            </a>
        </nav>

    </aside>

    <!-- Main Content -->
    <div class="flex-1 ml-72 bg-slate-50 min-h-screen">

        <header class="bg-white shadow-sm px-8 py-5 flex items-center justify-between border-b border-slate-200">

            <h2 class="text-xl font-extrabold text-blue-950">
                @yield('title')
            </h2>

            <!-- User Dropdown -->
            <div x-data="{open:false}" class="relative">

                <button
                    @click="open=!open"
                    @click.outside="open=false"
                    class="flex items-center gap-2 px-3 py-2 rounded-xl hover:bg-slate-100 transition">

                    <div class="w-9 h-9 rounded-full bg-blue-950 text-white flex items-center justify-center font-bold text-sm">
                        {{ strtoupper(substr(auth()->user()->full_name ?? '?', 0, 1)) }}
                    </div>

                    <span class="font-semibold text-slate-700 text-sm">{{ auth()->user()->full_name ?? 'Supplier' }}</span>

                    <svg :class="open ? 'rotate-180' : ''"
                         class="w-4 h-4 text-slate-400 transition-transform duration-200"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>

                </button>

                <div x-show="open"
                     x-transition
                     class="absolute right-0 mt-2 w-48 bg-white border border-slate-200 rounded-xl shadow-lg z-50">

                    <div class="px-4 py-3 border-b border-slate-100">
                        <p class="font-semibold text-slate-800 text-sm">{{ auth()->user()->full_name ?? 'Supplier' }}</p>
                        <p class="text-xs text-slate-500">{{ auth()->user()->role ?? '' }}</p>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-left px-4 py-2.5 text-red-600 hover:bg-slate-50 text-sm font-medium rounded-b-xl">
                            Logout
                        </button>
                    </form>

                </div>

            </div>

        </header>

        <main class="p-6 md:p-8">
            @yield('content')
        </main>

    </div>

</div>

</body>
</html>