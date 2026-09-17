@extends('layouts.dealer')

@section('title', 'Complaints')

@section('content')
<div class="max-w-7xl mx-auto space-y-5" x-data="{ replyOpen: null }">

    @if(session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
             class="p-3 bg-green-100 border border-green-300 text-green-800 rounded-xl flex justify-between items-center text-xs">
            <span class="font-bold">{{ session('success') }}</span>
            <button @click="show = false" class="text-green-600 font-bold">&times;</button>
        </div>
    @endif
    @if($errors->any())
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
             class="p-3 bg-red-100 border border-red-300 text-red-800 rounded-xl flex justify-between items-center text-xs mb-4">
            <ul class="list-disc pl-5 font-bold">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button @click="show = false" class="text-red-600 font-bold">&times;</button>
        </div>
    @endif

    {{-- Category/Status labels must stay in sync with the C# API's
         ComplaintCategory/ComplaintStatus enums -- they serialize as
         raw integers, no JsonStringEnumConverter configured there. --}}
    @php
        $categoryLabels = ['Device Issue', 'Billing', 'App Bug', 'Other'];
        $statusLabels = ['With You', 'With ShaloTrack Support', 'Resolved', 'Closed'];
        $statusColors = ['bg-amber-100 text-amber-800', 'bg-blue-100 text-blue-800', 'bg-green-100 text-green-800', 'bg-slate-100 text-slate-600'];
    @endphp

    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-black text-blue-950">Complaints</h1>
            <p class="text-xs text-slate-500 mt-0.5">Device and account issues raised by your customers.</p>
        </div>
        <span class="bg-blue-50 text-blue-950 border border-blue-200 py-1 px-3 rounded-full text-xs font-black">
            {{ count($complaints) }} Complaints
        </span>
    </div>

    <div class="space-y-3">
        @forelse($complaints as $complaint)
            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                <div class="flex flex-wrap justify-between items-start gap-2">
                    <div>
                        <span class="{{ $statusColors[$complaint['status']] ?? 'bg-slate-100 text-slate-600' }} text-xs font-black px-2 py-1 rounded-full">
                            {{ $statusLabels[$complaint['status']] ?? 'Unknown' }}
                        </span>
                        <span class="ml-2 text-xs font-bold text-slate-500">
                            {{ $categoryLabels[$complaint['category']] ?? 'Other' }}
                        </span>
                        <h3 class="text-sm font-black text-blue-950 mt-1">
                            {{ $complaint['vehicleNumber'] ?? '' }}
                            @if(!empty($complaint['make']) || !empty($complaint['model']))
                                &mdash; {{ trim(($complaint['make'] ?? '') . ' ' . ($complaint['model'] ?? '')) }}
                            @endif
                        </h3>
                    </div>
                    <span class="text-xs text-slate-400">{{ \Carbon\Carbon::parse($complaint['createdAt'])->diffForHumans() }}</span>
                </div>

                <p class="text-sm text-slate-700 mt-2">{{ $complaint['description'] }}</p>

                @if(!empty($complaint['replies']))
                    <div class="mt-3 space-y-2 border-t border-slate-100 pt-3">
                        @foreach($complaint['replies'] as $reply)
                            <div class="text-xs">
                                <span class="font-black text-blue-950">{{ $reply['authorName'] }}:</span>
                                <span class="text-slate-600">{{ $reply['message'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($complaint['status'] === 0)
                    <div class="mt-3 flex flex-wrap gap-2 items-center">
                        <button @click="replyOpen = (replyOpen === '{{ $complaint['complaintId'] }}' ? null : '{{ $complaint['complaintId'] }}')"
                                class="text-xs font-black text-blue-700 px-3 py-1.5 rounded-lg border border-blue-200 hover:bg-blue-50">
                            Reply
                        </button>
                        <form method="POST" action="{{ route('dealer.complaints.escalate', $complaint['complaintId']) }}">
                            @csrf
                            <button type="submit" class="text-xs font-black text-red-700 px-3 py-1.5 rounded-lg border border-red-200 hover:bg-red-50"
                                    onclick="return confirm('Transfer this complaint to ShaloTrack support?')">
                                Transfer to Support
                            </button>
                        </form>
                    </div>

                    <form method="POST" action="{{ route('dealer.complaints.reply', $complaint['complaintId']) }}"
                          x-show="replyOpen === '{{ $complaint['complaintId'] }}'" class="mt-2 flex gap-2">
                        @csrf
                        <input type="text" name="message" required maxlength="2000" placeholder="Type your reply..."
                               class="flex-1 text-xs border border-slate-200 rounded-lg px-3 py-2">
                        <button type="submit" class="text-xs font-black bg-blue-700 text-white px-4 py-2 rounded-lg">Send</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="bg-white p-8 rounded-2xl border border-slate-200 text-center text-sm text-slate-500">
                No complaints right now.
            </div>
        @endforelse
    </div>
</div>
@endsection