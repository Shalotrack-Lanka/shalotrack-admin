<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ShaloTrack Distributor')</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
{{-- Distributor portal shell: its own navigation, never the admin sidebar. --}}
<body class="bg-gray-100 font-sans antialiased">
    @php $me = Auth::user(); @endphp
    <header class="bg-[#0B1B3F] text-white">
        <div class="max-w-6xl mx-auto px-4 md:px-6 py-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <span class="font-bold text-lg">ShaloTrack</span>
                <span class="text-xs text-blue-200 ml-2">Distributor Portal</span>
            </div>
            <nav class="flex flex-wrap items-center gap-4 text-sm">
                <a href="{{ route('distributor.dashboard') }}" class="{{ request()->routeIs('distributor.dashboard') ? 'font-semibold underline' : 'hover:underline' }}">Dashboard</a>
                <a href="{{ route('distributor.dealers') }}" class="{{ request()->routeIs('distributor.dealers') ? 'font-semibold underline' : 'hover:underline' }}">My dealers</a>
                <a href="{{ route('distributor.stock') }}" class="{{ request()->routeIs('distributor.stock') ? 'font-semibold underline' : 'hover:underline' }}">My stock</a>
                <a href="{{ route('distributor.transfer') }}" class="{{ request()->routeIs('distributor.transfer*') ? 'font-semibold underline' : 'hover:underline' }}">Transfer</a>
                <a href="{{ route('distributor.profile') }}" class="{{ request()->routeIs('distributor.profile') ? 'font-semibold underline' : 'hover:underline' }}">Profile</a>
                <span class="hidden md:inline text-blue-200">{{ $me->full_name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-red-300 hover:underline">Logout</button>
                </form>
            </nav>
        </div>
    </header>

    <main class="max-w-6xl mx-auto p-4 md:p-6">
        @yield('content')
    </main>
</body>
</html>