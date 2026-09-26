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

{{-- 🔹 DYNAMIC NOTIFICATION LOGIC 🔹 --}}
@php
    $dealerId = null;
    if (Auth::check()) {
        $dealerId = Auth::user()->dealer->id ?? Auth::user()->dealer_id ?? null;
    }

    $pendingReminders = collect();
    $totalPendingCount = 0;

    if ($dealerId) {
        // Pending devices thiyena customers lawa gannawa
        $pendingCustomers = \App\Models\DealerCustomerAd::where('dealer_id', $dealerId)
            ->where('no_of_devices', '>', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        foreach($pendingCustomers as $cust) {
            $pendingReminders->push([
                'customer_name' => $cust->name,
                'pending' => $cust->no_of_devices,
                'message' => "{$cust->name} requires {$cust->no_of_devices} more device(s) to be assigned."
            ]);
            $totalPendingCount += $cust->no_of_devices;
        }
    }
@endphp


<!-- x-data added to manage sidebar state -->
<div x-data="{ sidebarOpen: false }" class="flex h-screen overflow-hidden">

    <!-- Mobile Overlay Backdrop -->
    <div x-show="sidebarOpen"
         x-transition.opacity
         style="display: none;"
         class="fixed inset-0 z-20 bg-black bg-opacity-50 lg:hidden"
         @click="sidebarOpen = false"></div>

    <!-- Sidebar -->
    <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
           class="w-72 bg-[#0B1B3F] text-white fixed inset-y-0 left-0 z-30 overflow-y-auto transition-transform duration-300 transform lg:translate-x-0 lg:static lg:inset-auto">

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
               class="block p-3 rounded text-white hover:bg-blue-900 {{ request()->routeIs('dealer.dashboard') ? 'bg-blue-800' : '' }}">
                Dashboard
            </a>

            <!-- CUSTOMERS DROPDOWN -->
            <div x-data="{open: {{ request()->is('dealer/customers*') ? 'true' : 'false' }} }">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded mt-1">

                    <span>Customers </span>
                    <svg :class="open ? 'rotate-180' : ''"
                         class="w-4 h-4 transition-transform duration-200"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm mt-1 space-y-1">
                    <a href="{{ route('dealer.customers.index') }}" class="block py-2 px-3 text-white hover:bg-blue-800 rounded-lg transition {{ request()->routeIs('dealer.customers.index') ? 'bg-blue-800' : '' }}">Customer List</a>
                </div>
            </div>

            <!-- TRACKING DROPDOWN (Aluthin add karapu eka) -->
            <div x-data="{open: {{ request()->routeIs('dealer.device-commands') || request()->routeIs('dealer.gps-tracking') ? 'true' : 'false' }} }" class="mt-1">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                    <span>Tracking </span>
                    <svg :class="open ? 'rotate-180' : ''"
                         class="w-4 h-4 transition-transform duration-200"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>

                </button>

                <div x-show="open" class="ml-5 text-sm mt-1 space-y-1">
                    {{-- Device Command Center Link --}}
                    <a href="{{ route('dealer.device-commands') }}"
                       class="block py-2 px-3 text-white hover:bg-blue-800 rounded-lg transition {{ request()->routeIs('dealer.device-commands') ? 'bg-blue-800' : '' }}">
                        Device Commands
                    </a>

                    {{-- GPS Tracking Link --}}
                    <a href="{{ route('dealer.gps-tracking') }}"
                       class="block py-2 px-3 text-white hover:bg-blue-800 rounded-lg transition {{ request()->routeIs('dealer.gps-tracking') ? 'bg-blue-800' : '' }}">
                        GPS Tracking
                    </a>
                </div>
            </div>

            {{-- NEW: Complaints link -- route/controller/view already existed
     (dealer.complaints, DealerComplaintController, dealer/complaints
     view) but nothing in this sidebar ever linked to them. --}}
            <a href="{{ route('dealer.complaints') }}" class="flex items-center justify-between p-3 text-white hover:bg-blue-900 rounded {{ request()->routeIs('dealer.complaints*') ? 'bg-blue-900' : '' }}">
                <div class="flex items-center space-x-2">
                    <span>Complains</span>

                    <!-- Show badge if there are unresolved complaints -->
                    @if(isset($complaintsCount) && $complaintsCount > 0)
                        <span class="inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-600 rounded-full">
                            {{ $complaintsCount }}
                        </span>
                    @endif
                </div>
            </a>
 
                      {{-- RESOLVED COMPLAINTS LINK --}}
            <a href="{{ route('dealer.complaints.resolved') }}" class="flex items-center justify-between p-3 text-white hover:bg-blue-900 rounded {{ request()->routeIs('dealer.complaints.resolved') ? 'bg-blue-900 font-bold' : '' }}">
                <div class="flex items-center space-x-2">
                    <span>Resolved Complaints</span>
                </div>
            </a>

        </nav>

    </aside>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col min-h-screen bg-gray-50 overflow-hidden">

        <header class="bg-white shadow-sm px-6 py-4 flex items-center justify-between z-10">

            <div class="flex items-center gap-4">
                <!-- Hamburger Menu Button for Mobile -->
                <button @click="sidebarOpen = true" class="text-slate-600 focus:outline-none lg:hidden">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7" />
                    </svg>
                </button>

                <h2 class="text-2xl font-bold text-slate-800">
                    @yield('title')
                </h2>
            </div>

            <!-- Header Right Section (Notification Bell + Profile Dropdown) -->
            <div class="flex items-center gap-4">

                {{-- 🔔 NOTIFICATION BELL DROPDOWN --}}
                <div x-data="{ openNotification: false }" class="relative">
                    <button @click="openNotification = !openNotification"
                            @click.outside="openNotification = false"
                            class="relative p-2.5 rounded-full bg-slate-100 text-slate-600 hover:bg-slate-200 transition focus:outline-none cursor-pointer">

                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>

                        {{-- Dynamic Red Count Badge --}}
                        @if($totalPendingCount > 0)
                            <span class="absolute -top-1 -right-1 bg-red-500 text-white font-black text-[10px] w-5 h-5 rounded-full flex items-center justify-center border-2 border-white animate-pulse">
                                {{ $totalPendingCount }}
                            </span>
                        @endif
                    </button>

                    {{-- Dropdown Menu --}}
                    <div x-show="openNotification"
                         x-cloak
                         style="display: none;"
                         x-transition
                         class="absolute right-0 mt-3 w-80 sm:w-96 bg-white rounded-2xl shadow-2xl border border-slate-100 text-slate-800 overflow-hidden z-50">

                        <div class="bg-[#0B1B3F] px-5 py-3.5 flex items-center justify-between text-white">
                            <div class="flex items-center gap-2">
                                <span>🔔</span>
                                <h4 class="font-bold text-sm">Pending Allocations</h4>
                            </div>
                            <span class="px-2.5 py-0.5 bg-blue-900 text-blue-200 rounded-full text-xs font-bold">
                                {{ $totalPendingCount }} Pending
                            </span>
                        </div>

                        <div class="max-h-80 overflow-y-auto divide-y divide-slate-100">
                            @if($pendingReminders->count() > 0)
                                @foreach($pendingReminders as $reminder)
                                    <div class="p-4 hover:bg-amber-50/50 transition flex items-start gap-3">
                                        <div class="p-1.5 bg-amber-100 text-amber-800 rounded-lg font-bold text-xs shrink-0 mt-0.5">
                                            ⚠️
                                        </div>
                                        <div class="space-y-1">
                                            <div class="flex items-center justify-between gap-2">
                                                <h5 class="text-xs font-black text-amber-900 uppercase tracking-wider">
                                                    {{ $reminder['customer_name'] }}
                                                </h5>
                                                <span class="text-[10px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full shrink-0">
                                                    Pending: {{ $reminder['pending'] }}
                                                </span>
                                            </div>
                                            <p class="text-[11px] font-medium text-slate-600 leading-relaxed mt-1">
                                                {{ $reminder['message'] }}
                                            </p>
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                <div class="p-8 text-center text-slate-400 text-xs font-medium space-y-2">
                                    <span class="text-2xl block">🎉</span>
                                    <span>All devices have been allocated successfully!</span>
                                </div>
                            @endif
                        </div>

                        <div class="p-2.5 bg-slate-50 border-t border-slate-100 text-center">
                            <button @click="openNotification = false" type="button" class="text-xs font-bold text-slate-500 hover:text-slate-800 transition">
                                Close
                            </button>
                        </div>
                    </div>
                </div>

                {{-- User Profile Dropdown --}}
                @auth
                <div x-data="{open:false}" class="relative">

                    <button
                        @click="open=!open"
                        @click.outside="open=false"
                        class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-100 transition">

                        <div class="w-9 h-9 rounded-full bg-[#0B1B3F] text-white flex items-center justify-center font-semibold text-sm">
                            {{ strtoupper(substr(Auth::user()->full_name ?? 'D', 0, 1)) }}
                        </div>

                        <span class="font-medium text-gray-700 hidden sm:inline-block">{{ Auth::user()->full_name ?? 'Dealer' }}</span>

                        <svg :class="open ? 'rotate-180' : ''"
                             class="w-4 h-4 text-gray-500 transition-transform duration-200"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>

                    </button>

                    <div x-show="open"
                         x-transition
                         style="display: none;"
                         class="absolute right-0 mt-2 w-48 bg-white border border-gray-200 rounded-lg shadow-lg z-50">

                        <div class="px-4 py-3 border-b border-gray-100">
                            <p class="font-semibold text-gray-800">{{ Auth::user()->full_name ?? 'Dealer' }}</p>
                            <p class="text-sm text-gray-500">{{ Auth::user()->role ?? 'Dealer Account' }}</p>
                        </div>

                        <a href="{{ route('dealer.profile.edit') }}"
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
                @else
                <a href="{{ route('login') }}" class="text-sm font-bold text-blue-600 hover:underline">Login</a>
                @endauth

            </div>

        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto p-6">
            @yield('content')
        </main>

    </div>

</div>

{{--
    Toast: hidden by default via inline style (transform: translateY(-200%)).
    We DO NOT use Tailwind's arbitrary-value class translate-y-[-200%] for
    the initial hidden state because classList.remove('translate-y-[-200%]')
    silently fails in most browsers — the brackets and percent sign cause the
    string-matching lookup to miss, so the hiding class is never removed and
    the toast stays invisible even though showToast() is called correctly.
    Using element.style.transform instead is guaranteed to work.
--}}
<div id="custom-toast"
     class="fixed top-5 right-5 z-50 transition-all duration-300 ease-in-out"
     style="transform: translateY(-200%);">
    <div class="flex items-center p-4 bg-white/80 dark:bg-gray-800/80 backdrop-blur-md border border-gray-200/50 dark:border-gray-700/50 rounded-2xl shadow-2xl space-x-3.5 w-80 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">

        <!-- ක්ලික් කළ හැකි ප්‍රදේශය (Dealer Complaints පිටුවට යයි) -->
        <div onclick="window.location.href='{{ route('dealer.complaints') }}'" class="flex items-center flex-1 space-x-3.5 cursor-pointer">
            <div class="flex-shrink-0 bg-blue-500 text-white p-2.5 rounded-full shadow-md">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                </svg>
            </div>
            <div class="flex-1">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">ShaloTrack Alert</h4>
                <p id="toast-message" class="text-sm font-medium text-gray-900 dark:text-white">New complaint received!</p>
            </div>
        </div>

        <!-- Close Button (මෙය එබූ විට ලින්ක් එකට යන්නේ නැත, Box එක පමණක් වැසේ) -->
        <button onclick="hideToast()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors ml-auto z-10 relative">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
    </div>
</div>

<!-- Apple-like 'tinnnn' Sound Audio Element -->
<audio id="notification-sound" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3" preload="auto"></audio>

<script>
    function showToast(customMessage) {
        const toast = document.getElementById('custom-toast');
        const sound = document.getElementById('notification-sound');
        const msgEl = document.getElementById('toast-message');

        if (customMessage) {
            msgEl.innerText = customMessage;
        }

        if (sound) {
            sound.currentTime = 0;
            sound.play().catch(err => {
                console.log("Audio blocked until first user interaction:", err);
            });
        }

        // FIX: use element.style.transform instead of toggling Tailwind
        // arbitrary-value classes. classList.remove('translate-y-[-200%]')
        // fails silently because the bracket/percent characters make the
        // DOMTokenList string lookup miss, leaving both classes on the element
        // and the hiding transform winning.
        if (toast) {
            toast.style.transform = 'translateY(0)';
            setTimeout(() => hideToast(), 6000);
        }
    }

    function hideToast() {
        const toast = document.getElementById('custom-toast');
        if (toast) {
            toast.style.transform = 'translateY(-200%)';
        }
    }

    // සෑම තත්පර 15 කට වරක්ම ඩීලර් කෙනෙකුට අලුත් complain එකක් ඇවිදැයි බැලීම
    setInterval(() => {
        fetch('/dealer/check-new-complaints')
            .then(res => res.json())
            .then(data => {
                if (data.has_new) {
                    showToast("You've Received a Complaint!");
                }
            })
            .catch(err => console.error("Dealer complaint notification error:", err));
    }, 1000);

    // සෑම තත්පර 20 කට වරක්ම Admin reply කළාදැයි බැලීම
    // (15s interval ට stagger කර ඇති නිසා API hits overlap නොවේ)
    setInterval(() => {
        fetch('/dealer/check-new-replies')
            .then(res => res.json())
            .then(data => {
                if (data.has_new_reply) {
                    const who = data.author_name || 'ShaloTrack Support';
                    showToast(who + " reply කර ඇත!");
                }
            })
            .catch(err => console.error("Dealer reply notification error:", err));
    }, 2000);
</script>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>