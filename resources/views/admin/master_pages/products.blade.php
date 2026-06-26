<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        /* ── LIGHT MODE (default) ── *
        body                          { background:#f1f5f9; color:#1e293b; }
        .main-content                 { background:#f1f5f9; }
        .header-bar                   { background:#ffffff; border-bottom:1px solid #e2e8f0; }
        .card                         { background:#ffffff; border:1px solid #e2e8f0; color:#1e293b; }
        .card h3                      { color:#1e293b; }
        .table-head tr                { background:#f1f5f9; }
        .table-head th                { color:#1e293b; border-bottom:1px solid #e2e8f0; }
        .table-body td                { color:#1e293b; border-bottom:1px solid #e2e8f0; }
        .table-body tr:hover          { background:#f8fafc; }
        .username-text                { color:#374151; }
        .dropdown-menu                { background:#ffffff; border:1px solid #e2e8f0; }
        .dropdown-item                { color:#374151; }
        .dropdown-item:hover          { background:#f1f5f9; }
        .dropdown-info p              { color:#1e293b; }
        .dropdown-info span           { color:#6b7280; }
        .page-title                   { color:#1e293b; }
        .toggle-btn                   { background:#f1f5f9; border:1px solid #e2e8f0; }
        .chart-card                   { background:#ffffff; border:1px solid #e2e8f0; }
        .chart-card h3                { color:#1e293b; }

        /* ── DARK MODE ── *
        .dark body                    { background:#0f172a; color:#f1f5f9; }
        .dark .main-content           { background:#0f172a; }
        .dark .header-bar             { background:#1e293b; border-bottom:1px solid #334155; }
        .dark .card                   { background:#1e293b; border:1px solid #334155; color:#f1f5f9; }
        .dark .card h3                { color:#f1f5f9; }
        .dark .table-head tr          { background:#0f172a; }
        .dark .table-head th          { color:#f1f5f9; border-bottom:1px solid #334155; }
        .dark .table-body td          { color:#f1f5f9; border-bottom:1px solid #334155; }
        .dark .table-body tr:hover    { background:#334155; }
        .dark .username-text          { color:#f1f5f9; }
        .dark .dropdown-menu          { background:#1e293b; border:1px solid #334155; }
        .dark .dropdown-item          { color:#f1f5f9; }
        .dark .dropdown-item:hover    { background:#334155; }
        .dark .dropdown-info p        { color:#f1f5f9; }
        .dark .dropdown-info span     { color:#94a3b8; }
        .dark .page-title             { color:#f1f5f9; }
        .dark .toggle-btn             { background:#334155; border:1px solid #475569; }
        .dark .chart-card             { background:#1e293b; border:none; }
        .dark .chart-card h3          { color:#ffffff; } */
    </style>
</head>
<body x-data="{ sidebarOpen: false }"> <!-- Added Alpine state for Mobile Menu Toggle -->

<div class="flex h-screen overflow-hidden"> <!-- Prevent double scrollbars -->

    @include('partials.sidebars.admin')

    @include('partials.header')

        <main class="p-4 md:p-6 flex-1">
            @yield('content')

