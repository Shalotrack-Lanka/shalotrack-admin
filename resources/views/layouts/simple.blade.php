<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ShaloTrack')</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
{{-- Minimal shell for roles that have no full portal yet (FINANCE, TECHNICIAN):
     top bar with who is signed in, a profile link and Logout. No admin sidebar,
     because those roles must never be shown admin navigation. --}}
<body class="bg-gray-100 font-sans antialiased">
    @php
        $me = Auth::user();
        $profileRoute = match ($me->role) {
            'FINANCE'    => 'finance.profile',
            'TECHNICIAN' => 'technician.profile',
            default      => 'admin.profile',
        };
    @endphp
    <header class="bg-white border-b border-gray-200 px-4 md:px-6 py-3 flex items-center justify-between">
        <span class="font-bold text-slate-900">ShaloTrack</span>
        <div class="flex items-center gap-4 text-sm">
            <span class="text-slate-600 hidden sm:inline">{{ $me->full_name }} · {{ $me->role }}</span>
            <a href="{{ route($profileRoute) }}" class="text-cyan-700 hover:underline">Profile</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-red-600 hover:underline">Logout</button>
            </form>
        </div>
    </header>

    <main class="max-w-3xl mx-auto p-4 md:p-6">
        @yield('content')
    </main>
</body>
</html>
