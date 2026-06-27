<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
       class="w-72 h-screen bg-[#0B1B3F] text-white fixed left-0 top-0 overflow-y-auto z-50 transition-transform duration-300 lg:translate-x-0">

    <!-- Mobile Close Button -->
    <div class="flex justify-end p-4 lg:hidden">
        <button @click="sidebarOpen = false" class="text-white focus:outline-none">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <!-- Logo -->
    <div class="p-5 border-b border-blue-800 flex flex-col items-center text-center">


        <h1 class="text-3xl font-bold">ShaloTrack</h1>
        <p class="text-sm text-gray-300">Admin Portal</p>

    </div>

    <nav class="px-3 pb-5 mt-2">

        <a href="{{ route('admin.dashboard') }}"
           class="block p-3 rounded text-white hover:bg-blue-900">
            Dashboard
        </a>

        <!-- MASTER PAGES -->
        <div x-data="{open:false}">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Master Pages</span>

                <svg :class="open ? 'rotate-180' : ''"
                     class="w-4 h-4 transition-transform duration-200"
                     fill="none"
                     stroke="currentColor"
                     viewBox="0 0 24 24">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M19 9l-7 7-7-7"/>

                </svg>

            </button>

            <div x-show="open" class="ml-5 text-sm">

                <a href="{{ route('admin.products') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Add Product
                </a>

                <a href="{{ route('admin.features') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Add Features
                </a>

                <a href="{{ route('admin.price-groups') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Add Price Group
                </a>

                <a href="{{ route('admin.price-group-details') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                   Add Price Group Details
                </a>

                <a href="{{ route('admin.change-product-code') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Change Product Codes
                </a>

            </div>

        </div>

        <!-- SUPPLIERS -->
        <div x-data="{open:false}">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Suppliers</span>

                <svg :class="open ? 'rotate-180' : ''"
                     class="w-4 h-4 transition-transform duration-200"
                     fill="none"
                     stroke="currentColor"
                     viewBox="0 0 24 24">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M19 9l-7 7-7-7"/>

                </svg>

            </button>

            <div x-show="open" class="ml-5 text-sm">

                <a href="{{ route('admin.suppliers') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Add Supplier
                </a>

                <a href="{{ route('admin.add-product-po') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Add Product PO and Stock Upload
                </a>

            </div>

        </div>

        <!-- DEALERS -->
        <div x-data="{open:false}">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Dealers</span>

                <svg :class="open ? 'rotate-180' : ''"
                     class="w-4 h-4 transition-transform duration-200"
                     fill="none"
                     stroke="currentColor"
                     viewBox="0 0 24 24">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M19 9l-7 7-7-7"/>

                </svg>

            </button>

            <div x-show="open" class="ml-5 text-sm">

                <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Add Dealer</a>
                <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Distributor</a>
                <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">LBC</a>
                <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Retailer</a>

            </div>

        </div>

        <!-- DEVICE MANAGEMENT -->
        <div x-data="{open:false}">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Device Management</span>

                <svg :class="open ? 'rotate-180' : ''"
                     class="w-4 h-4 transition-transform duration-200"
                     fill="none"
                     stroke="currentColor"
                     viewBox="0 0 24 24">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M19 9l-7 7-7-7"/>

                </svg>

            </button>

            <div x-show="open" class="ml-5 text-sm">

                <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Device List</a>
                <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">SIM Activation</a>
                <a href="#" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Testing Devices</a>

            </div>

        </div>

        <a href="#" class="block p-3 rounded text-white hover:bg-blue-900">
            Activations
        </a>

        <a href="#" class="block p-3 rounded text-white hover:bg-blue-900">
            Reports
        </a>

        <a href="#" class="block p-3 rounded text-white hover:bg-blue-900">
            User Management
        </a>

    </nav>

</aside>

<!-- Mobile Overlay -->
<div x-show="sidebarOpen"
     @click="sidebarOpen = false"
     class="fixed inset-0 bg-black/50 z-40 lg:hidden">
</div>