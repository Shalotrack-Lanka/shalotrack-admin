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


         <div class="bg-white dark:bg-slate-800 rounded-xl shadow p-6">
             <div class="grid md:grid-cols-3 gap-4">
                 <div><label class="block mb-2">User Type</label>
                 <select class="w-full rounded-lg border-gray-300 dark:bg-slate-700">
                    <option>--Select--</option></select>
                </div>
                 <div>
                    <label class="block mb-2">Price Group</label>
                    <select class="w-full rounded-lg border-gray-300 dark:bg-slate-700">
                        <option>--Select--</option>
                    </select>
                </div>
                 <div class="flex items-end">
                     <button class="px-6 py-2 bg-[#0B1B3F] text-white rounded-lg">Search</button>
                 </div>
             </div>
             <div class="overflow-x-auto mt-8">
                 <table class="w-full">
                    <thead class="bg-gray-100 dark:bg-slate-700">
                        <tr>
                            <th class="p-3">Product</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="p-3">ST-01 GPS</td>
                            <td>LKR 12,000</td>
                            <td>Active</td>
                            <td><button class="text-blue-600">Edit</button></td>
                        </tr>
                    </tbody>
                </table>
             </div>
         </div>


        </main>

    </div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>