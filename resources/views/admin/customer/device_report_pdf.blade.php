<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 20px; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 9px; color: #333; margin: 0; padding: 0; }
        
        .watermark {
            position: fixed; top: 25%; left: 10%; width: 80%;
            text-align: center; opacity: 0.08; z-index: -1000;
        }
        .watermark img { width: 450px; height: auto; }
        .watermark-text { font-size: 48px; font-weight: bold; color: #1a365d; letter-spacing: 4px; }
        
        .header { border-bottom: 2px solid #1a365d; padding-bottom: 5px; margin-bottom: 12px; }
        .header table { width: 100%; }
        .title { font-size: 15px; font-weight: bold; color: #1a365d; text-transform: uppercase; }
        .date { text-align: right; font-size: 8.5px; color: #666; }
        
        .section-header {
            font-size: 11px; font-weight: bold; margin-top: 15px; margin-bottom: 5px;
            padding: 4px 8px; border-radius: 4px;
        }
        .bg-active { background-color: #dcfce7; color: #15803d; }
        .bg-expired { background-color: #fef3c7; color: #b45309; }
        .bg-inactive { background-color: #fee2e2; color: #b91c1c; }

        .report-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .report-table th {
            background-color: #f8fafc; color: #1e293b; font-weight: bold;
            text-align: left; padding: 5px; border-bottom: 1px solid #cbd5e1;
            text-transform: uppercase; font-size: 8px;
        }
        .report-table td { padding: 5px; border-bottom: 1px solid #e2e8f0; font-size: 8.5px; }
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

    <!-- Header Section -->
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

    <!-- 1. ACTIVE DEVICES TABLE -->
    <div class="section-header bg-active">1. Active Devices (Total: {{ $activeDevices->count() }})</div>
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th>Customer ID</th>
                <th>Customer Name</th>
                <th>Vehicle Number</th>
                <th>Model</th>
                <th>GPS Device</th>
                <th>Vehicle ID</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($activeDevices as $index => $d)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $d->customer_id ?? 'N/A' }}</td>
                    <td><strong>{{ $d->customer_name ?? 'N/A' }}</strong></td>
                    <td>{{ $d->vehicle_number ?? 'N/A' }}</td>
                    <td>{{ $d->model ?? 'N/A' }}</td>
                    <td>{{ $d->has_gps_device ? 'Yes' : 'No' }}</td>
                    <td>{{ $d->vehicle_id ?? 'N/A' }}</td>
                    <td style="color: #16a34a; font-weight: bold;">{{ $d->status ?? 'Activated' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align: center; color: #94a3b8;">No active devices found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <!-- 2. EXPIRED DEVICES TABLE -->
    <div class="section-header bg-expired">2. Expired-Subscription-Devices (Total: {{ $expiredDevices->count() }})</div>
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th>Customer ID</th>
                <th>Customer Name</th>
                <th>Vehicle Number</th>
                <th>Model</th>
                <th>GPS Device</th>
                <th>Vehicle ID</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($expiredDevices as $index => $e)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $e->customer_id ?? 'N/A' }}</td>
                    <td><strong>{{ $e->customer_name ?? 'N/A' }}</strong></td>
                    <td>{{ $e->vehicle_number ?? 'N/A' }}</td>
                    <td>{{ $e->model ?? 'N/A' }}</td>
                    <td>{{ $e->has_gps_device ? 'Yes' : 'No' }}</td>
                    <td>{{ $e->vehicle_id ?? 'N/A' }}</td>
                    <td style="color: #d97706; font-weight: bold;">{{ $e->status ?? 'Expired' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align: center; color: #94a3b8;">No expired devices found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <!-- 3. INACTIVE DEVICES TABLE -->
    <div class="section-header bg-inactive">3. Inactive Devices (Total: {{ $inactiveDevices->count() }})</div>
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th>Customer ID</th>
                <th>Customer Name</th>
                <th>Vehicle Number</th>
                <th>Model</th>
                <th>GPS Device</th>
                <th>Vehicle ID</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($inactiveDevices as $index => $v)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $v->customer_id ?? 'N/A' }}</td>
                    <td><strong>{{ $v->customer_name ?? 'N/A' }}</strong></td>
                    <td>{{ $v->vehicle_number ?? 'N/A' }}</td>
                    <td>{{ $v->model ?? 'N/A' }}</td>
                    <td>{{ $v->has_gps_device ? 'Yes' : 'No' }}</td>
                    <td>{{ $v->vehicle_id ?? 'N/A' }}</td>
                    <td style="color: #dc2626; font-weight: bold;">Not Activated</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align: center; color: #94a3b8;">No inactive devices found.</td></tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>