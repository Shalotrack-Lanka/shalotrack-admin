<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

</head>
<body x-data="{ sidebarOpen: false }"> <!-- Added Alpine state for Mobile Menu Toggle -->

<div class="flex h-screen overflow-hidden"> <!-- Prevent double scrollbars -->

    @include('partials.sidebars.admin')


    @include('partials.header')


        <main class="p-4 md:p-6 flex-1">
            @yield('content')



                 <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
        
                            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                                <h3 class="text-xl font-bold text-gray-800">Dealer Ledger</h3>
                            </div>

                            <div class="p-6 grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">
                                
                                <div class="xl:col-span-7 border border-gray-200 rounded-lg overflow-hidden shadow-sm">
                                    <div class="bg-blue-600 text-white px-4 py-3 font-semibold text-sm">
                                        Supply Channels
                                    </div>
                                    
                                    <div class="overflow-x-auto max-h-[500px] overflow-y-auto">
                                        <table class="w-full text-left text-sm border-collapse">
                                            <thead class="bg-gray-100 text-gray-700 font-bold border-b border-gray-200 sticky top-0">
                                                <tr>
                                                    <th class="p-3 text-center w-12">#</th>
                                                    <th class="p-3">Company Name</th>
                                                    <th class="p-3 w-28">Type</th>
                                                    <th class="p-3 text-right w-36">Due Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200 text-gray-700 bg-white">
                                                <tr class="hover:bg-gray-50 transition">
                                                    <td class="p-3 text-center font-medium">1.</td>
                                                    <td class="p-3 font-medium text-gray-900">Auto Wave</td>
                                                    <td class="p-3 text-gray-500">Retailer</td>
                                                    <td class="p-3 text-right">
                                                        <span class="inline-block border border-blue-200 bg-blue-50 text-blue-700 rounded px-2.5 py-1 text-xs font-bold shadow-sm">
                                                            LKR 72,987
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr class="hover:bg-gray-50 transition">
                                                    <td class="p-3 text-center font-medium">2.</td>
                                                    <td class="p-3 font-medium text-gray-900">petcome car audio</td>
                                                    <td class="p-3 text-gray-500">Retailer</td>
                                                    <td class="p-3 text-right">
                                                        <span class="inline-block border border-blue-200 bg-blue-50 text-blue-700 rounded px-2.5 py-1 text-xs font-bold shadow-sm">
                                                            LKR 64,995
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr class="hover:bg-gray-50 transition">
                                                    <td class="p-3 text-center font-medium">3.</td>
                                                    <td class="p-3 font-medium text-gray-900">GLX truck Body</td>
                                                    <td class="p-3 text-gray-500">Retailer</td>
                                                    <td class="p-3 text-right">
                                                        <span class="inline-block border border-gray-200 bg-gray-50 text-gray-500 rounded px-2.5 py-1 text-xs font-medium">
                                                            LKR 0
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr class="hover:bg-gray-50 transition">
                                                    <td class="p-3 text-center font-medium">4.</td>
                                                    <td class="p-3 font-medium text-gray-900">D N Sound Systems</td>
                                                    <td class="p-3 text-gray-500">Retailer</td>
                                                    <td class="p-3 text-right">
                                                        <span class="inline-block border border-gray-200 bg-gray-50 text-gray-500 rounded px-2.5 py-1 text-xs font-medium">
                                                            LKR 0
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr class="hover:bg-gray-50 transition">
                                                    <td class="p-3 text-center font-medium">5.</td>
                                                    <td class="p-3 font-medium text-gray-900">Vega Motors</td>
                                                    <td class="p-3 text-gray-500">Retailer</td>
                                                    <td class="p-3 text-right">
                                                        <span class="inline-block border border-gray-200 bg-gray-50 text-gray-500 rounded px-2.5 py-1 text-xs font-medium">
                                                            LKR 0
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr class="hover:bg-gray-50 transition">
                                                    <td class="p-3 text-center font-medium">6.</td>
                                                    <td class="p-3 font-medium text-gray-900">Nishantha Auto A/C</td>
                                                    <td class="p-3 text-gray-500">Retailer</td>
                                                    <td class="p-3 text-right">
                                                        <span class="inline-block border border-gray-200 bg-gray-50 text-gray-500 rounded px-2.5 py-1 text-xs font-medium">
                                                            LKR 0
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr class="hover:bg-gray-50 transition">
                                                    <td class="p-3 text-center font-medium">7.</td>
                                                    <td class="p-3 font-medium text-gray-900">One 2 One Car Sale</td>
                                                    <td class="p-3 text-gray-500">Retailer</td>
                                                    <td class="p-3 text-right">
                                                        <span class="inline-block border border-gray-200 bg-gray-50 text-gray-500 rounded px-2.5 py-1 text-xs font-medium">
                                                            LKR 0
                                                        </span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="xl:col-span-5 border border-gray-200 rounded-lg overflow-hidden shadow-sm">
                                    <div class="bg-blue-600 text-white px-4 py-3 font-semibold text-sm">
                                        Ledger Details
                                    </div>
                                    <div class="p-8 text-center bg-white min-h-[250px] flex flex-col items-center justify-center">
                                        <svg class="h-12 w-12 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122" />
                                        </svg>
                                        <p class="text-gray-400 text-sm font-medium">Select a dealer from the list to view full ledger statement details.</p>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>


        </main>

    </div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>