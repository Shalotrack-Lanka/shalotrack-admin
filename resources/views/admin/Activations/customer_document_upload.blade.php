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
                        <h3 class="text-xl font-bold text-gray-800">Customer Document Upload</h3>
                  </div>

                            <div class="p-6 space-y-6 w-full">
                                <div class="bg-gray-50 border border-gray-100 p-6 rounded-xl shadow-sm max-w-2xl">
                                    <div class="flex flex-col sm:flex-row sm:items-center gap-6">
                                        <span class="text-sm font-bold text-gray-700 min-w-[100px]">Search By :</span>
                                        
                                        <div class="flex flex-wrap items-center gap-6">
                                            <label class="inline-flex items-center gap-2 cursor-pointer text-sm font-medium text-gray-700">
                                                <input type="radio" name="search_by" value="invoice" x-model="searchBy"
                                                    class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500 focus:ring-2">
                                                Invoice No
                                            </label>
                                            
                                            <label class="inline-flex items-center gap-2 cursor-pointer text-sm font-medium text-gray-700">
                                                <input type="radio" name="search_by" value="imei" x-model="searchBy"
                                                    class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500 focus:ring-2">
                                                Device Imei
                                            </label>
                                            
                                            <label class="inline-flex items-center gap-2 cursor-pointer text-sm font-medium text-gray-700">
                                                <input type="radio" name="search_by" value="activation_id" x-model="searchBy"
                                                    class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500 focus:ring-2">
                                                Activation Id
                                            </label>
                                        </div>
                                    </div>

                                    <div class="mt-6 pt-6 border-t border-gray-200 max-w-md">
                                        <form method="POST" action="#" class="space-y-4">
                                            @csrf
                                            <div>
                                                <label class="block text-xs font-semibold text-gray-700 mb-1.5 capitalize" 
                                                    x-text="searchBy === 'invoice' ? 'Enter Invoice Number' : searchBy === 'imei' ? 'Enter Device IMEI' : 'Enter Activation ID'"></label>
                                                <input type="text" name="search_value" required
                                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                            </div>
                                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs h-9 px-5 rounded-lg shadow transition">
                                                Proceed to Upload
                                            </button>
                                        </form>
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