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

                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full max-w-3xl">
                        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50"><h3 class="text-base font-bold text-gray-800">Change Product Code</h3></div>
                        
                        <div class="p-6 text-xs font-semibold text-gray-700 space-y-4">
                            <a href="#" class="text-blue-600 hover:underline font-bold inline-flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg> Download Format
                            </a>

                            <form method="POST" action="#" enctype="multipart/form-data" class="space-y-4 max-w-xl pt-2">
                                @csrf
                                
                                <div class="grid grid-cols-3 items-center gap-4">
                                    <label>From Product</label>
                                    <div class="col-span-2"><select name="from_product" class="w-full rounded-lg border-gray-300 h-9"><option value="">--Select--</option></select></div>
                                </div>

                                <div class="grid grid-cols-3 items-center gap-4">
                                    <label>Stock Qty</label>
                                    <div class="col-span-2 text-sm font-bold text-gray-900">0</div>
                                </div>

                                <div class="grid grid-cols-3 items-center gap-4">
                                    <label>To Product</label>
                                    <div class="col-span-2"><select name="to_product" class="w-full rounded-lg border-gray-300 h-9"><option value="">--Select--</option></select></div>
                                </div>

                                <div class="grid grid-cols-3 items-center gap-4">
                                    <div></div>
                                    <div class="col-span-2"><label class="inline-flex items-center gap-2 cursor-pointer font-bold"><input type="checkbox" name="single_imei" class="rounded border-gray-300 text-blue-600 w-4 h-4"> Single IMEI</label></div>
                                </div>

                                <div class="grid grid-cols-3 items-center gap-4">
                                    <label>IMPORT IMEI File :</label>
                                    <div class="col-span-2"><input type="file" name="imei_file" class="w-full border border-gray-300 bg-white rounded-lg p-1 h-9 text-[11px]"></div>
                                </div>

                                <div class="grid grid-cols-3 items-center gap-4">
                                    <label>Total IMEI found:</label>
                                    <div class="col-span-2 font-bold text-gray-900">0</div>
                                </div>

                                <div class="grid grid-cols-3 items-center gap-4">
                                    <label>Valid IMEI Found</label>
                                    <div class="col-span-2 font-bold text-gray-900">0</div>
                                </div>

                                <div class="grid grid-cols-3 gap-4 pt-2">
                                    <div></div>
                                    <div class="col-span-2">
                                        <button type="submit" class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold border border-gray-300 rounded px-4 py-2 shadow-sm transition">
                                            Upload Excel
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

        </main>

    </div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>