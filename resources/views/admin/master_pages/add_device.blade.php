<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Add Device</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden">

    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

        <main class="p-4 md:p-6 flex-1">
            @yield('content')

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start w-full">
                
                <div class="lg:col-span-4 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">Device Categories</div>
                    <div class="p-5">
                        <div class="border border-gray-200 rounded-lg overflow-hidden max-h-60 overflow-y-auto text-xs font-semibold text-gray-700">
                            <table class="w-full text-left border-collapse">
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th class="p-2.5">Device Type</th>
                                        <th class="p-2.5 text-center w-20">Serial Req.</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <tr>
                                        <td class="p-2.5">Port In Device</td>
                                        <td class="p-2.5 text-center text-green-600 font-bold">Yes</td>
                                    </tr>
                                    <tr>
                                        <td class="p-2.5">OBD Tracker</td>
                                        <td class="p-2.5 text-center text-green-600 font-bold">Yes</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-8 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">Add New Tracking Device</div>
                    <div class="p-5 text-xs font-semibold text-gray-700 space-y-4">
                        <form method="POST" action="#" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @csrf
                            
                            <div>
                                <label class="block mb-1">Device Category / Type</label>
                                <select name="product_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                    <option value="" selected disabled>--Select Device Type--</option>
                                    <option value="port_in_device">Shalotrack V5 basic</option>
                                    <option value="port_in_device">Shalotrack V5 plus</option>
                                </select>
                            </div>

                            <div>
                                <label class="block mb-1">Device Imei Number</label>
                                <input type="text" name="product_name" class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                            </div>

                            <div class="md:col-span-2 flex gap-2 pt-2">
                                <button type="reset" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg font-bold shadow-sm transition">Reset</button>
                                <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white px-5 py-2 rounded-lg font-bold shadow-sm transition">Save Device</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
</body>
</html>