@php
    $soon = 'flex items-center justify-between p-3 rounded text-blue-300/60 cursor-not-allowed select-none';
@endphp
<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
       class="w-72 h-screen bg-[#0B1B3F] text-white fixed left-0 top-0 overflow-y-auto z-50 transition-transform duration-300 lg:translate-x-0">

    <!-- Mobile Close Button -->
    <div class="flex justify-end p-4 lg:hidden">
        <button @click="sidebarOpen = false" class="text-white focus:outline-none" aria-label="Close menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <!-- Logo -->
    <div class="p-5 border-b border-blue-800 flex flex-col items-center text-center">
        <h1 class="text-3xl font-bold">ShaloTrack</h1>
        <p class="text-sm text-gray-300">Distributor Portal</p>
    </div>

    <nav class="px-3 pb-5 mt-2">

        <a href="{{ route('distributor.dashboard') }}"
           class="block p-3 rounded text-white hover:bg-blue-900 {{ request()->routeIs('distributor.dashboard') ? 'bg-blue-800' : '' }}">
            Dashboard
        </a>

        <!-- NETWORK -->
        <div x-data="{ open: {{ request()->routeIs('distributor.dealers', 'distributor.stock') ? 'true' : 'false' }} }">
            <button @click="open=!open" class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">
                <span>My Network</span>
                <svg :class="open ? 'rotate-180' : ''" class="w-4 h-4 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div x-show="open" class="ml-5 text-sm">
                <a href="{{ route('distributor.dealers') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('distributor.dealers') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">My Dealers</a>
                <a href="{{ route('distributor.stock') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('distributor.stock') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">My Stock</a>
            </div>
        </div>

        <!-- STOCK TRANSFER -->
        <div x-data="{ open: {{ request()->routeIs('distributor.transfer', 'distributor.transfers*') ? 'true' : 'false' }} }">
            <button @click="open=!open" class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">
                <span>Stock Transfer</span>
                <svg :class="open ? 'rotate-180' : ''" class="w-4 h-4 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div x-show="open" class="ml-5 text-sm">
                <a href="{{ route('distributor.transfer') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('distributor.transfer') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">Transfer to a Dealer</a>
                <a href="{{ route('distributor.transfers') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('distributor.transfers*') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">Transfer History</a>
            </div>
        </div>

        <!-- BILLING -->
        <a href="{{ route('distributor.billing.index') }}"
           class="block p-3 rounded text-white hover:bg-blue-900 {{ request()->routeIs('distributor.billing*') ? 'bg-blue-800' : '' }}">
            Billing &amp; Invoices
        </a>

        <!-- COMING SOON (shown so the roadmap is visible; not clickable) -->
        <p class="px-3 pt-4 pb-1 text-[11px] font-semibold uppercase tracking-wider text-blue-300/70">Coming soon</p>
        <div class="{{ $soon }}"><span>Activate &amp; Renew Devices</span><span class="text-[10px] rounded-full bg-blue-900 px-2 py-0.5">Soon</span></div>
        <div class="{{ $soon }}"><span>Device Commands</span><span class="text-[10px] rounded-full bg-blue-900 px-2 py-0.5">Soon</span></div>

        <!-- ACCOUNT -->
        <p class="px-3 pt-4 pb-1 text-[11px] font-semibold uppercase tracking-wider text-blue-300/70">Account</p>
        <a href="{{ route('distributor.profile') }}"
           class="block p-3 rounded text-white hover:bg-blue-900 {{ request()->routeIs('distributor.profile*') ? 'bg-blue-800' : '' }}">
            My Profile &amp; Password
        </a>
    </nav>
</aside>
