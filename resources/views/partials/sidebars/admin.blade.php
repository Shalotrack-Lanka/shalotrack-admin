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
           class="block p-3 rounded text-white hover:bg-blue-900 {{ request()->routeIs('admin.dashboard') ? 'bg-blue-800' : '' }}">
            Dashboard
        </a>

        <!-- MASTER PAGES -->
        <div x-data="{ open: {{ request()->is('admin/master-pages*') ? 'true' : 'false' }} }">

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

                <a href="{{ route('admin.add-device-type') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.add-device-type') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                     Add Device Types
                </a>

                <a href="{{ route('admin.add-sim') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.add-sim') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                    Add SIM
                </a>

                <a href="{{ route('admin.setup-device') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.setup-device') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                    Setup Shalotrack Device
                </a>

                <a href="{{ route('admin.stock_transfer') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.stock_transfer') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                    Stock Transfer
                </a>

             <!--   <a href="" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Add Features
                </a>

                <a href="" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                   Add Price Group Details
                </a>

                <a href="" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Change Product Codes
                </a>  -->

            </div>

        </div>

       <!-- CANCEL REQUESTS -->
        <div x-data="{ open: {{ request()->is('admin/cancel-requests*') ? 'true' : 'false' }} }">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Cancel Requests</span>

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

                <a href="{{ route('admin.cancel-device') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.cancel-device') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                   Cancel Device
                </a>

                <a href="{{ route('admin.cancel-sim') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.cancel-sim') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                   Cancel Sim
                </a>

            </div>

        </div>
        
        <!-- CUSTOMER -->
        <div x-data="{ open: {{ request()->is('admin/customer*') ? 'true' : 'false' }} }">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Customer</span>

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

                <a href="{{ route('admin.customer-setup') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.customer-setup') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                    Customer Setup
                </a>

                <a href="{{ route('admin.customer-device-management') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.customer-device-management') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                    Customer Device Management
                </a>

            </div>

        </div>
        
        <!-- VEHICLES -->
        <div x-data="{ open: {{ request()->is('admin/vehicles*') ? 'true' : 'false' }} }">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Vehicles</span>

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

                <a href="{{ route('admin.vehicles.details') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.vehicles.details') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                    Vehicle Details
                </a>
                <a href="{{ route('admin.vehicles.gps') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.vehicles.gps') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                    Gps Tracking
                </a>
                <a href="{{ route('admin.vehicles.device-commands') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.vehicles.device-commands') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                    Device Commands
                </a>

            </div>

        </div>


           <!-- DEALERS -->
        <div x-data="{ open: {{ request()->is('admin/dealer*') ? 'true' : 'false' }} }">

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

                <a href="{{ route('admin.dealer-management') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.dealer-management') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">Dealer Management</a>
                <a href="{{ route('admin.dealers.customer-ads') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.dealers.customer-ads') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">Dealer customers</a>
                <!--<a href="{{ route('admin.manage-replacement') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.manage-replacement') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">Manage Replacements</a>-->
                <!--<a href="{{ route('admin.dealer-ledger') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.dealer-ledger') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">Dealer Ledger</a>-->

            </div>

        </div>


              <!-- SUPPLIERS -->
        <div x-data="{ open: {{ request()->is('admin/supplier*') ? 'true' : 'false' }} }">

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

                <a href="{{ route('admin.suppliers') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.suppliers') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                     Supplier Management
                </a>

                <a href="{{ route('admin.supplier-invoice') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.supplier-invoice') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">
                    Supplier Invoice
                </a>

            </div>

        </div>


         <!-- STOCK MANAGEMENT -->
        <div x-data="{ open: {{ request()->is('admin/stock*') ? 'true' : 'false' }} }">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Stock</span>

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

                <a href="{{ route('admin.stock.manage') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.stock.manage') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">Add Stock</a>
               <!-- <a href="" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Sold Device Report</a>  -->
               <!-- <a href="" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Add Faulty Device</a> -->

            </div>

        </div>

        <!-- COMPLAINS & ENQUIRIES -->
            <div x-data="{ open: {{ request()->is('admin/complaints*') ? 'true' : 'false' }} }">
                <button
                    @click="open=!open"
                    class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">
                    
                    <div class="flex items-center space-x-2">
                        <span>Complains</span>
                        
                        <!-- Count එක 0 ට වඩා වැඩි නම් පමණක් Badge එක පෙන්වීම -->
                        @if(isset($complaintsCount) && $complaintsCount > 0)
                            <span class="inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-600 rounded-full">
                                {{ $complaintsCount }}
                            </span>
                        @endif
                    </div>

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

                <div x-show="open" class="ml-5 text-sm" style="display: none;">
                    <!--<a href="{{ route('admin.troubleshoot') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.troubleshoot') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">Troubleshoot</a>-->
                    <a href="{{ route('admin.complaints.index') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.complaints.*') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">View Complains</a>
                    <!--<a href="{{ route('admin.feedback') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.feedback') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">Feedbacks</a>-->
                    <!--<a href="{{ route('admin.device-replace-request') }}" class="block py-2 px-4 rounded-lg transition-colors {{ request()->routeIs('admin.device-replace-request') ? 'bg-blue-900 text-white font-semibold' : 'text-gray-300 hover:bg-blue-900 hover:text-white' }}">Device Replace Requests</a>-->
                </div>
            </div>

        {{-- ACTIVATION MANAGEMENT — hidden from sidebar for now, routes/controllers still exist and work if linked to directly
        <div x-data="{open:false}">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Activations</span>

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

                <a href="{{ route('admin.activation-report') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Activation Reports</a>
                <a href="{{ route('admin.customer-document-upload') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Customers Document upload</a>

            </div>

        </div>
        --}}

        {{-- REPORT MANAGEMENT — hidden from sidebar for now, routes/controllers still exist and work if linked to directly
        <div x-data="{open:false}">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Reports</span>

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

                <a href="{{ route('admin.stock-in-report') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Stock In Report</a>
                <a href="{{ route('admin.credit-invoice-report') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Credit Invoice Report</a>

            </div>

        </div>
        --}}
        
    </nav>

