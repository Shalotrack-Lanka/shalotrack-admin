<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden bg-gray-50">

    @include('partials.sidebars.admin')
    @include('partials.header')

    <main class="flex-1 overflow-y-auto p-6">
        @yield('content')
                <div class="flex flex-wrap gap-1.5 bg-gray-50 p-3 rounded-xl border border-gray-200 shadow-sm w-full">
                        <a href="#" class="bg-blue-600 text-white font-bold border border-blue-700 text-[11px] py-1.5 px-3 rounded shadow transition">Add Supplier</a>
                        <a href="#" class="bg-[#17a2b8] hover:bg-[#138496] text-white font-semibold text-[11px] py-1.5 px-3 rounded shadow-sm transition">Create PO & Stock Upload</a>
                    </div>

                    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                            <h3 class="text-lg font-bold text-gray-800">Supplier Master</h3>
                        </div>

                        <div class="p-6 grid grid-cols-1 lg:grid-cols-12 gap-8 items-start w-full">
                            
                            <form method="POST" action="#" class="lg:col-span-5 space-y-3 max-w-xl w-full text-xs font-semibold text-gray-700">
                                @csrf
                                <div><label class="block mb-1">Supplier Name</label><input type="text" name="supplier_name" class="w-full rounded-lg border-gray-300 h-9"></div>
                                <div><label class="block mb-1">Address</label><textarea name="address" rows="3" class="w-full rounded-lg border-gray-300"></textarea></div>
                                <div><label class="block mb-1">Country</label><select name="country" class="w-full rounded-lg border-gray-300 h-9"><option value="">--Select--</option></select></div>
                                <div><label class="block mb-1">State</label><select name="state" class="w-full rounded-lg border-gray-300 h-9"><option value="">--Select--</option></select></div>
                                <div><label class="block mb-1">Phone Number</label><input type="text" name="phone_number" class="w-full rounded-lg border-gray-300 h-9"></div>
                                <div><label class="block mb-1">Email ID</label><input type="email" name="email_id" class="w-full rounded-lg border-gray-300 h-9"></div>
                                <div><label class="block mb-1">Website (if any)</label><input type="text" name="website" class="w-full rounded-lg border-gray-300 h-9"></div>
                                <div><label class="block mb-1">GSTIN Number</label><input type="text" name="gstin" class="w-full rounded-lg border-gray-300 h-9"></div>
                                
                                <div class="flex gap-2 pt-2">
                                    <button type="reset" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded font-bold shadow-sm">Reset</button>
                                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded font-bold shadow-sm">Save</button>
                                </div>
                            </form>

                            <div class="lg:col-span-7 space-y-6 w-full">
                                <div class="border border-gray-200 rounded-lg overflow-hidden shadow-sm w-full text-xs font-semibold text-gray-700">
                                    <div class="flex bg-gray-100 border-b border-gray-200">
                                        <button @click="supplierTab = 'active'" :class="supplierTab =A== 'active' ? 'bg-white text-gray-800 font-bold border-t-2 border-t-blue-500' : 'text-gray-500 hover:bg-gray-50'" class="px-5 py-2.5 border-r border-gray-200">Active</button>
                                        <button @click="supplierTab = 'archived'" :class="supplierTab === 'archived' ? 'bg-white text-gray-800 font-bold border-t-2 border-t-blue-500' : 'text-gray-500 hover:bg-gray-50'" class="px-5 py-2.5">Archived</button>
                                    </div>

                                    <div x-show="supplierTab === 'active'" class="overflow-y-auto max-h-64 bg-white">
                                        <table class="w-full border-collapse text-left">
                                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 font-bold">
                                                <tr>
                                                    <th class="p-2.5 text-center w-10">#</th>
                                                    <th class="p-2.5">User Name</th>
                                                    <th class="p-2.5">MobileNo</th>
                                                    <th class="p-2.5">Date</th>
                                                    <th class="p-2.5 text-center w-24">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200 text-gray-600 font-medium">
                                                <tr><td class="p-2.5 text-center">1.</td><td class="p-2.5 font-bold text-gray-900">amila test</td><td class="p-2.5">079123456</td><td class="p-2.5 text-gray-400">26 Nov 2019</td><td class="p-2.5 text-center flex justify-center gap-1.5"><button class="border border-gray-200 rounded px-2 py-0.5 hover:bg-gray-50 text-[10px]">Edit</button><button class="text-gray-400 hover:text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button></td></tr>
                                                <tr><td class="p-2.5 text-center">2.</td><td class="p-2.5 font-bold text-gray-900">Dialog Axiata</td><td class="p-2.5">777123456</td><td class="p-2.5 text-gray-400">01 Oct 2019</td><td class="p-2.5 text-center flex justify-center gap-1.5"><button class="border border-gray-200 rounded px-2 py-0.5 hover:bg-gray-50 text-[10px]">Edit</button><button class="text-gray-400 hover:text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button></td></tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div x-show="supplierTab === 'archived'" class="p-8 text-center text-gray-400 bg-white" style="display: none;">
                                        No archived suppliers found.
                                    </div>
                                </div>

                                <div class="border border-gray-200 rounded-lg overflow-hidden shadow-sm w-full text-xs">
                                    <div class="bg-gray-100 p-2.5 font-bold text-gray-700 border-b border-gray-200">Supplier Products</div>
                                    <div class="p-4 text-center text-gray-500 font-medium bg-white">No Records Found.</div>
                                </div>
                            </div>
                        </div>

                        <div class="p-6 border-t border-gray-100 w-full text-xs font-semibold text-gray-700">
                            <div class="mb-3 font-bold text-sm text-gray-800">All Available Products</div>
                            <div class="border border-gray-200 rounded-lg overflow-hidden shadow-sm overflow-x-auto">
                                <table class="w-full border-collapse text-left">
                                    <thead class="bg-gray-50 border-b border-gray-200 font-bold">
                                        <tr>
                                            <th class="p-2.5 text-center w-12">S.No.</th>
                                            <th class="p-2.5">Product Name</th>
                                            <th class="p-2.5 w-32">Price</th>
                                            <th class="p-2.5 w-32">Discount</th>
                                            <th class="p-2.5 text-center w-24">Add</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 bg-white font-medium">
                                        <tr>
                                            <td class="p-2.5 text-center">1</td>
                                            <td class="p-2.5 font-bold text-gray-900">10 Ke Tgoti</td>
                                            <td class="p-2.5"><input type="text" placeholder="Price" class="rounded border-gray-300 px-2 py-1 text-xs h-7 w-24"></td>
                                            <td class="p-2.5"><input type="text" placeholder="Disc" class="rounded border-gray-300 px-2 py-1 text-xs h-7 w-24"></td>
                                            <td class="p-2.5 text-center"><button class="bg-[#17a2b8] hover:bg-[#138496] text-white font-bold text-[10px] px-4 py-1 rounded shadow-sm">Add</button></td>
                                        </tr>
                                        <tr>
                                            <td class="p-2.5 text-center">2</td>
                                            <td class="p-2.5 font-bold text-gray-900">10-11 No. chabi Taparia</td>
                                            <td class="p-2.5"><input type="text" placeholder="Price" class="rounded border-gray-300 px-2 py-1 text-xs h-7 w-24"></td>
                                            <td class="p-2.5"><input type="text" placeholder="Disc" class="rounded border-gray-300 px-2 py-1 text-xs h-7 w-24"></td>
                                            <td class="p-2.5 text-center"><button class="bg-[#17a2b8] hover:bg-[#138496] text-white font-bold text-[10px] px-4 py-1 rounded shadow-sm">Add</button></td>
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