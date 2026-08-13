<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 25px; }
        body { font-family: 'Helvetica', Arial, sans-serif; font-size: 10px; color: #333; margin: 0; padding: 0; }

        .watermark {
            position: fixed; top: 25%; left: 10%; width: 80%;
            opacity: 0.08; z-index: -1000; text-align: center;
        }
        .watermark img { width: 450px; height: auto; }
        .watermark-text { font-size: 48px; font-weight: bold; color: #1a365d; letter-spacing: 4px; }

        .header { border-bottom: 2px solid #17a2b8; padding-bottom: 8px; margin-bottom: 12px; }
        .header table { width: 100%; }
        .title { font-size: 15px; font-weight: bold; color: #17a2b8; text-transform: uppercase; }
        .meta { font-size: 9px; color: #666; text-align: right; }

        .summary { margin-bottom: 15px; width: 100%; border-collapse: collapse; background-color: #f8fafc; border: 1px solid #e2e8f0; }
        .summary td { padding: 6px 10px; font-size: 9.5px; }
        .summary .label { color: #64748b; font-size: 8px; text-transform: uppercase; font-weight: bold; }

        table.data { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data thead th {
            background-color: #f1f5f9; text-align: left; padding: 6px 8px;
            font-size: 8.5px; text-transform: uppercase; border-bottom: 1.5px solid #cbd5e1; color: #1e293b;
        }
        table.data tbody td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; font-size: 9px; vertical-align: middle; }
        
        .badge-green { color: #15803d; font-weight: bold; }
        .badge-red { color: #b91c1c; font-weight: bold; }
        .footer { margin-top: 15px; font-size: 8px; color: #94a3b8; text-align: right; }
    </style>
</head>
<body>

    <!-- Watermark -->
    <div class="watermark">
        @if(!empty($logoBase64))
            <img src="{{ $logoBase64 }}" alt="Watermark Logo">
        @else
            <div class="watermark-text">SHALOTRACK</div>
            <div style="font-size: 13px; letter-spacing: 5px; color: #475569; margin-top: 4px;">ALWAYS CONNECTED</div>
        @endif
    </div>

    <!-- Header -->
    <div class="header">
        <table>
            <tr>
                <td>
                    <div class="title">{{ $title }}</div>
                    <div style="color: #64748b; font-size: 8.5px; margin-top: 2px;">ShaloTrack Vehicle Tracking History</div>
                </td>
                <td class="meta">
                    Generated: {{ now()->format('d M Y, h:i A') }}<br>
                    @if($fromDate || $toDate)
                        Range: {{ $fromDate ?? 'Start' }} to {{ $toDate ?? 'Now' }}
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <!-- Vehicle Summary -->
    @if($vehicle)
        <table class="summary">
            <tr>
                <td><div class="label">Vehicle No</div><strong>{{ $vehicle['vehicleNumber'] ?? '-' }}</strong></td>
                <td><div class="label">Make & Model</div>{{ $vehicle['make'] ?? '' }} {{ $vehicle['model'] ?? '' }}</td>
                <td><div class="label">Customer Name</div>{{ $vehicle['customerName'] ?? '-' }}</td>
                <td><div class="label">GPS Device IMEI</div><strong>{{ $vehicle['imei'] ?? 'None' }}</strong></td>
                <td><div class="label">Total Trips</div><strong>{{ count($tripsWithAddress) }} Trips</strong></td>
            </tr>
        </table>
    @endif

    <!-- Trip Details Table -->
    <table class="data">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 14%;">Date & Trip Time</th>
                <th style="width: 32%;">Start Location (Address)</th>
                <th style="width: 32%;">End Location (Address)</th>
                <th style="width: 9%; text-align: center;">Duration</th>
                <th style="width: 9%; text-align: right;">Distance</th>
            </tr>
        </thead>
        <tbody>
            @forelse($tripsWithAddress as $index => $trip)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>
                        <strong>{{ $trip['start_time']->format('d M Y') }}</strong><br>
                        <span style="color: #64748b; font-size: 8.5px;">
                            {{ $trip['start_time']->format('h:i A') }} - {{ $trip['end_time']->format('h:i A') }}
                        </span>
                    </td>
                    <td>
                        <span class="badge-green">● START:</span><br>
                        {{ $trip['start_address'] ?? 'Coordinates: ' . $trip['start_lat'] . ', ' . $trip['start_lng'] }}
                    </td>
                    <td>
                        <span class="badge-red">● END:</span><br>
                        {{ $trip['end_address'] ?? 'Coordinates: ' . $trip['end_lat'] . ', ' . $trip['end_lng'] }}
                    </td>
                    <td style="text-align: center;">
                        @if($trip['duration_min'] >= 60)
                            {{ intdiv($trip['duration_min'], 60) }}h {{ $trip['duration_min'] % 60 }}m
                        @else
                            {{ $trip['duration_min'] }}m
                        @endif
                    </td>
                    <td style="text-align: right; font-weight: bold; color: #0284c7;">
                        {{ $trip['distance_km'] }} km
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; color: #94a3b8; padding: 20px;">
                        No distinct trips recorded for this vehicle in the selected date range.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">ShaloTrack GPS Management System — Confidential Report</div>

</body>
</html><!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 25px; }
        body { font-family: 'Helvetica', Arial, sans-serif; font-size: 10px; color: #333; margin: 0; padding: 0; }

        .watermark {
            position: fixed; top: 25%; left: 10%; width: 80%;
            opacity: 0.08; z-index: -1000; text-align: center;
        }
        .watermark img { width: 450px; height: auto; }
        .watermark-text { font-size: 48px; font-weight: bold; color: #1a365d; letter-spacing: 4px; }

        .header { border-bottom: 2px solid #17a2b8; padding-bottom: 8px; margin-bottom: 12px; }
        .header table { width: 100%; }
        .title { font-size: 15px; font-weight: bold; color: #17a2b8; text-transform: uppercase; }
        .meta { font-size: 9px; color: #666; text-align: right; }

        .summary { margin-bottom: 15px; width: 100%; border-collapse: collapse; background-color: #f8fafc; border: 1px solid #e2e8f0; }
        .summary td { padding: 6px 10px; font-size: 9.5px; }
        .summary .label { color: #64748b; font-size: 8px; text-transform: uppercase; font-weight: bold; }

        table.data { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data thead th {
            background-color: #f1f5f9; text-align: left; padding: 6px 8px;
            font-size: 8.5px; text-transform: uppercase; border-bottom: 1.5px solid #cbd5e1; color: #1e293b;
        }
        table.data tbody td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; font-size: 9px; vertical-align: middle; }
        
        .badge-green { color: #15803d; font-weight: bold; }
        .badge-red { color: #b91c1c; font-weight: bold; }
        .footer { margin-top: 15px; font-size: 8px; color: #94a3b8; text-align: right; }
    </style>
</head>
<body>

    <!-- Watermark -->
    <div class="watermark">
        @if(!empty($logoBase64))
            <img src="{{ $logoBase64 }}" alt="Watermark Logo">
        @else
            <div class="watermark-text">SHALOTRACK</div>
            <div style="font-size: 13px; letter-spacing: 5px; color: #475569; margin-top: 4px;">ALWAYS CONNECTED</div>
        @endif
    </div>

    <!-- Header -->
    <div class="header">
        <table>
            <tr>
                <td>
                    <div class="title">{{ $title }}</div>
                    <div style="color: #64748b; font-size: 8.5px; margin-top: 2px;">ShaloTrack Vehicle Tracking History</div>
                </td>
                <td class="meta">
                    Generated: {{ now()->format('d M Y, h:i A') }}<br>
                    @if($fromDate || $toDate)
                        Range: {{ $fromDate ?? 'Start' }} to {{ $toDate ?? 'Now' }}
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <!-- Vehicle Summary -->
    @if($vehicle)
        <table class="summary">
            <tr>
                <td><div class="label">Vehicle No</div><strong>{{ $vehicle['vehicleNumber'] ?? '-' }}</strong></td>
                <td><div class="label">Make & Model</div>{{ $vehicle['make'] ?? '' }} {{ $vehicle['model'] ?? '' }}</td>
                <td><div class="label">Customer Name</div>{{ $vehicle['customerName'] ?? '-' }}</td>
                <td><div class="label">GPS Device IMEI</div><strong>{{ $vehicle['imei'] ?? 'None' }}</strong></td>
                <td><div class="label">Total Trips</div><strong>{{ count($tripsWithAddress) }} Trips</strong></td>
            </tr>
        </table>
    @endif

    <!-- Trip Details Table -->
    <table class="data">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 14%;">Date & Trip Time</th>
                <th style="width: 32%;">Start Location (Address)</th>
                <th style="width: 32%;">End Location (Address)</th>
                <th style="width: 9%; text-align: center;">Duration</th>
                <th style="width: 9%; text-align: right;">Distance</th>
            </tr>
        </thead>
        <tbody>
            @forelse($tripsWithAddress as $index => $trip)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>
                        <strong>{{ $trip['start_time']->format('d M Y') }}</strong><br>
                        <span style="color: #64748b; font-size: 8.5px;">
                            {{ $trip['start_time']->format('h:i A') }} - {{ $trip['end_time']->format('h:i A') }}
                        </span>
                    </td>
                    <td>
                        <span class="badge-green">● START:</span><br>
                        {{ $trip['start_address'] ?? 'Coordinates: ' . $trip['start_lat'] . ', ' . $trip['start_lng'] }}
                    </td>
                    <td>
                        <span class="badge-red">● END:</span><br>
                        {{ $trip['end_address'] ?? 'Coordinates: ' . $trip['end_lat'] . ', ' . $trip['end_lng'] }}
                    </td>
                    <td style="text-align: center;">
                        @if($trip['duration_min'] >= 60)
                            {{ intdiv($trip['duration_min'], 60) }}h {{ $trip['duration_min'] % 60 }}m
                        @else
                            {{ $trip['duration_min'] }}m
                        @endif
                    </td>
                    <td style="text-align: right; font-weight: bold; color: #0284c7;">
                        {{ $trip['distance_km'] }} km
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; color: #94a3b8; padding: 20px;">
                        No distinct trips recorded for this vehicle in the selected date range.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">ShaloTrack GPS Management System — Confidential Report</div>

</body>
</html>