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

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">


       <div class="bg-white dark:bg-slate-800 rounded-xl shadow p-6 max-w-4xl">
           <h2 class="text-2xl font-semibold mb-6">Change Product Code</h2>
               <form class="space-y-4">
                   <a href="#" class="text-blue-600 underline">Download Format</a>
                   <div>
                       <label class="block mb-2">From Product</label>
                       <select class="w-full rounded-lg border-gray-300 dark:bg-slate-700">
                           <option>--Select--</option>
                       </select>
                   </div>
                   <div>
                       <label class="block mb-2">To Product</label>
                       <select class="w-full rounded-lg border-gray-300 dark:bg-slate-700">
                           <option>--Select--</option>
                       </select>
                   </div>
                   <label class="flex items-center gap-2">
                       <input type="checkbox">Single IMEI
                   </label>
                   <div>
                       <label class="block mb-2">Import IMEI File</label>
                       <input type="file">
                   </div>
                   <p>Total IMEI Found : 0</p>
                   <p>Valid IMEI Found : 0</p>
                   <button class="px-6 py-2 bg-[#0B1B3F] text-white rounded-lg">Upload Excel</button>
               </form>
           </div>


        </main>

    </div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>