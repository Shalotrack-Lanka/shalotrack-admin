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
                 <h3 class="text-xl font-bold text-gray-800">Sold Device Report</h3>
              </div>

                        <div class="p-6 space-y-6 w-full">
                            <form method="GET" action="#" class="bg-gray-50 border border-gray-100 p-5 rounded-xl shadow-sm w-full">
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4 items-center">
                                    
                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">From Date :</label>
                                        <div class="col-span-2"><input type="date" name="from_date" value="2026-05-26" class="w-full rounded-lg border-gray-300 text-sm h-10"></div>
                                    </div>

                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">To Date :</label>
                                        <div class="col-span-2"><input type="date" name="to_date" value="2026-06-26" class="w-full rounded-lg border-gray-300 text-sm h-10"></div>
                                    </div>

                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Device Type :</label>
                                        <div class="col-span-2">
                                            <select name="device_type" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                                <option value="all" selected disable>All</option>
                                                <option value="basic_device">Basic Device</option>
                                                <option value="basic_foc">Basic FOC</option>
                                                <option value="dash_cam" >Dash cam</option>
                                                <option value="dashcam">Dashcam</option>
                                                <option value="fuel_device">Fuel Device</option>
                                                <option value="letstrack_basic_series">Letstrack Basic Series</option>
                                                <option value="letstrack_bike">Letstrack Bike</option>
                                                <option value="letstrack_plus_series_lt05">Letstrack Plus Series LT05</option>
                                                <option value="letstrack_premium_fuel_ltp001">Letstrack Premium Fuel -LTP001 (GPS Tracking Device)</option>
                                                <option value="letstrack_prima_fuel_lt02">Letstrack Prima Fuel - LT02-Fuel (GPS Tracking Device)</option>
                                                <option value="letstrack_prima_series_ltp001">Letstrack Prima Series - LTP001 (GPS Tracking Device)</option>
                                                <option value="lt_bike_special">LT Bike Special</option>
                                                <option value="lt_simple">LT Simple</option>
                                                <option value="magnatic_device">Magnatic Device</option>
                                                <option value="obd_device">OBD Device</option>
                                                <option value="personal_mega">PERSONAL MEGA</option>
                                                <option value="plus_device">Plus Device</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Invoice No like</label>
                                        <div class="col-span-2"><input type="text" name="invoice_no" class="w-full rounded-lg border-gray-300 text-sm h-10"></div>
                                    </div>

                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Serial No</label>
                                        <div class="col-span-2"><input type="text" name="serial_no" class="w-full rounded-lg border-gray-300 text-sm h-10"></div>
                                    </div>

                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Sale Mode</label>
                                        <div class="col-span-2">
                                            <select name="sale_mode" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                                <option value="All">All</option>
                                                <option value="offline">Offline</option>
                                                <option value="online">Online</option>
                                                <option value="others">Others</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Sale Type</label>
                                        <div class="col-span-2">
                                            <select name="sale_type" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                                <option value="" selected disabled>--Select--</option>
                                                <option value="automobile_show_rooms">Automobile Show Rooms</option>
                                                <option value="axis">Axis</option>
                                                <option value="b2b">B2B</option>
                                                <option value="ba">BA</option>
                                                <option value="cars24">Cars24</option>
                                                <option value="direct_sale">Direct Sale</option>
                                                <option value="dsa">DSA</option>
                                                <option value="employee_sample">Employee Sample</option>
                                                <option value="foc">FOC</option>
                                                <option value="mass_distribution">Mass Distribution</option>
                                                <option value="online">Online</option>
                                                <option value="schools">Schools</option>
                                                <option value="stock_transfer">Stock Transfer</option>
                                                <option value="temp_demo">Temp Demo</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Doc. Status</label>
                                        <div class="col-span-2">
                                            <select name="doc_status" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                                <option value="" selected disabled>--Select--</option>
                                                <option value="document_received">Document Received</option>
                                                <option value="not_received_yet">Not Received Yet</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="flex items-end justify-start">
                                        <button type="submit" class="w-full md:w-36 bg-[#17a2b8] hover:bg-[#138496] text-white font-bold h-10 rounded-lg shadow-sm transition text-sm">
                                            Search
                                        </button>
                                    </div>

                                </div>
                            </form>

                            <div class="border border-gray-200 rounded-lg overflow-hidden overflow-x-auto bg-gray-50 shadow-inner">
                                <table class="w-full border-collapse text-center text-[11px] font-bold text-gray-700">
                                    <thead class="bg-gray-100 border-b border-gray-200 text-gray-900">
                                        <tr class="divide-x divide-gray-200">
                                            <th class="p-2.5">Total Sale</th>
                                            <th class="p-2.5">Automobile Show Rooms</th>
                                            <th class="p-2.5">B2B</th>
                                            <th class="p-2.5">Direct Sale</th>
                                            <th class="p-2.5">Mass Distribution</th>
                                            <th class="p-2.5">Stock Transfer</th>
                                            <th class="p-2.5">Schools</th>
                                            <th class="p-2.5">Online</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="divide-x divide-gray-200 font-medium text-gray-600 bg-white">
                                            <td class="p-2.5 font-bold text-gray-900">DSA</td>
                                            <td class="p-2.5">BA</td>
                                            <td class="p-2.5">Employee Sample</td>
                                            <td class="p-2.5">FOC</td>
                                            <td class="p-2.5">Temp Demo</td>
                                            <td class="p-2.5">Unknown</td>
                                            <td class="p-2.5">Doc. Received</td>
                                            <td class="p-2.5">Doc. Pending</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </div>
                </div>
                
        </main>

    </div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>