<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ShaloTrack Distributor')</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak]{display:none !important}</style>
</head>
{{-- Same shell as the admin portal (sidebar + shared header), so every portal looks and behaves alike. --}}
<body x-data="{ sidebarOpen: false }">
<div class="flex h-screen overflow-hidden">
    @include('partials.sidebars.distributor')
    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')
        <main class="p-4 md:p-6 flex-1 bg-gray-50">
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
