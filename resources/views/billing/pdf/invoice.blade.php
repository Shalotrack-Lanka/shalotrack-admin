@php $m = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<!doctype html><html><head><meta charset="utf-8">@include('billing.pdf._style')</head><body>
<table><tr><td style="border:0;width:60%"><h1>INVOICE</h1><p class="muted">{{ $number }}</p></td>
<td style="border:0" class="r"><b>{{ $snap['issuer']['legal_name'] }}</b><br>{!! nl2br(e($snap['issuer']['address'])) !!}<br>@if(! empty($snap['issuer']['tax_number']))Tax no.: {{ $snap['issuer']['tax_number'] }}<br>@endif{{ $snap['issuer']['phone'] ?? '' }}</td></tr></table>
<table style="margin-top:14px"><tr><td style="border:0;width:60%"><h2>Bill to</h2><b>{{ $snap['buyer']['legal_name'] }}</b><br>{!! nl2br(e($snap['buyer']['address'] ?? '')) !!}@if(! empty($snap['buyer']['tax_number']))<br>Tax no.: {{ $snap['buyer']['tax_number'] }}@endif</td>
<td style="border:0" class="r"><h2>Details</h2>Issue date: {{ $snap['issue_date'] }}<br>Due date: {{ $due }}<br>Currency: {{ $snap['currency'] }}</td></tr></table>
<table style="margin-top:14px"><thead><tr><th>Transfer</th><th>Device type</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Amount</th></tr></thead><tbody>
@foreach($snap['lines'] as $l)<tr><td>{{ $l['reference'] }}<br><span class="muted">{{ $l['transferred'] }}</span></td><td>{{ $l['category'] }}</td><td class="r">{{ $l['quantity'] }}</td><td class="r">{{ $m($l['unit_price']) }}</td><td class="r">{{ $m($l['line_total']) }}</td></tr>@endforeach
</tbody></table>
<table style="margin-top:8px;width:45%;margin-left:55%"><tr><td>Subtotal</td><td class="r">{{ $m($snap['subtotal']) }}</td></tr>
@if(($snap['tax_rate'] ?? null) !== null)<tr><td>{{ $snap['tax_label'] }} {{ $snap['tax_rate'] }}%</td><td class="r">{{ $m($snap['tax']) }}</td></tr>@endif
<tr><td class="big">Total (LKR)</td><td class="r big">{{ $m($snap['total']) }}</td></tr></table>
@if(! empty($snap['notes']))<p><b>Note:</b> {{ $snap['notes'] }}</p>@endif
@if(! empty($snap['zero_reason']))<p><b>Zero-value reason:</b> {{ $snap['zero_reason'] }}</p>@endif
@if(! empty($snap['issuer']['bank_details']))<div class="box"><h2>Please pay to</h2>{!! nl2br(e($snap['issuer']['bank_details'])) !!}</div>@endif
<p class="foot">{{ $snap['footer'] ?? '' }}<br>Issued by {{ $snap['issued_by'] ?? '' }}. This invoice cannot be altered after issue; corrections are made with a credit note.</p>
</body></html>
