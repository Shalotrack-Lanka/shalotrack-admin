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

                <div class="w-full space-y-6">

                    <div class="flex flex-wrap gap-1.5 bg-gray-50 p-3 rounded-xl border border-gray-200 shadow-sm w-full">
                        <a href="#" class="bg-[#17a2b8] hover:bg-[#138496] text-white font-semibold text-[11px] py-1.5 px-3 rounded shadow-sm transition">Add Supplier</a>
                        <a href="#" class="bg-blue-600 text-white font-bold border border-blue-700 text-[11px] py-1.5 px-3 rounded shadow transition">Create PO & Stock Upload</a>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start w-full">
                        
                        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                            <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                                <h3 class="text-sm font-bold text-gray-800">Purchase Order</h3>
                                <button type="button" class="bg-[#17a2b8] hover:bg-[#138496] text-white font-bold text-[10px] py-1.5 px-4 rounded shadow-sm transition">Save & Print</button>
                            </div>
                            <div class="p-5 text-xs font-semibold text-gray-700 space-y-4">
                                <div class="grid grid-cols-3 items-center gap-4">
                                    <label>Select Supplier</label>
                                    <div class="col-span-2">
                                        <select name="supplier_id" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                            <option value="">--Select--</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                            <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                                <h3 class="text-sm font-bold text-gray-800">Stock Upload</h3>
                                <span class="text-xs font-semibold text-gray-500 bg-gray-100 px-2.5 py-1 rounded-md flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    26<sup>th</sup> June 2026
                                </span>
                            </div>
                            <div class="p-5 text-xs font-semibold text-gray-700 space-y-4">
                                <div class="grid grid-cols-3 items-center gap-4">
                                    <label>Select P.O.</label>
                                    <div class="col-span-2">
                                        <select name="po_id" class="w-full rounded-lg border-gray-300 text-xs h-9">
                                            <option value="">--Select--</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

        </main>

    </div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

<script>
function printPO()
{
    window.print();
}
</script>

</body>
</html>