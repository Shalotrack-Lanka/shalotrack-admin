<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ShaloTrack Admin')</title>
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
        </main>
    </div>
</div>
</body>
</html>