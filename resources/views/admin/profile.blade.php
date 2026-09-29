@extends(Auth::user()->role === 'ADMIN' ? 'layouts.admin' : 'layouts.simple')

@section('title', 'My Profile')

@section('content')
    @php $me = Auth::user(); @endphp
    <div class="max-w-2xl bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h1 class="text-lg font-bold text-slate-900">{{ $me->full_name }}</h1>
            <p class="text-sm text-slate-500">{{ $me->role }}</p>
        </div>
        <dl class="divide-y divide-gray-100 text-sm">
            @foreach ([
                'Username' => $me->username,
                'Email'    => $me->email,
                'Phone'    => $me->phone_number,
                'Status'   => $me->status,
            ] as $label => $value)
                <div class="px-6 py-3 flex justify-between gap-4">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="font-medium text-slate-900 text-right">{{ $value ?: '—' }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="px-6 py-3 text-xs text-slate-400 border-t border-gray-100">
            To change your details or password, ask a ShaloTrack administrator.
        </p>
    </div>
@endsection
