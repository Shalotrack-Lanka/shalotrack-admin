@extends('layouts.dealer')

@section('title', 'Resolved Complaints')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    {{-- HEADER SECTION --}}
    <div class="flex items-center justify-between bg-white p-6 rounded-3xl shadow-sm border border-slate-200">
        <div>
            <h2 class="text-xl font-black text-slate-900">Resolved Complaints</h2>
            <p class="text-xs text-slate-500 mt-1">History of all successfully resolved customer issues.</p>
        </div>
        <span class="px-4 py-1.5 rounded-full bg-emerald-50 text-emerald-700 text-xs font-bold border border-emerald-200 flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            {{ count($complaints ?? []) }} Resolved
        </span>
    </div>

    {{-- COMPLAINTS GRID --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        @forelse($complaints ?? [] as $complaint)
            <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-200 hover:shadow-md transition">
                
                <div class="flex justify-between items-start mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        <div>
                            <h4 class="font-bold text-slate-900 text-sm">{{ $complaint['vehicleNumber'] ?? 'Vehicle N/A' }}</h4>
                            <p class="text-xs text-slate-500 font-semibold mt-0.5">{{ $complaint['categoryName'] ?? 'General Issue' }}</p>
                        </div>
                    </div>
                    <span class="text-[10px] font-bold text-slate-400 bg-slate-50 px-2.5 py-1.5 rounded-lg border border-slate-100">
                        {{ \Carbon\Carbon::parse($complaint['resolvedAt'] ?? now())->format('d M Y') }}
                    </span>
                </div>

                <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 mb-4">
                    <p class="text-sm text-slate-700 leading-relaxed font-medium">{{ $complaint['description'] ?? 'No description provided.' }}</p>
                </div>

                @if(!empty($complaint['replies']))
                    <div class="space-y-3 pt-4 border-t border-slate-100">
                        <h5 class="text-[10px] font-black text-slate-400 uppercase tracking-wider">Resolution Notes</h5>
                        @foreach($complaint['replies'] as $reply)
                            <div class="flex gap-2.5 bg-blue-50/50 p-3 rounded-xl border border-blue-100/50">
                                <div class="w-1.5 h-1.5 rounded-full bg-blue-500 mt-1.5 shrink-0"></div>
                                <div>
                                    <span class="text-xs font-bold text-blue-900">{{ $reply['authorName'] ?? 'Support' }}</span>
                                    <p class="text-xs text-slate-600 mt-0.5">{{ $reply['message'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="col-span-full bg-white p-12 rounded-3xl shadow-sm border border-slate-200 text-center flex flex-col items-center justify-center">
                <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mb-4 border border-slate-100">
                    <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <h3 class="font-bold text-slate-900 text-lg">No Resolved Complaints Yet</h3>
                <p class="text-sm text-slate-500 mt-1">When customer issues are fixed, they will appear here as a history log.</p>
            </div>
        @endforelse
    </div>

</div>
@endsection