</aside>

<!-- Mobile Overlay -->
<div x-show="sidebarOpen"
     @click="sidebarOpen = false"
     class="fixed inset-0 bg-black/50 z-40 lg:hidden">
</div>


<!-- Transparent Apple-style Notification Toast Box (Clickable for Admin) -->
<div id="custom-toast" class="fixed top-5 right-5 z-50 transform translate-y-[-200%] transition-all duration-300 ease-in-out">
    <div class="flex items-center p-4 bg-white/80 dark:bg-gray-800/80 backdrop-blur-md border border-gray-200/50 dark:border-gray-700/50 rounded-2xl shadow-2xl space-x-3.5 w-80 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
        
        <!-- ක්ලික් කළ හැකි ප්‍රදේශය (Admin Complaints පිටුවට යයි) -->
        <div onclick="window.location.href='{{ route('admin.complaints.index') }}'" class="flex items-center flex-1 space-x-3.5 cursor-pointer">
            <div class="flex-shrink-0 bg-blue-500 text-white p-2.5 rounded-full shadow-md">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                </svg>
            </div>
            <div class="flex-1">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">ShaloTrack Alert</h4>
                <p id="toast-message" class="text-sm font-medium text-gray-900 dark:text-white">New escalated complaint!</p>
            </div>
        </div>

        <!-- Close Button -->
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
        const messageEl = document.getElementById('toast-message');

        if(customMessage) {
            messageEl.innerText = customMessage;
        }

        // Sound එක Play කිරීම
        if(sound) {
            sound.currentTime = 0;
            sound.play().catch(error => {
                console.log("Audio play blocked by browser policy until user clicks page: ", error);
            });
        }

        // Box එක පෙන්වීම
        if(toast) {
            toast.classList.remove('translate-y-[-200%]');
            toast.classList.add('translate-y-0');

            setTimeout(() => {
                hideToast();
            }, 6000);
        }
    }

    function hideToast() {
        const toast = document.getElementById('custom-toast');
        if(toast) {
            toast.classList.remove('translate-y-0');
            toast.classList.add('translate-y-[-200%]');
        }
    }

    // සෑම තත්පර 15 කට වරක්ම Admin ට අලුත්/Transfer කළ complain එකක් ඇවිදැයි බැලීම
    setInterval(() => {
        fetch("{{ route('admin.complaints.check-new') }}")
            .then(res => res.json())
            .then(data => {
                if(data.has_new) {
                    showToast("ඩීලර් විසින් පැමිණිල්ලක් මාරු කර ඇත!");
                }
            })
            .catch(err => console.error("Admin notification check error:", err));
    }, 15000);
</script>