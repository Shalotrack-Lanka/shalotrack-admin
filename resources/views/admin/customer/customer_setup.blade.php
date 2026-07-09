<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Customer Setup</title>

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

            <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                <h3 class="text-xl font-bold text-gray-800">Customer Setup</h3>
                <p class="text-gray-500 mt-2">Page under construction.</p>
            </div>

        </main>
    </div>

</div>

</body>
</html>