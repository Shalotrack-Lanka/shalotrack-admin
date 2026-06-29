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
                 <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                  <h3 class="text-lg font-bold text-gray-800">Credit Invoice Report</h3>
                  <span class="text-xs font-semibold text-gray-500 bg-gray-100 px-2.5 py-1 rounded-md flex items-center gap-1">
                  <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    26<sup>th</sup> June 2026
                  </span>
              </div>

                        <div class="p-6 space-y-6 w-full">
                            <form method="GET" action="#" class="bg-gray-50 border border-gray-100 p-5 rounded-xl shadow-sm w-full">
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-4 max-w-5xl">
                                    
                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">From Date</label>
                                        <div class="col-span-2">
                                            <input type="date" name="from_date" value="2026-05-27" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">To Date</label>
                                        <div class="col-span-2">
                                            <input type="date" name="to_date" value="2026-06-26" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Invoice No</label>
                                        <div class="col-span-2">
                                            <input type="text" name="invoice_no" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Type</label>
                                        <div class="col-span-2">
                                            <select name="type" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                                <option value="">--Select--</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-3 items-center gap-2">
                                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Channel</label>
                                        <div class="col-span-2">
                                            <select name="channel" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                                <option value="">--Select--</option>
                                            </select>
                                        </div>
                                    </div>

                                </div>

                                <div class="mt-4 flex justify-start">
                                    <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white font-bold h-10 w-36 rounded-lg shadow-sm transition flex items-center justify-center gap-2 text-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                        Search
                                    </button>
                                </div>
                            </form>

                            <div class="border border-gray-200 rounded-xl overflow-hidden shadow-sm w-full">
                                <div class="p-12 text-center bg-gray-50">
                                    <svg class="mx-auto h-12 w-12 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0a2 2 0 01-2 2H6a2 2 0 01-2-2m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                    <p class="text-gray-600 font-semibold text-base">No Record Found.</p>
                                </div>
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