<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: 'Helvetica', Arial, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 10px; }

        /* --- Beautiful SVG Watermark Styles --- */
        .watermark {
            position: fixed; top: 150px; left: 50%; width: 440px; margin-left: -220px;
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

        /* --- Layout & Tables --- */
        .header { border-bottom: 2px solid #17a2b8; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { font-size: 16px; margin: 0 0 4px 0; color: #17a2b8; text-transform: uppercase; }
        .header .meta { font-size: 10px; color: #777; }
        
        .section-title { 
            font-size: 12px; font-weight: bold; background-color: #f5f5f5; 
            padding: 6px 8px; margin-top: 20px; margin-bottom: 10px; border-left: 4px solid #1B2E5E; 
        }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        thead th {
            background-color: #f9fafb; text-align: left; padding: 6px 8px;
            font-size: 10px; text-transform: uppercase; border-bottom: 1px solid #ccc;
        }
        tbody td { padding: 6px 8px; border-bottom: 1px solid #eee; }
        .text-center { text-align: center; }
        
        .footer { 
            position: fixed; bottom: -15px; left: 0; right: 0; 
            font-size: 9px; color: #999; text-align: right; 
            border-top: 1px solid #ddd; padding-top: 5px; 
        }
    </style>
</head>
<body>

    <!-- Beautiful SVG Watermark -->
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

    <!-- Header -->
    <div class="header">
        <h1>{{ $title }}</h1>
        <div class="meta">
            Generated on {{ \Carbon\Carbon::now()->format('d M Y, h:i A') }} &nbsp;|&nbsp; Total Transfers: {{ $transfers->count() }}
        </div>
    </div>

    <!-- Bulk Transfers Section -->
    <div class="section-title">Bulk Transfer History</div>
    <table>
        <thead>
            <tr>
                <th style="width: 25%;">Date & Time</th>
                <th style="width: 25%;">Dealer Name</th>
                <th style="width: 30%;">Device Category / Type</th>
                <th style="width: 20%;" class="text-center">Quantity</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transfers as $transfer)
                <tr>
                    <td>{{ $transfer->created_at->format('Y-m-d h:i A') }}</td>
                    <td><strong>{{ $transfer->dealer->full_name ?? '-' }}</strong></td>
                    <td>{{ $transfer->device_category }}</td>
                    <td class="text-center"><strong>{{ $transfer->quantity }}</strong></td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center" style="color: #999;">No stock transfers found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Individual Allocated Devices Section -->
    <div class="section-title">Transferred IMEI / Devices (Individual Records)</div>
    <table>
        <thead>
            <tr>
                <th style="width: 20%;">IMEI Number</th>
                <th style="width: 15%;">SIM Number</th>
                <th style="width: 20%;">Device Type</th>
                <th style="width: 15%;">Dealer Name</th>
                <th style="width: 15%;">Status</th>
                <th style="width: 15%;">Allocation Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($allocatedDevices as $device)
                <tr>
                    <td>{{ $device->imei_number }}</td>
                    <td>{{ $device->sim_number ?? '-' }}</td>
                    <td>
                        {{ $device->deviceType->device_category ?? $device->device_category ?? '-' }}
                        @if($device->deviceType?->model)
                            {{ $device->deviceType->model }}
                        @endif
                    </td>
                    <td><strong>{{ $device->dealer->full_name ?? '-' }}</strong></td>
                    <td>{{ $device->status }}</td>
                    <td>{{ $device->allocated_at ? $device->allocated_at->format('d M Y, h:i A') : '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center" style="color: #999;">No devices have been transferred to a dealer yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">ShaloTrack Admin Portal — Auto-generated Report</div>

</body>
</html>