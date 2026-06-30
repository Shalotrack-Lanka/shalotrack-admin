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
                        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50"><h3 class="text-lg font-bold text-gray-800">Add Features</h3></div>
                        
                        <div class="p-6 grid grid-cols-1 lg:grid-cols-12 gap-8 items-start w-full">
                            <form method="POST" action="#" class="lg:col-span-5 space-y-4 max-w-xl w-full text-xs font-semibold text-gray-700">
                                @csrf
                                <div><label class="block mb-1">Product Type</label><select name="product_type" class="w-full rounded-lg border-gray-300 h-9"><option value="">--Select--</option></select></div>
                                <div><label class="block mb-1">Product Name</label><select name="product_name" class="w-full rounded-lg border-gray-300 h-9"><option value="">--Select--</option></select></div>
                                <div><label class="block mb-1">Feature Name</label><select name="feature_name" class="w-full rounded-lg border-gray-300 h-9"><option value="No Data">No Data</option></select></div>
                                <div><label class="block mb-1">Feature Remarks</label><textarea name="feature_remarks" rows="4" class="w-full rounded-lg border-gray-300"></textarea></div>
                                <div class="flex gap-2 pt-2">
                                    <button type="reset" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded font-bold shadow-sm">Reset</button>
                                    <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white px-5 py-2 rounded font-bold shadow-sm">Add Feature</button>
                                </div>
                            </form>

                            <div class="lg:col-span-7 border border-gray-200 rounded-lg overflow-hidden shadow-sm w-full h-80 bg-gray-50 flex flex-col">
                                <div class="flex bg-gray-100 border-b border-gray-200 text-xs font-bold text-gray-700">
                                    <button class="bg-white px-4 py-2 border-r border-gray-200 font-bold border-t-2 border-t-blue-500">Active</button>
                                    <button class="px-4 py-2 text-gray-500 hover:bg-gray-50">Archived</button>
                                </div>
                                <div class="flex-1 p-6 flex flex-col items-center justify-center text-gray-400 text-xs font-medium">
                                    <svg class="w-10 h-10 text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                                    No feature telemetry logs active under this scope.
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