<div class="space-y-6">

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

        <!-- Product Type -->
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow">

            <div class="border-b px-6 py-4">
                <h2 class="text-xl font-bold">
                    Product Type
                </h2>
            </div>

            <div class="p-6">

                <form>

                    <div class="space-y-5">

                        <div>
                            <label class="block mb-2 font-medium">
                                Product Type
                            </label>

                            <input
                                type="text"
                                class="w-full rounded-lg border-gray-300">

                        </div>

                        <div class="flex items-center gap-3">

                            <input
                                type="checkbox">

                            Upload Serial Number Required

                        </div>

                        <div class="flex gap-3">

                            <button
                                class="px-5 py-2 bg-orange-500 text-white rounded-lg">

                                Save

                            </button>

                            <button
                                type="reset"
                                class="px-5 py-2 bg-gray-300 rounded-lg">

                                Reset

                            </button>

                        </div>

                    </div>

                </form>

            </div>

        </div>

        <!-- Add Product -->

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow">

            <div class="border-b px-6 py-4">

                <h2 class="text-xl font-bold">

                    Add Product

                </h2>

            </div>

            <div class="p-6">

                <form enctype="multipart/form-data">

                    <div class="grid grid-cols-1 gap-4">

                        <select class="rounded-lg border-gray-300">

                            <option>Select Product Type</option>

                        </select>

                        <input
                            class="rounded-lg border-gray-300"
                            placeholder="Product Name">

                        <textarea
                            rows="3"
                            class="rounded-lg border-gray-300"
                            placeholder="Description"></textarea>

                        <input
                            type="number"
                            class="rounded-lg border-gray-300"
                            placeholder="Product Price">

                        <input
                            type="number"
                            class="rounded-lg border-gray-300"
                            placeholder="Device Price">

                        <input
                            type="number"
                            class="rounded-lg border-gray-300"
                            placeholder="Service Price">

                        <input
                            type="file">

                        <select
                            class="rounded-lg border-gray-300">

                            <option>Trade Type</option>

                            <option>Retail</option>

                            <option>Dealer</option>

                            <option>Distributor</option>

                        </select>

                        <div class="flex items-center gap-2">

                            <input type="checkbox">

                            Testing Required

                        </div>

                        <input
                            type="number"
                            class="rounded-lg border-gray-300"
                            placeholder="Minimum Alert Qty">

                        <div class="flex gap-3">

                            <button
                                class="bg-[#0B1B3F] text-white px-5 py-2 rounded-lg hover:bg-blue-900">

                                Add Product

                            </button>

                            <button
                                type="reset"
                                class="bg-gray-300 px-5 py-2 rounded-lg">

                                Reset

                            </button>

                        </div>

                    </div>

                </form>

            </div>

        </div>

    </div>

    <!-- Tables -->

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

        <!-- Product Types -->

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow">

            <div class="px-6 py-4 border-b">

                <h2 class="font-bold">

                    Product Types

                </h2>

            </div>

            <div class="overflow-x-auto">

                <table class="w-full">

                    <thead class="bg-gray-100 dark:bg-slate-700">

                    <tr>

                        <th class="p-3">Type</th>

                        <th>Serial</th>

                        <th>Action</th>

                    </tr>

                    </thead>

                    <tbody>

                    <tr>

                        <td class="p-3">
                            GPS Device
                        </td>

                        <td>
                            Yes
                        </td>

                        <td>

                            <button class="text-blue-600">
                                Edit
                            </button>

                            |

                            <button class="text-red-600">
                                Delete
                            </button>

                        </td>

                    </tr>

                    </tbody>

                </table>

            </div>

        </div>

        <!-- Product List -->

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow">

            <div class="px-6 py-4 border-b">

                <h2 class="font-bold">

                    Products

                </h2>

            </div>

            <div class="overflow-x-auto">

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

                        <td class="p-3">

                            ST-01 GPS

                        </td>

                        <td>

                            LKR 12,000

                        </td>

                        <td>

                            <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full">

                                Active

                            </span>

                        </td>

                        <td>

                            <button class="text-blue-600">

                                Edit

                            </button>

                            |

                            <button class="text-red-600">

                                Delete

                            </button>

                        </td>

                    </tr>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>


<!-- Dark Mode Script --
<script>
const html = document.documentElement;
const themeToggle = document.getElementById('themeToggle');
const moonIcon = document.getElementById('moonIcon');
const sunIcon = document.getElementById('sunIcon');

function updateIcons() {
    if (html.classList.contains('dark')) {
        moonIcon.classList.add('hidden');
        sunIcon.classList.remove('hidden');
    } else {
        moonIcon.classList.remove('hidden');
        sunIcon.classList.add('hidden');
    }
}

// Load saved theme
if (localStorage.getItem('theme') === 'dark') {
    html.classList.add('dark');
}

updateIcons();

// Toggle theme
themeToggle.addEventListener('click', () => {
    html.classList.toggle('dark');

    if (html.classList.contains('dark')) {
        localStorage.setItem('theme', 'dark');
    } else {
        localStorage.setItem('theme', 'light');
    }

    updateIcons();
});
</script> -->

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>