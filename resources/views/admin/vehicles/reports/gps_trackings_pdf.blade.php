<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Helvetica', Arial, sans-serif; font-size: 11px; color: #333; }

        .watermark {
            position: fixed; top: 300px; left: 50%; width: 440px; margin-left: -220px;
            opacity: 0.18; z-index: -1; text-align: center;
        }
        .watermark .wordmark {
            margin-top: 6px; font-family: Arial, Helvetica, sans-serif;
            font-weight: 900; font-size: 2.4rem; letter-spacing: 3px;
        }
        .watermark .wordmark .shalo { color: #1B2E5E; }
        .watermark .wordmark .track { color: #F07A1A; }
        .watermark .tagline { margin-top: 8px; font-size: 0.9rem; letter-spacing: 6px; color: #1B2E5E; }
        .watermark .subtitle { margin-top: 6px; font-size: 0.6rem; letter-spacing: 2px; color: #888; }

        .header { border-bottom: 2px solid #17a2b8; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { font-size: 16px; margin: 0 0 4px 0; color: #17a2b8; }
        .header .meta { font-size: 10px; color: #777; }

        .summary { margin-bottom: 16px; }
        .summary td { padding: 4px 12px 4px 0; font-size: 11px; }
        .summary .label { color: #888; font-size: 9px; text-transform: uppercase; }

        table.data { width: 100%; border-collapse: collapse; }
        table.data thead th {
            background-color: #f5f5f5; text-align: left; padding: 6px 8px;
            font-size: 10px; text-transform: uppercase; border-bottom: 1px solid #ccc;
        }
        table.data tbody td { padding: 5px 8px; border-bottom: 1px solid #eee; }
        .footer { margin-top: 20px; font-size: 9px; color: #999; text-align: right; }
    </style>
</head>
<body>

    <div class="watermark">
        <svg width="140" viewBox="0 0 220 200" xmlns="http://www.w3.org/2000/svg">
            <g transform="translate(110, 90) scale(0.9)">
                <path d="M-58,30 Q-72,-30 -30,-72 Q0,-95 30,-72 Q58,-50 62,-20" fill="none" stroke="#F07A1A" stroke-width="14" stroke-linecap="round"/>
                <path d="M-10,50 Q10,70 35,50 Q55,28 62,-20" fill="none" stroke="#F07A1A" stroke-width="12" stroke-linecap="round"/>
                <ellipse cx="0" cy="-28" rx="52" ry="58" fill="#1B2E5E"/>
                <path d="M-18,24 Q0,68 18,24" fill="#1B2E5E"/>
                <path d="M-16,-60 Q-30,-35 -8,-12 Q12,10 -4,32" fill="none" stroke="white" stroke-width="10" stroke-linecap="round"/>
                <path d="M28,-72 Q38,-68 34,-58" fill="none" stroke="#F07A1A" stroke-width="4" stroke-linecap="round"/>
                <path d="M36,-80 Q52,-72 46,-56" fill="none" stroke="#F07A1A" stroke-width="4" stroke-linecap="round"/>
                <path d="M44,-88 Q65,-76 58,-55" fill="none" stroke="#F07A1A" stroke-width="4" stroke-linecap="round"/>
                <g transform="translate(0, 18)">
                    <rect x="-22" y="-8" width="44" height="16" rx="4" fill="#1B2E5E" stroke="white" stroke-width="1.2"/>
                    <path d="M-14,-8 Q-10,-18 10,-18 Q16,-18 20,-8" fill="#1B2E5E" stroke="white" stroke-width="1.2"/>
                    <path d="M-10,-8 Q-7,-15 9,-15 Q14,-15 18,-8" fill="white" opacity="0.25"/>
                    <circle cx="-12" cy="8" r="4.5" fill="white"/>
                    <circle cx="12" cy="8" r="4.5" fill="white"/>
                    <circle cx="-12" cy="8" r="2" fill="#1B2E5E"/>
                    <circle cx="12" cy="8" r="2" fill="#1B2E5E"/>
                    <line x1="-4" y1="13" x2="-4" y2="22" stroke="white" stroke-width="2" stroke-linecap="round"/>
                    <line x1="4" y1="13" x2="4" y2="22" stroke="white" stroke-width="2" stroke-linecap="round"/>
                </g>
            </g>
        </svg>
        <div class="wordmark"><span class="shalo">SHALO</span><span class="track">TRACK</span></div>
        <div class="tagline">ALWAYS CONNECTED</div>
        <div class="subtitle">GPS TRACKING &nbsp;|&nbsp; VEHICLE SECURITY &nbsp;|&nbsp; FLEET MANAGEMENT</div>
    </div>

    <div class="header">
        <h1>ShaloTrack — GPS Tracking Report</h1>
        <div class="meta">
            Generated on {{ now()->format('d M Y, h:i A') }}
            @if($fromDate || $toDate)
                &nbsp;|&nbsp; Range: {{ $fromDate ?? 'Start' }} to {{ $toDate ?? 'Now' }}
            @endif
            &nbsp;|&nbsp; {{ $historyData->count() }} points
        </div>
    </div>

    @if($vehicle)
        <table class="summary">
            <tr>
                <td><div class="label">Vehicle</div>{{ $vehicle['vehicleNumber'] ?? '-' }}</td>
                <td><div class="label">Make / Model</div>{{ $vehicle['make'] ?? '' }} {{ $vehicle['model'] ?? '' }}</td>
                <td><div class="label">Customer</div>{{ $vehicle['customerName'] ?? '-' }}</td>
                <td><div class="label">GPS Device (IMEI)</div>{{ $vehicle['imei'] ?? 'None' }}</td>
            </tr>
        </table>
    @endif

    <table class="data">
        <thead>
            <tr>
                <th style="width: 22%;">Date & Time</th>
                <th style="width: 19%;">Latitude</th>
                <th style="width: 19%;">Longitude</th>
                <th style="width: 15%;">Speed (km/h)</th>
                <th style="width: 12%;">Heading</th>
                <th style="width: 13%;">Satellites</th>
            </tr>
        </thead>
        <tbody>
            @forelse($historyData as $point)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($point['eventTime'])->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $point['latitude'] }}</td>
                    <td>{{ $point['longitude'] }}</td>
                    <td>{{ $point['speed'] }}</td>
                    <td>{{ $point['heading'] }}&deg;</td>
                    <td>{{ $point['satellites'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align: center; color: #999;">No tracking data found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">ShaloTrack Admin Portal — Auto-generated report</div>

</body>
</html>