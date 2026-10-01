{{-- Shared by the staff and dealer "Renewals due" pages. --}}
@php
    use App\Services\RenewalsReport;
    $isOverdue = $scope === RenewalsReport::SCOPE_OVERDUE;
@endphp

<div class="space-y-5">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <h1 class="text-2xl font-black text-blue-950">Renewals due</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Call these customers before their subscription ends. Live tracking and trip history pause when it does.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route($routeName, ['scope' => 'due', 'days' => $days]) }}"
               class="px-4 py-2 rounded-xl text-xs font-bold border {{ ! $isOverdue ? 'bg-blue-950 text-white border-blue-950' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
                Due soon ({{ $dueCount }})
            </a>
            <a href="{{ route($routeName, ['scope' => 'overdue', 'days' => $days]) }}"
               class="px-4 py-2 rounded-xl text-xs font-bold border {{ $isOverdue ? 'bg-red-700 text-white border-red-700' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
                Overdue / not paid ({{ $overdueCount }})
            </a>

            @unless($isOverdue)
                <form method="GET" action="{{ route($routeName) }}" class="flex items-center gap-2">
                    <input type="hidden" name="scope" value="due">
                    <select name="days" onchange="this.form.submit()"
                            class="text-xs font-bold border border-slate-300 rounded-xl px-3 py-2 bg-slate-50">
                        @foreach($windows as $w)
                            <option value="{{ $w }}" {{ $days === $w ? 'selected' : '' }}>Next {{ $w }} days</option>
                        @endforeach
                    </select>
                </form>
            @endunless

            <a href="{{ route($routeName, ['scope' => $scope, 'days' => $days, 'export' => 'csv']) }}"
               class="px-4 py-2 rounded-xl text-xs font-bold border border-slate-300 bg-white text-slate-700 hover:bg-slate-50">
                Export CSV
            </a>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                    <tr>
                        <th class="p-3">Customer</th>
                        <th class="p-3">Phone</th>
                        <th class="p-3">Vehicle</th>
                        <th class="p-3">IMEI</th>
                        @if($showDealer)<th class="p-3">Dealer</th>@endif
                        <th class="p-3">{{ $isOverdue ? 'Ended on' : 'Ends on' }}</th>
                        @unless($isOverdue)<th class="p-3">Days left</th>@endunless
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                    @forelse($rows as $row)
                        @php
                            $end = $isOverdue
                                ? ($row->ended_on ? \Illuminate\Support\Carbon::parse($row->ended_on) : null)
                                : $row->subscription_end_date;
                            $left = $isOverdue ? null : RenewalsReport::daysLeft($end);
                            $tone = $left === null ? '' : ($left <= 1 ? 'bg-red-100 text-red-700' : ($left <= 7 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'));
                        @endphp
                        <tr>
                            <td class="p-3 font-semibold">{{ $row->customer_name ?: '—' }}</td>
                            <td class="p-3">
                                @if($row->customer_phone)
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $row->customer_phone) }}" class="text-blue-700 hover:underline">{{ $row->customer_phone }}</a>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="p-3">{{ $row->vehicle_number ?: '—' }}</td>
                            <td class="p-3 font-mono text-xs">{{ $row->imei_number }}</td>
                            @if($showDealer)<td class="p-3">{{ $row->dealer_name ?: 'Company' }}</td>@endif
                            <td class="p-3">
                                @if($end)
                                    {{ $end->copy()->local()->format('Y-m-d') }}
                                @else
                                    <span class="text-slate-400">{{ $isOverdue ? 'Never paid' : '—' }}</span>
                                @endif
                            </td>
                            @unless($isOverdue)
                                <td class="p-3">
                                    <span class="inline-block px-2.5 py-1 rounded-lg text-[11px] font-bold {{ $tone }}">
                                        {{ $left === 0 ? 'Today' : ($left === 1 ? '1 day' : $left . ' days') }}
                                    </span>
                                </td>
                            @endunless
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-8 text-center text-slate-400 text-sm">
                                {{ $isOverdue ? 'No overdue subscriptions.' : 'Nothing due in this window.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($rows->hasPages())
            <div class="p-4 border-t border-slate-100">{{ $rows->links() }}</div>
        @endif
    </div>
</div>