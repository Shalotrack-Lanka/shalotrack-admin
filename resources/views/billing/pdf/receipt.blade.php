@php $m = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<!doctype html><html><head><meta charset="utf-8">@include('billing.pdf._style')</head><body>
<table><tr><td style="border:0;width:60%"><h1>PAYMENT RECEIPT</h1><p class="muted">{{ $number }}</p></td>
<td style="border:0" class="r"><b>{{ $snap['issuer']['legal_name'] ?? '' }}</b><br>{!! nl2br(e($snap['issuer']['address'] ?? '')) !!}</td></tr></table>
@if($status === 'reversed')<p class="stamp">REVERSED: this payment was cancelled and no longer counts.</p>@endif
<div class="box"><h2>Received from</h2><b>{{ $snap['payer']['legal_name'] ?? '' }}</b></div>
<table style="margin-top:12px"><tr><td>Amount received</td><td class="r big">LKR {{ $m($snap['amount']) }}</td></tr>
<tr><td>Date received</td><td class="r">{{ $snap['received_date'] }}</td></tr>
<tr><td>Method</td><td class="r">{{ \App\Models\PaymentReceipt::METHODS[$snap['method']] ?? $snap['method'] }}</td></tr>
@if(! empty($snap['reference']))<tr><td>Reference</td><td class="r">{{ $snap['reference'] }}</td></tr>@endif</table>
<p class="foot">Recorded by {{ $snap['recorded_by'] ?? '' }} on {{ $snap['recorded_at'] ?? '' }}.</p></body></html>
