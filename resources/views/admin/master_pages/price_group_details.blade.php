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
                        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                            <h3 class="text-sm font-bold text-gray-800">Price Group Details</h3>
                            <button class="bg-gray-800 text-white font-bold text-[10px] py-1.5 px-3 rounded hover:bg-gray-900 shadow transition flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg> Price Group
                            </button>
                        </div>

                        <div class="p-6 text-xs font-semibold text-gray-700 w-full">
                            <form method="GET" action="#" class="bg-gray-50 border border-gray-150 p-5 rounded-xl max-w-4xl">
                                <div class="flex flex-wrap items-end gap-6">
                                    <div class="w-full sm:w-64">
                                        <label class="block mb-1.5">User Type</label>
                                        <select name="user_type" class="w-full rounded-lg border-gray-300 text-xs h-9 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10"">
                                            <option value="" selected disabled>--Select--</option>
                                            <option value="lbc">LBC</option>
                                            <option value="distributor">Distributor</option>
                                            <option value="retailer">Retailer</option>
                                        </select>
                                    </div>
                                    <div class="w-full sm:w-64">
                                        <label class="block mb-1.5">Price Group</label>
                                        <select name="price_group" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                            <option value="" selected disabled>--Select--</option>
                                            <option value="vat_lbc">VAT-LBC</option>
                                            <option value="vat_dist">VAT-DIST</option>
                                            <option value="vat_ret">VAT-RET</option>
                                            <option value="vat_dsa">VAT-DSA</option>
                                            <option value="vat_ba">VAT-BA</option>
                                            <option value="vat_csa">VAT-CSA</option>
                                            <option value="vat_ltpoint">VAT-LTPoint</option>    
                                        </select>
                                    </div>
                                    <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white font-bold h-9 px-6 rounded-lg shadow-sm flex items-center gap-1.5 transition">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg> Search
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
        </main>

    </div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>