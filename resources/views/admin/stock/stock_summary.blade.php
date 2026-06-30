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
                     <div class="bg-blue-600 text-white px-5 py-3 font-semibold text-sm">
                         Subscription Expire Report
                     </div>

                <div class="p-5">
                        <form method="GET" action="#" class="flex flex-wrap items-center gap-6 text-xs font-semibold text-gray-700">
                            
                            <div class="flex items-center gap-2">
                                <label for="device_imei">Device IMEI</label>
                                <input type="text" id="device_imei" name="device_imei" class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xs h-9 w-44">
                            </div>

                            <div class="flex items-center gap-2">
                                <label for="expire_next">Expire in Next (?) Days</label>
                                <select id="expire_next" name="expire_next" class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xs h-9 w-24">
                                    <option value="">Select</option>
                                </select>
                            </div>

                            <div class="flex items-center gap-2">
                                <label for="expired_before">Expired (?) Days Before</label>
                                <select id="expired_before" name="expired_before" class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xs h-9 w-24">
                                    <option value="">Select</option>
                                </select>
                            </div>

                            <div class="flex items-center gap-2">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold h-9 px-4 rounded-lg shadow transition flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                    Search
                                </button>
                                <button type="button" class="bg-[#17a2b8] hover:bg-[#138496] text-white font-bold h-9 w-9 rounded-lg shadow transition flex items-center justify-center" title="Download Report">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                </button>
                            </div>

                        </form>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                    <div class="bg-blue-600 text-white px-5 py-3 font-semibold text-sm">
                        Stock Summary
                    </div>
                    
                    <div class="p-5 overflow-x-auto w-full">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead class="bg-gray-100 text-gray-700 font-bold border-b border-gray-200">
                                <tr>
                                    <th class="p-3 text-center w-12">#</th>
                                    <th class="p-3">Product Name</th>
                                    <th class="p-3">Product Model</th>
                                    <th class="p-3 text-center">Stock In</th>
                                    <th class="p-3 text-center">Company Available Stock</th>
                                    <th class="p-3 text-center">Dealer Available Stock</th>
                                    <th class="p-3 text-center">Sold To Customer</th>
                                    <th class="p-3 text-center w-32">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 text-gray-600 bg-white font-medium">
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-3 text-center">1.</td>
                                    <td class="p-3 text-gray-900 font-semibold">Basic Device</td>
                                    <td class="p-3 text-gray-500">Basic Device</td>
                                    <td class="p-3 text-center font-mono">734</td>
                                    <td class="p-3 text-center font-mono">4</td>
                                    <td class="p-3 text-center font-mono">161</td>
                                    <td class="p-3 text-center font-mono text-green-600">569</td>
                                    <td class="p-3 text-center">
                                        <button class="bg-[#17a2b8] hover:bg-[#138496] text-white font-bold px-3 py-1 rounded text-[11px] shadow flex items-center gap-1 mx-auto transition">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            Download
                                        </button>
                                    </td>
                                </tr>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-3 text-center">2.</td>
                                    <td class="p-3 text-gray-900 font-semibold">Dash cam</td>
                                    <td class="p-3 text-gray-500">Dash cam</td>
                                    <td class="p-3 text-center font-mono">45</td>
                                    <td class="p-3 text-center font-mono">5</td>
                                    <td class="p-3 text-center font-mono">20</td>
                                    <td class="p-3 text-center font-mono text-green-600">20</td>
                                    <td class="p-3 text-center">
                                        <button class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-3 py-1 rounded text-[11px] shadow flex items-center gap-1 mx-auto transition">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            Download
                                        </button>
                                    </td>
                                </tr>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-3 text-center">3.</td>
                                    <td class="p-3 text-gray-900 font-semibold">Fuel Device</td>
                                    <td class="p-3 text-gray-500">Fuel Device</td>
                                    <td class="p-3 text-center font-mono">11</td>
                                    <td class="p-3 text-center font-mono">7</td>
                                    <td class="p-3 text-center font-mono">2</td>
                                    <td class="p-3 text-center font-mono text-green-600">2</td>
                                    <td class="p-3 text-center">
                                        <button class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-3 py-1 rounded text-[11px] shadow flex items-center gap-1 mx-auto transition">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            Download
                                        </button>
                                    </td>
                                </tr>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-3 text-center">4.</td>
                                    <td class="p-3 text-gray-900 font-semibold">Letstrack Basic</td>
                                    <td class="p-3 text-gray-500">Letstrack Basic Series</td>
                                    <td class="p-3 text-center font-mono">4034</td>
                                    <td class="p-3 text-center font-mono">580</td>
                                    <td class="p-3 text-center font-mono">89</td>
                                    <td class="p-3 text-center font-mono text-green-600">3364</td>
                                    <td class="p-3 text-center">
                                        <button class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-3 py-1 rounded text-[11px] shadow flex items-center gap-1 mx-auto transition">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            Download
                                        </button>
                                    </td>
                                </tr>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-3 text-center">5.</td>
                                    <td class="p-3 text-gray-900 font-semibold">Letstrack Bike</td>
                                    <td class="p-3 text-gray-500">Letstrack Bike</td>
                                    <td class="p-3 text-center font-mono">10</td>
                                    <td class="p-3 text-center font-mono">0</td>
                                    <td class="p-3 text-center font-mono">2</td>
                                    <td class="p-3 text-center font-mono text-green-600">8</td>
                                    <td class="p-3 text-center">
                                        <button class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-3 py-1 rounded text-[11px] shadow flex items-center gap-1 mx-auto transition">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            Download
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
                
        </main>

    </div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>