@php $m = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<!doctype html><html><head><meta charset="utf-8">@include('billing.pdf._style')</head><body>
<table><tr><td style="border:0;width:60%"><h1>CREDIT NOTE</h1><p class="muted">{{ $number }} · against invoice {{ $snap['invoice'] }}</p></td>
<td style="border:0" class="r"><b>{{ $snap['issuer']['legal_name'] ?? '' }}</b><br>{!! nl2br(e($snap['issuer']['address'] ?? '')) !!}</td></tr></table>
<table style="margin-top:14px"><tr><td style="border:0;width:60%"><h2>Credited to</h2><b>{{ $snap['buyer']['legal_name'] ?? '' }}</b><br>{!! nl2br(e($snap['buyer']['address'] ?? '')) !!}</td><td style="border:0" class="r">Issue date: {{ $snap['issue_date'] }}<br>Currency: LKR</td></tr></table>
<p><b>Reason:</b> {{ $snap['reason'] }}</p>
<table><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Amount</th></tr></thead><tbody>
@foreach($snap['lines'] as $l)<tr><td>{{ $l['description'] }}</td><td class="r">{{ $l['quantity'] }}</td><td class="r">{{ $m($l['unit_price']) }}</td><td class="r">{{ $m($l['line_total']) }}</td></tr>@endforeach</tbody></table>
<table style="margin-top:8px;width:45%;margin-left:55%"><tr><td>Subtotal</td><td class="r">{{ $m($snap['subtotal']) }}</td></tr>@if($snap['tax'] > 0)<tr><td>Tax</td><td class="r">{{ $m($snap['tax']) }}</td></tr>@endif<tr><td class="big">Credited (LKR)</td><td class="r big">{{ $m($snap['total']) }}</td></tr></table>
<p class="foot">Issued by {{ $snap['issued_by'] ?? '' }}.</p></body></html>
