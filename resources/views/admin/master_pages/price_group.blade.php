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
                  <div class="px-6 py-4 border-b border-gray-100 bg-gray-50"><h3 class="text-lg font-bold text-gray-800">Add Price Group</h3></div>
        
                        <div class="p-6 grid grid-cols-1 lg:grid-cols-12 gap-8 items-start w-full">
                            <form method="POST" action="#" class="lg:col-span-5 space-y-4 max-w-xl w-full text-xs font-semibold text-gray-700">
                                @csrf
                                <div>
                                    <label class="block mb-1">Group Name</label>
                                <input type="text" name="group_name" class="w-full rounded-lg border-gray-300 h-9"></div>
                                <div>
                                    <label class="block mb-1">User Type</label>
                                    <select name="user_type" class="w-full rounded-lg border-gray-300 h-9 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10"">
                                        <option value="" selected disabled>--Select--</option>
                                        <option value="lbc">LBC</option>
                                        <option value="distributor">Distributor</option>
                                        <option value="retailer">Retailer</option>
                                        <option value="dsa">DSA</option>
                                        <option value="ba">BA</option>
                                        <option value="csa">CSA</option>
                                        <option value="lt_point">LT Point</option>
                                    </select>
                                </div>
                            </form>

                            <div class="lg:col-span-7 border border-gray-200 rounded-lg overflow-hidden shadow-sm w-full text-xs font-semibold text-gray-700">
                                <div class="flex bg-gray-100 border-b border-gray-200">
                                    <button class="bg-white px-4 py-2 font-bold border-t-2 border-t-blue-500">Active</button>
                                    <button class="px-4 py-2 text-gray-500 hover:bg-gray-50">Archived</button>
                                </div>
                                <div class="overflow-y-auto max-h-80 bg-white">
                                    <table class="w-full border-collapse text-left">
                                        <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 font-bold">
                                            <tr>
                                                <th class="p-2.5 text-center w-10">#</th>
                                                <th class="p-2.5">Group Name</th>
                                                <th class="p-2.5">User Type</th>
                                                <th class="p-2.5">Date</th>
                                                <th class="p-2.5 text-center w-24">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 text-gray-600 font-medium">
                                            <tr><td class="p-2.5 text-center">1.</td><td class="p-2.5 font-bold text-gray-900">VAT-BA</td><td class="p-2.5">BA</td><td class="p-2.5 text-gray-400">12 Oct 2019</td><td class="p-2.5 text-center flex justify-center gap-1.5"><button class="border border-gray-200 rounded px-2 py-0.5 hover:bg-gray-50 text-gray-700 font-bold text-[10px]">Edit</button><button class="text-gray-400 hover:text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button></td></tr>
                                            <tr><td class="p-2.5 text-center">2.</td><td class="p-2.5 font-bold text-gray-900">VAT-DIST</td><td class="p-2.5">Distributor</td><td class="p-2.5 text-gray-400">29 Jan 2021</td><td class="p-2.5 text-center flex justify-center gap-1.5"><button class="border border-gray-200 rounded px-2 py-0.5 hover:bg-gray-50 text-gray-700 font-bold text-[10px]">Edit</button><button class="text-gray-400 hover:text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button></td></tr>
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