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
            <h3 class="text-xl font-bold text-gray-800">Current Stock</h3>
        </div>

        <div class="p-6 space-y-6 w-full">
            <form method="GET" action="#" class="bg-gray-50 border border-gray-100 p-5 rounded-xl shadow-sm w-full space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl">
                    
                    <div class="grid grid-cols-3 items-center gap-2">
                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Product Type</label>
                        <div class="col-span-2">
                            <select name="product_type" class="w-full rounded-lg border-gray-300 text-sm h-10"><option value="">--Select--</option></select>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 items-center gap-2">
                        <label class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Product</label>
                        <div class="col-span-2">
                            <select name="product" class="w-full rounded-lg border-gray-300 text-sm h-10"><option value="No Data">No Data</option></select>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-8 text-xs font-bold text-gray-700 pl-2">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="srilanka_branch" x-model="srilankaBranch" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"> Srilanka Branch
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="missing_stock" x-model="missingStock" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"> MISSING STOCK - LK
                    </label>
                </div>

                <div class="pt-2 flex justify-start">
                    <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white font-bold h-10 w-36 rounded-lg shadow-sm transition text-sm">
                        Search
                    </button>
                </div>
            </form>

            <div class="border border-gray-200 rounded-lg overflow-hidden shadow-sm overflow-x-auto w-full">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-gray-100 text-gray-700 font-bold border-b border-gray-200">
                        <tr>
                            <th class="p-3 text-center w-12">#</th>
                            <th class="p-3">Product Type</th>
                            <th class="p-3">Product Name</th>
                            <th class="p-3 text-center w-24">Qty.</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-gray-600 bg-white font-medium">
                        <tr class="hover:bg-gray-50">
                            <td class="p-3 text-center">1.</td>
                            <td class="p-3">Device</td>
                            <td class="p-3 text-gray-900 font-semibold">Basic Device</td>
                            <td class="p-3 text-center font-mono text-blue-600 font-bold"><a href="#" class="hover:underline">4</a></td>
                        </tr>
                        <tr class="hover:bg-gray-50">
                            <td class="p-3 text-center">2.</td>
                            <td class="p-3">Device</td>
                            <td class="p-3 text-gray-900 font-semibold">Dash cam</td>
                            <td class="p-3 text-center font-mono text-blue-600 font-bold"><a href="#" class="hover:underline">5</a></td>
                        </tr>
                        <tr class="hover:bg-gray-50">
                            <td class="p-3 text-center">3.</td>
                            <td class="p-3">Device</td>
                            <td class="p-3 text-gray-900 font-semibold">Fuel Device</td>
                            <td class="p-3 text-center font-mono text-blue-600 font-bold"><a href="#" class="hover:underline">7</a></td>
                        </tr>
                        <tr class="hover:bg-gray-50">
                            <td class="p-3 text-center">4.</td>
                            <td class="p-3">Device</td>
                            <td class="p-3 text-gray-900 font-semibold">Letstrack Basic Series</td>
                            <td class="p-3 text-center font-mono text-blue-600 font-bold"><a href="#" class="hover:underline">580</a></td>
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