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
            <h2 class="text-2xl font-semibold mb-6">Add Features</h2>

            <form>
                <div class="space-y-4">

        <div>
            <label class="block mb-2 font-medium">Product Type</label>
            <select class="w-full rounded-lg border-gray-300 dark:bg-slate-700">
                <option>--Select--</option>
            </select>
        </div>

    <div>

        <label class="block mb-2 font-medium">Product Name</label>
        <select class="w-full rounded-lg border-gray-300 dark:bg-slate-700">
            <option>--Select--</option>
        </select>
    </div>

    <div>
        <label class="block mb-2 font-medium">Feature Name</label>
        <input type="text" class="w-full rounded-lg border-gray-300 dark:bg-slate-700">
    </div>

    <div>
        <label class="block mb-2 font-medium">Feature Remarks</label>
        <textarea rows="5" class="w-full rounded-lg border-gray-300 dark:bg-slate-700"></textarea>
    </div>

    <div class="flex gap-3">
        <button class="px-5 py-2 bg-[#0B1B3F] text-white rounded-lg">Add Feature</button>
        <button type="reset" class="px-5 py-2 bg-gray-300 rounded-lg">Reset</button>
    </div>

  </div>
</form>
</div>

<div class="bg-white dark:bg-slate-800 rounded-xl shadow">

    <div class="border-b px-6 py-4 flex gap-2">
        <button class="px-4 py-2 bg-[#0B1B3F] text-white rounded-lg">Active</button>
        <button class="px-4 py-2 bg-gray-300 rounded-lg">Archived</button>
    </div>

        <div class="overflow-x-auto">

            <table class="w-full">
                <thead class="bg-gray-100 dark:bg-slate-700">
                    <tr>
                        <th class="p-3 text-left">Product</th>
                         <th>Feature</th>
                        <th>Remarks</th>
                         <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <tr class="border-b">
                        <td class="p-3">ST-01 GPS</td>
                        <td>Engine Lock</td>
                        <td>Sample Feature</td>
                        <td>
                            <button class="text-blue-600">Edit</button> |
                            <button class="text-red-600">Delete</button>
                        </td>
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