<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resolved Complaints - ShaloTrack Admin</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-100 font-sans antialiased">

<div class="flex h-screen overflow-hidden" x-data="{ sidebarOpen: false }"> 
    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col h-screen overflow-y-auto">

        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 w-full max-w-full">

            @php
                $categoryLabels = ['Device Issue', 'Billing', 'App Bug', 'Other'];
                $statusLabels = ['With Dealer', 'With Admin', 'Resolved', 'Closed'];
                $statusColors = ['bg-amber-100 text-amber-800', 'bg-blue-100 text-blue-800', 'bg-green-100 text-green-800', 'bg-gray-100 text-gray-600'];
            @endphp

            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-3">
                    <h3 class="text-xl font-bold text-gray-800">Resolved Complaints</h3>
                    
                    {{-- Changed badge color to green to match the resolved status --}}
                    <span class="bg-green-50 text-green-800 border border-green-200 py-1 px-3 rounded-full text-xs font-bold">
                        {{ count($complaints) }} Resolved
                    </span>
                </div>

                <div class="p-6 space-y-4">
                    @forelse($complaints as $complaint)
                        <div class="border border-gray-200 rounded-xl p-4">
                            <div class="flex flex-wrap justify-between items-start gap-2">
                                <div>
                                    <span class="{{ $statusColors[$complaint['status']] ?? 'bg-gray-100 text-gray-600' }} text-xs font-bold px-2 py-1 rounded-full">
                                        {{ $statusLabels[$complaint['status']] ?? 'Unknown' }}
                                    </span>
                                    <span class="ml-2 text-xs font-semibold text-gray-500">
                                        {{ $categoryLabels[$complaint['category']] ?? 'Other' }}
                                    </span>
                                    @if(!empty($complaint['dealerName']))
                                        <span class="ml-2 text-xs text-gray-400">via {{ $complaint['dealerName'] }}</span>
                                    @endif
                                    <h4 class="text-sm font-bold text-gray-800 mt-1">
                                        {{ $complaint['vehicleNumber'] ?? '' }}
                                        @if(!empty($complaint['make']) || !empty($complaint['model']))
                                            &mdash; {{ trim(($complaint['make'] ?? '') . ' ' . ($complaint['model'] ?? '')) }}
                                        @endif
                                    </h4>
                                </div>
                                <span class="text-xs text-gray-400">{{ \Carbon\Carbon::parse($complaint['createdAt'])->diffForHumans() }}</span>
                            </div>

                            <p class="text-sm text-gray-700 mt-2">{{ $complaint['description'] }}</p>

                            @if(!empty($complaint['replies']))
                                <div class="mt-3 space-y-2 border-t border-gray-100 pt-3">
                                    @foreach($complaint['replies'] as $reply)
                                        <div class="text-xs">
                                            <span class="font-bold text-gray-800">{{ $reply['authorName'] }}:</span>
                                            <span class="text-gray-600">{{ $reply['message'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            
                            {{-- Action buttons completely removed for the Resolved view --}}
                        </div>
                    @empty
                        <div class="p-12 text-center bg-gray-50 rounded-xl">
                            <p class="text-gray-600 font-semibold text-base">No resolved complaints found.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </main>

        
    </div>
</div>

</body>
</html>