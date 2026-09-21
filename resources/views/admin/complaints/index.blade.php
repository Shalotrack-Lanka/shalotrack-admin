<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-100 font-sans antialiased">

<div class="flex h-screen overflow-hidden" x-data="{ sidebarOpen: false }"> @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col h-screen overflow-y-auto">

        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 w-full max-w-full" x-data="{ replyOpen: null }">

            {{-- Category/Status labels must stay in sync with the C# API's
                 ComplaintCategory/ComplaintStatus enums -- they serialize
                 as raw integers, no JsonStringEnumConverter there. --}}
            @php
                $categoryLabels = ['Device Issue', 'Billing', 'App Bug', 'Other'];
                $statusLabels = ['With Dealer', 'With Admin', 'Resolved', 'Closed'];
                $statusColors = ['bg-amber-100 text-amber-800', 'bg-blue-100 text-blue-800', 'bg-green-100 text-green-800', 'bg-gray-100 text-gray-600'];
            @endphp

            @if(session('success'))
                <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded-xl text-sm font-semibold">
                    {{ session('success') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mb-4 p-3 bg-red-100 border border-red-300 text-red-800 rounded-xl text-sm font-semibold">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-3">
                    <h3 class="text-xl font-bold text-gray-800">Complaints</h3>
                    <span class="bg-blue-50 text-blue-800 border border-blue-200 py-1 px-3 rounded-full text-xs font-bold">
                        {{ count($complaints) }} With Admin
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

                            @if(in_array($complaint['status'], [0, 1]))
                                <div class="mt-3 flex flex-wrap gap-2 items-center">
                                    <button @click="replyOpen = (replyOpen === '{{ $complaint['complaintId'] }}' ? null : '{{ $complaint['complaintId'] }}')"
                                            class="text-xs font-bold text-blue-700 px-3 py-1.5 rounded-lg border border-blue-200 hover:bg-blue-50">
                                        Reply
                                    </button>
                                    <form method="POST" action="{{ route('admin.complaints.resolve', $complaint['complaintId']) }}">
                                        @csrf
                                        <button type="submit" class="text-xs font-bold text-green-700 px-3 py-1.5 rounded-lg border border-green-200 hover:bg-green-50">
                                            Mark Resolved
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.complaints.close', $complaint['complaintId']) }}">
                                        @csrf
                                        <button type="submit" class="text-xs font-bold text-gray-600 px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50"
                                                onclick="return confirm('Close this complaint?')">
                                            Close
                                        </button>
                                    </form>
                                </div>

                                <form method="POST" action="{{ route('admin.complaints.reply', $complaint['complaintId']) }}"
                                      x-show="replyOpen === '{{ $complaint['complaintId'] }}'" class="mt-2 flex gap-2">
                                    @csrf
                                    <input type="text" name="message" required maxlength="2000" placeholder="Type your reply..."
                                           class="flex-1 text-xs border border-gray-200 rounded-lg px-3 py-2">
                                    <button type="submit" class="text-xs font-bold bg-blue-700 text-white px-4 py-2 rounded-lg">Send</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <div class="p-12 text-center bg-gray-50 rounded-xl">
                            <p class="text-gray-600 font-semibold text-base">No complaints right now.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </main>

    </div>
</div>

</body>
</html>