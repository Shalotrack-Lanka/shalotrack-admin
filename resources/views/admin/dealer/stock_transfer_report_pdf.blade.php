<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 20px; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 9px; color: #333; margin: 0; padding: 0; }
        
        /* Watermark Styles */
        .watermark {
            position: fixed; top: 25%; left: 10%; width: 80%;
            text-align: center; opacity: 0.08; z-index: -1000;
        }
        .watermark img { width: 450px; height: auto; }
        .watermark-text { font-size: 48px; font-weight: bold; color: #1a365d; letter-spacing: 4px; }
        
        /* Header Section */
        .header { border-bottom: 2px solid #1a365d; padding-bottom: 5px; margin-bottom: 12px; }
        .header table { width: 100%; }
        .title { font-size: 15px; font-weight: bold; color: #1a365d; text-transform: uppercase; }
        .date { text-align: right; font-size: 8.5px; color: #666; }
        
        /* Section Title Headers */
        .section-title {
            font-size: 11px; font-weight: bold; margin-top: 15px; margin-bottom: 6px;
            padding: 5px 8px; border-radius: 4px; background-color: #f1f5f9;
            color: #0f172a; border-left: 4px solid #17a2b8;
        }

        /* Table Styles */
        .report-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .report-table th {
            background-color: #f8fafc; color: #1e293b; font-weight: bold;
            text-align: left; padding: 5px; border-bottom: 1px solid #cbd5e1;
            text-transform: uppercase; font-size: 8px;
        }
        .report-table td { padding: 5px; border-bottom: 1px solid #e2e8f0; font-size: 8.5px; }
        .badge {
            padding: 2px 5px; border-radius: 3px; font-weight: bold; font-size: 8px;
            background-color: #eff6ff; color: #1d4ed8; display: inline-block;
        }
    </style>
</head>
<body>

    <!-- Watermark Logo -->
    <div class="watermark">
        @if($logoBase64)
            <img src="{{ $logoBase64 }}" alt="Watermark Logo">
        @else
            <div class="watermark-text">SHALOTRACK</div>
            <div style="font-size: 14px; letter-spacing: 6px; color: #475569; margin-top: 5px;">ALWAYS CONNECTED</div>
        @endif
    </div>

    <!-- Header -->
    <div class="header">
        <table>
            <tr>
                <td>
                    <div class="title">{{ $title }}</div>
                    <div style="color: #64748b; font-size: 8.5px; margin-top: 2px;">ShaloTrack Admin Portal</div>
                </td>
                <td class="date">
                    Generated Date: {{ date('Y-m-d H:i A') }}
                </td>
            </tr>
        </table>
    </div>

    <!-- TABLE 1: TRANSFERRED DEVICE HISTORY -->
    <div class="section-title">1. Transferred Device History (Total: {{ $transfers->count() }})</div>
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th>Date & Time</th>
                <th>Dealer</th>
                <th>Device Category / Type</th>
                <th style="text-align: center;">No. of Devices</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transfers as $index => $transfer)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $transfer->created_at->format('Y-m-d h:i A') }}</td>
                    <td><strong>{{ $transfer->dealer->full_name ?? '-' }}</strong></td>
                    <td>{{ $transfer->device_category }}</td>
                    <td style="text-align: center; font-weight: bold; color: #1d4ed8;">{{ $transfer->quantity }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align: center; color: #94a3b8;">No stock transfers found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <!-- TABLE 2: TRANSFERRED IMEI / DEVICES -->
    <div class="section-title">2. Transferred IMEI / Devices (Total: {{ $allocatedDevices->count() }})</div>
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th>IMEI Number</th>
                <th>SIM Number</th>
                <th>Device Type</th>
                <th>Dealer Name</th>
                <th>Status</th>
                <th>Allocation Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($allocatedDevices as $index => $device)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td><strong>{{ $device->imei_number }}</strong></td>
                    <td>{{ $device->sim_number ?? '-' }}</td>
                    <td>
                        {{ $device->deviceType->device_category ?? $device->device_category ?? '-' }}
                        @if($device->deviceType?->model)
                            <span style="color: #64748b;">— {{ $device->deviceType->model }}</span>
                        @endif
                    </td>
                    <td><strong>{{ $device->dealer->full_name ?? '-' }}</strong></td>
                    <td><span class="badge">{{ $device->status }}</span></td>
                    <td>{{ $device->allocated_at ? $device->allocated_at->format('d M Y, h:i A') : 'Not recorded' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align: center; color: #94a3b8;">No devices have been transferred to a dealer yet.</td></tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>