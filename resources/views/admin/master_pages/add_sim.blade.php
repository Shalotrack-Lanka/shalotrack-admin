<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Add SIM</title>

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

                <div class="lg:col-span-8 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">Add New SIM </div>
                    <div class="p-5 text-xs font-semibold text-gray-700 space-y-4">

                        @if(session('success'))
                            <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-xs font-bold">
                                {{ session('success') }}
                            </div>
                        @endif

                        @if ($errors->any())
                <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg shadow-xs transition duration-300">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-4 h-4 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <span class="text-xs font-bold uppercase tracking-wider">කරුණාකර මෙම වැරදීම් නිවැරදි කරන්න:</span>
                    </div>
                    
                    <ul class="list-disc pl-5 space-y-1 text-[11px] font-semibold">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

                        <form method="POST" action="{{ route('admin.stock.sim.store') }}" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @csrf
                            
                            <div>
                                <label class="block mb-1">SIM Type</label>
                                <select name="sim_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                    <option value="" selected disabled>--Select SIM Type--</option>
                                    <option value="dialog">Dialog Axiata</option>
                                    <option value="mobitel">Mobitel Sri Lanka</option>
                                </select>
                            </div>

                            <div>
                                <label class="block mb-1">SIM Number</label>
                                <input type="text" name="sim_number" class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                            </div>

                            <div>
                                <label class="block mb-1">IMEI Number</label>
                                <input type="text" name="imei_number" class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                            </div>

                            <div>
                                <label class="block mb-1">SIM Status</label>
                                <select name="sim_status" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                    <option value="" selected disabled>--Select SIM Status--</option>
                                    <option value="Activated">Activated</option>
                                    <option value="Not Activated">Not Activated</option>
                                </select>
                            </div>

                            <div class="md:col-span-2 flex items-center gap-2 pt-2">
                                <input type="checkbox" name="testing_required" value="1" class="rounded border-gray-300 text-blue-600 w-4 h-4 shadow-sm">
                                <label>Activation / Network Testing Required</label>
                            </div>

                            <div class="md:col-span-2 flex gap-2 pt-2">
                                <button type="reset" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg font-bold shadow-sm transition">Reset</button>
                                <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white px-5 py-2 rounded-lg font-bold shadow-sm transition">Save SIM Product</button>
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