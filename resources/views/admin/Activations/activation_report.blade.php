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
                     <h3 class="text-xl font-bold text-gray-800">Activation Report</h3>
              </div>

                        <div class="p-6 space-y-6 w-full">
                            <form method="GET" action="#" class="bg-gray-50 border border-gray-100 p-5 rounded-xl shadow-sm w-full">
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 items-end">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">From Date</label>
                                        <input type="date" name="from_date" value="2026-06-19" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">To Date</label>
                                        <input type="date" name="to_date" value="2026-06-26" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Payment Mode</label>
                                        <select name="payment_mode" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                            <option value="" selected disabled>--Select--</option>
                                            <option value="pay_online">Pay Online</option>
                                            <option value="emi">EMI</option>
                                            <option value="cash_on_delivery">Cash On Delivery</option>
                                            <option value="collect_cash">Collect Cash</option>
                                            <option value="cheque">Cheque</option>
                                            <option value="payment_pending">Payment Pending</option>
                                            <option value="imps">IMPS</option>
                                            <option value="neft">NEFT</option>
                                            <option value="rtgs">RTGS</option>
                                            <option value="online_payment">Online Payment</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Invoice No</label>
                                        <input type="text" name="invoice_no" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Invoice Date</label>
                                        <input type="date" name="invoice_date" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Device IMEI</label>
                                        <input type="text" name="device_imei" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Customer Name</label>
                                        <input type="text" name="customer_name" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">State</label>
                                        <select name="state" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                            <option value="" selected disabled>--Select--</option>
                                            <option value="central">Central</option>
                                            <option value="colombo">Colombo</option>
                                            <option value="eastern">Eastern</option>
                                            <option value="gampaha">Gamapaha</option>
                                            <option value="north_central">North Central</option>
                                            <option value="north_western">North Western</option>
                                            <option value="northern">Northern</option>
                                            <option value="sabaragamuwa">Sabaragamuwa</option>
                                            <option value="southern">Southern</option>
                                            <option value="uva">Uva</option>
                                            <option value="western">Western</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Activation by</label>
                                        <select name="activation_by" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                            <option value="all" selected>All</option>
                                            <option value="company">Company</option>
                                            <option value="other">other</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Status</label>
                                        <select name="status" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                            <option value="all" selected>All</option>
                                            <option value="cancel">Cancel</option>
                                            <option value="sold">Sold</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Sale Type</label>
                                        <select name="sale_type" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                            <option value="all" selected>All</option>
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
                                            <option value="temp_demo">Temp Demo</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Sale By</label>
                                        <select name="sale_by" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                            <option value="" selected disabled>--Select--</option>
                                            <option value="mvd_luke_nadeeja_perera">MVD LUKE NADEEJA PERERA</option>
                                            <option value="prakashan_malrajitha_stephen_1">Prakashan Malrajitha Stephen</option>
                                            <option value="shalaka_manoj_perera">Shalaka Manoj Perera</option>
                                            <option value="letstrack_lanka_master_admin">Letstrack Lanka Master Admin</option>
                                            <option value="test_lanka_fitter">Test Lanka Fitter</option>
                                            <option value="danushka_namal">Danushka Namal</option>
                                            <option value="aloka">Aloka</option>
                                            <option value="prakashan_malrajitha_stephen_2">Prakashan Malrajitha Stephen</option>
                                            <option value="nitin_sachdeva">NITIN SACHDEVA</option>
                                            <option value="f1">F1</option>
                                            <option value="ravinath_babu">Ravinath Babu</option>
                                            <option value="puneet_sharma">Puneet Sharma</option>
                                            <option value="dharmender_srilanka">Dharmender Srilanka</option>
                                            <option value="pramod_kumar_pandey">Pramod Kumar Pandey</option>
                                            <option value="pramod_pandey">Pramod Pandey</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mt-4 flex justify-end">
                                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs h-9 px-5 rounded-lg shadow transition flex items-center gap-1.5">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                        Search
                                    </button>
                                </div>
                            </form>

                            <div class="border border-gray-200 rounded-lg overflow-hidden shadow-sm overflow-x-auto">
                                <table class="w-full text-left text-xs border-collapse">
                                    <thead class="bg-gray-100 text-gray-700 font-bold border-b border-gray-200">
                                        <tr>
                                            <th class="p-3 text-center w-10">#</th>
                                            <th class="p-3">Invoice No</th>
                                            <th class="p-3">Sale By</th>
                                            <th class="p-3">Invoice Date</th>
                                            <th class="p-3">Customer Name</th>
                                            <th class="p-3">Device IMEI</th>
                                            <th class="p-3 text-center">Qty</th>
                                            <th class="p-3 text-center">Box Activation Code</th>
                                            <th class="p-3 text-right">Amount Collect</th>
                                            <th class="p-3">Payment Mode</th>
                                            <th class="p-3 text-center">Status</th>
                                            <th class="p-3 text-center w-24">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 text-gray-600 bg-white">
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-3 text-center">1.</td>
                                            <td class="p-3">-</td>
                                            <td class="p-3 font-medium text-gray-900">Pasindu car audio (Retailer)</td>
                                            <td class="p-3 whitespace-nowrap">26 Jun 2026</td>
                                            <td class="p-3 font-medium">Nuwan Aloka</td>
                                            <td class="p-3 font-mono">869925074201110</td>
                                            <td class="p-3 text-center">1</td>
                                            <td class="p-3 text-center">0</td>
                                            <td class="p-3 text-right">0.00</td>
                                            <td class="p-3">-</td>
                                            <td class="p-3 text-center"><span class="bg-green-50 text-green-700 border border-green-200 rounded px-2 py-0.5 font-semibold">Successful</span></td>
                                            <td class="p-3 text-center">
                                                <div class="flex items-center justify-center gap-1.5">
                                                    <button class="p-1 text-gray-500 hover:text-blue-600 border border-gray-200 rounded hover:bg-gray-50" title="Print"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg></button>
                                                    <button class="p-1 text-gray-500 hover:text-green-600 border border-gray-200 rounded hover:bg-gray-50" title="View"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg></button>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-3 text-center">2.</td>
                                            <td class="p-3">-</td>
                                            <td class="p-3 font-medium text-gray-900">Car Deko (Retailer)</td>
                                            <td class="p-3 whitespace-nowrap">26 Jun 2026</td>
                                            <td class="p-3 font-medium">Nuwan Aloka</td>
                                            <td class="p-3 font-mono">869925072204488</td>
                                            <td class="p-3 text-center">1</td>
                                            <td class="p-3 text-center">0</td>
                                            <td class="p-3 text-right">0.00</td>
                                            <td class="p-3">-</td>
                                            <td class="p-3 text-center"><span class="bg-green-50 text-green-700 border border-green-200 rounded px-2 py-0.5 font-semibold">Successful</span></td>
                                            <td class="p-3 text-center border-t border-gray-200">
                                                <div class="flex items-center justify-center gap-1.5">
                                                    <button class="p-1 text-gray-500 hover:text-blue-600 border border-gray-200 rounded hover:bg-gray-50"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg></button>
                                                    <button class="p-1 text-gray-500 hover:text-green-600 border border-gray-200 rounded hover:bg-gray-50"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg></button>
                                                </div>
                                            </td>
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