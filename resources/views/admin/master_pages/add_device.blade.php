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

@if ($errors->any())
    <div class="p-3 mb-4 bg-red-50 text-red-700 border border-red-200 rounded-lg text-xs font-bold">
        <ul class="list-disc pl-4 space-y-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(session('success'))
    <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg flex items-center gap-2 text-xs font-bold shadow-xs transition duration-300">
        <svg class="w-4 h-4 text-green-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span>{{ session('success') }}</span>
    </div>
@endif

<form action="{{ route('admin.device.store') }}" method="POST" class="space-y-4">

                        <form method="POST" action="#" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @csrf
                            
                            <div>
                                <label class="block mb-1">Device Category / Type</label>
                                <select name="device_model" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                    <option value="" selected disabled>--Select Device Type--</option>
                                    <option value="v5_basic">Shalotrack V5 basic</option>
                                    <option value="v5_plus">Shalotrack V5 plus</option>
                                </select>
                            </div>

                            <div>
                                <label class="block mb-1">Device Imei Number</label>
                                <input type="text" name="imei_number" class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                                <input type="hidden" name="branch_name" value="Srilanka Branch">
                                <input type="hidden" name="status" value="Available">
                            </div>

                            <div>
                                <label class="block mb-1">Device SIM Number</label>
                                <input type="text" name="sim_number" class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                                <input type="hidden" name="branch_name" value="Srilanka Branch">
                                <input type="hidden" name="status" value="Available">
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