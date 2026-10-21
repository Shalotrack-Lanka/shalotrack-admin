@php $m = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<!doctype html><html><head><meta charset="utf-8">@include('billing.pdf._style')</head><body>
<table><tr><td style="border:0;width:60%"><h1>STATEMENT OF ACCOUNT</h1><p class="muted">{{ $from }} to {{ $to }}</p></td>
<td style="border:0" class="r"><b>{{ $company['legal_name'] }}</b><br>{!! nl2br(e($company['address'])) !!}</td></tr></table>
<div class="box"><h2>Account</h2><b>{{ $party['legal_name'] }}</b><br>{!! nl2br(e($party['address'] ?? '')) !!}</div>
<table style="margin-top:12px"><thead><tr><th>Date</th><th>What</th><th>Number</th><th>Detail</th><th class="r">Amount</th><th class="r">Balance</th></tr></thead><tbody>
<tr><td colspan="5"><b>Opening balance</b></td><td class="r"><b>{{ $m($stmt['opening']) }}</b></td></tr>
@foreach($stmt['rows'] as $r)<tr><td>{{ $r['date'] }}</td><td>{{ $r['type'] }}</td><td>{{ $r['number'] }}</td><td class="muted">{{ $r['note'] }}</td><td class="r">{{ $m($r['amount']) }}</td><td class="r">{{ $m($r['balance']) }}</td></tr>@endforeach
<tr><td colspan="5"><b>Closing balance</b></td><td class="r big">{{ $m($stmt['closing']) }}</td></tr></tbody></table>
<p class="foot">Amounts in LKR. A positive balance is owed to {{ $company['legal_name'] }}.</p></body></html>
