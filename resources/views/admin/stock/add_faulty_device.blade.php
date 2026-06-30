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
                   <h3 class="text-lg font-bold text-gray-800">Add Faulty Device</h3>
                </div>

                        <div class="p-6 grid grid-cols-1 lg:grid-cols-12 gap-8 items-start w-full">
                            
                            <form method="POST" action="#" class="lg:col-span-7 space-y-4 max-w-2xl w-full">
                                @csrf
                                
                                <div class="grid grid-cols-3 items-center gap-4">
                                    <label class="text-sm font-semibold text-gray-700">Device IMEI</label>
                                    <div class="col-span-2">
                                        <input type="text" name="device_imei" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                    </div>
                                </div>

                                <div class="grid grid-cols-3 items-start gap-4">
                                    <label class="text-sm font-semibold text-gray-700 pt-1">Fault Type</label>
                                    <div class="col-span-2 border border-gray-300 rounded-lg p-3 bg-white max-h-40 overflow-y-auto space-y-2 text-xs font-medium text-gray-700">
                                        <label class="flex items-center gap-2"><input type="checkbox" name="fault_types[]" value="Reinstallation" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"> Reinstallation In Other Vehicle In 499</label>
                                        <label class="flex items-center gap-2"><input type="checkbox" name="fault_types[]" value="Offline" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"> Device Offline</label>
                                        <label class="flex items-center gap-2"><input type="checkbox" name="fault_types[]" value="Faulty" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"> FAULTY DEVICE</label>
                                        <label class="flex items-center gap-2"><input type="checkbox" name="fault_types[]" value="Connection" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"> CONNECTION PROBLEM</label>
                                    </div>
                                </div>

                                <div class="grid grid-cols-3 items-center gap-4">
                                    <label class="text-sm font-semibold text-gray-700">Is ReTested</label>
                                    <div class="col-span-2 flex items-center gap-4 text-sm font-medium text-gray-700">
                                        <label class="inline-flex items-center gap-1.5"><input type="radio" name="is_retested" value="Yes" checked class="text-blue-600 focus:ring-blue-500"> Yes</label>
                                        <label class="inline-flex items-center gap-1.5"><input type="radio" name="is_retested" value="No" class="text-blue-600 focus:ring-blue-500"> No</label>
                                    </div>
                                </div>

                                <div class="grid grid-cols-3 items-center gap-4">
                                    <label class="text-sm font-semibold text-gray-700">Date</label>
                                    <div class="col-span-2">
                                        <input type="date" name="date" value="2026-06-26" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                    </div>
                                </div>

                                <div class="grid grid-cols-3 items-start gap-4">
                                    <label class="text-sm font-semibold text-gray-700 pt-1">Remarks</label>
                                    <div class="col-span-2">
                                        <textarea name="remarks" rows="3" class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500"></textarea>
                                    </div>
                                </div>

                                <div class="grid grid-cols-3 gap-4 pt-2">
                                    <div></div>
                                    <div class="col-span-2">
                                        <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white font-bold h-10 px-8 rounded-lg shadow-sm transition text-sm">
                                            Save
                                        </button>
                                    </div>
                                </div>
                            </form>

                            <div class="lg:col-span-5 border border-gray-200 rounded-lg overflow-hidden shadow-sm w-full">
                                <div class="p-3 bg-gray-50 border-b border-gray-200 flex gap-2">
                                    <input type="text" placeholder="FAULT" class="flex-1 rounded border-gray-300 text-xs px-2.5 py-1.5 h-8">
                                    <button type="button" class="bg-[#17a2b8] hover:bg-[#138496] text-white font-bold text-xs px-4 h-8 rounded shadow-sm">Add</button>
                                </div>
                                
                                <div class="overflow-y-auto max-h-80 text-xs font-semibold text-gray-700">
                                    <table class="w-full border-collapse">
                                        <thead class="bg-gray-100 border-b border-gray-200 text-left sticky top-0">
                                            <tr>
                                                <th class="p-2.5 text-center w-10">#</th>
                                                <th class="p-2.5">Fault</th>
                                                <th class="p-2.5 text-center w-20">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 bg-white">
                                            <tr class="hover:bg-gray-50">
                                                <td class="p-2.5 text-center"><input type="checkbox" class="rounded border-gray-300 text-blue-600"></td>
                                                <td class="p-2.5">SIM NOT ACTIVATED</td>
                                                <td class="p-2.5 text-center flex justify-center gap-2">
                                                    <button class="text-gray-500 hover:text-blue-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg></button>
                                                    <button class="text-gray-500 hover:text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                                                </td>
                                            </tr>
                                            <tr class="hover:bg-gray-50">
                                                <td class="p-2.5 text-center"><input type="checkbox" class="rounded border-gray-300 text-blue-600"></td>
                                                <td class="p-2.5">IGNITION ON/OFF ISSUE</td>
                                                <td class="p-2.5 text-center flex justify-center gap-2">
                                                    <button class="text-gray-500 hover:text-blue-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg></button>
                                                    <button class="text-gray-500 hover:text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                                                </td>
                                            </tr>
                                            <tr class="hover:bg-gray-50">
                                                <td class="p-2.5 text-center"><input type="checkbox" class="rounded border-gray-300 text-blue-600"></td>
                                                <td class="p-2.5">AC ON/OFF ISSUE</td>
                                                <td class="p-2.5 text-center flex justify-center gap-2">
                                                    <button class="text-gray-500 hover:text-blue-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg></button>
                                                    <button class="text-gray-500 hover:text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
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