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

                <a href="{{ route('admin.add-device') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Setup Shalotrack Device
                </a>

                <a href="{{ route('admin.add-sim') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Add SIM
                </a>

                <a href="{{ route('admin.cancel-device') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                   Cancel Device
                </a>

                <a href="{{ route('admin.cancel-sim') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                   Cancel Sim
                </a>

             <!--   <a href="" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Add Features
                </a> 

                <a href="" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Add Price Group
                </a>

                <a href="" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                   Add Price Group Details
                </a>

                <a href="" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Change Product Codes
                </a>  -->

            </div>

        </div>

                    <!-- ADMIN -->
        <div x-data="{open:false}">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Admin</span>

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

                <a href="{{ route('admin.add-device-types') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                     Add Device Types
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

                <a href="{{ route('admin.supplier-invoice') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Supplier Invoice
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

                <a href="{{ route('admin.add-dealer') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Add Dealer</a>
                <a href="{{ route('admin.stock-transfer') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Stock Transfer</a>
                <a href="{{ route('admin.manage-replacement') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Manage Replacements</a>
                <a href="{{ route('admin.dealer-ledger') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Dealer Ledger</a>

            </div>

        </div>
        
        <!-- CUSTOMER -->
        <div x-data="{open:false}">

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

                <a href="{{ route('admin.customer-setup') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">
                    Customer Setup
                </a>

            </div>

        </div>        

        <!-- COMPLAINTS & ENQUIRIES MANAGEMENT -->
        <div x-data="{open:false}">

            <button
                @click="open=!open"
                class="w-full flex justify-between items-center p-3 text-white hover:bg-blue-900 rounded">

                <span>Complains & Enquiries</span>

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

                <a href="{{ route('admin.troubleshoot') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Troubleshoot</a>
                <a href="{{ route('admin.view-complains') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">View Complains</a>
                <a href="{{ route('admin.feedback') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Feedbacks</a>
                <a href="{{ route('admin.device-replace-request') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Device Replace Requests</a>

            </div>

        </div>

         <!-- STOCK MANAGEMENT -->
        <div x-data="{open:false}">

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

                <a href="{{ route('admin.stock.manage') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Manage Raw Stock</a>
                <a href="{{ route('admin.current-stock') }}" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Current Stock</a>
               <!-- <a href="" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Sold Device Report</a>  -->
               <!-- <a href="" class="block py-3 rounded-lg text-white hover:bg-blue-900 transition">Add Faulty Device</a> -->

            </div>

        </div>

        <!-- ACTIVATION MANAGEMENT -->
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

       <!-- REPORT MANAGEMENT -->
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
        
    </nav>

</aside>

<!-- Mobile Overlay -->
<div x-show="sidebarOpen"
     @click="sidebarOpen = false"
     class="fixed inset-0 bg-black/50 z-40 lg:hidden">
</div>