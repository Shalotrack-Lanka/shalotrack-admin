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
                        <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                            </svg>
                            View Device Replacement Request
                        </h3>
                    </div>

                    <div class="p-6">
                        <form method="GET" action="#" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                
                                <div>
                                    <label for="from_date" class="block text-sm font-semibold text-gray-700 mb-2">From Date</label>
                                    <input type="date" id="from_date" name="from_date" 
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                </div>

                                <div>
                                    <label for="to_date" class="block text-sm font-semibold text-gray-700 mb-2">To Date</label>
                                    <input type="date" id="to_date" name="to_date" 
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                </div>

                                <div>
                                    <label for="user_type" class="block text-sm font-semibold text-gray-700 mb-2">User Type</label>
                                    <select id="user_type" name="user_type" 
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                        <option value="">--Select--</option>
                                        <option value="dealer">Dealer</option>
                                        <option value="distributor">Distributor</option>
                                        <option value="retailer">Retailer</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="user" class="block text-sm font-semibold text-gray-700 mb-2">User</label>
                                    <select id="user" name="user" 
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                        <option value="">--Select User--</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="status" class="block text-sm font-semibold text-gray-700 mb-2">Status</label>
                                    <select id="status" name="status" 
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                        <option value="pending" selected>Pending</option>
                                        <option value="approved">Approved</option>
                                        <option value="rejected">Rejected</option>
                                    </select>
                                </div>

                                <div class="flex items-end">
                                    <button type="submit" 
                                        class="w-full bg-[#17a2b8] hover:bg-[#138496] text-white font-semibold h-10 px-5 rounded-lg shadow-sm transition duration-200 text-sm flex items-center justify-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                        </svg>
                                        Search
                                    </button>
                                </div>

                            </div>
                        </form>

                        <div class="mt-8 border border-gray-200 rounded-lg overflow-hidden">
                            <div class="p-12 text-center bg-gray-50">
                                <svg class="mx-auto h-12 w-12 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                                <p class="text-gray-600 font-semibold text-base">no record found</p>
                                <p class="text-xs text-gray-400 mt-1">There are currently no replacement requests matching these filters.</p>
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