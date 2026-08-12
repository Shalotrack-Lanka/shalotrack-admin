<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 25px; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10px; color: #333; margin: 0; padding: 0; }
        
        /* Watermark Styles */
        .watermark {
            position: fixed; top: 35%; left: 10%; width: 80%;
            text-align: center; opacity: 0.08; z-index: -1000;
        }
        .watermark img { width: 450px; height: auto; }
        .watermark-text { font-size: 48px; font-weight: bold; color: #1a365d; letter-spacing: 4px; }
        
        .header { border-bottom: 2px solid #1a365d; padding-bottom: 8px; margin-bottom: 15px; }
        .header table { width: 100%; }
        .title { font-size: 16px; font-weight: bold; color: #1a365d; text-transform: uppercase; }
        .date { text-align: right; font-size: 9px; color: #666; }
        
        .report-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .report-table th {
            background-color: #f2f4f8; color: #1a365d; font-weight: bold;
            text-align: left; padding: 6px; border-bottom: 1px solid #cbd5e1;
            text-transform: uppercase; font-size: 8.5px;
        }
        .report-table td { padding: 6px; border-bottom: 1px solid #e2e8f0; font-size: 9.5px; }
        
        .badge {
            background-color: #fef3c7; color: #d97706; font-weight: bold;
            padding: 2px 6px; border-radius: 4px; display: inline-block;
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

    <!-- Header Section -->
    <div class="header">
        <table>
            <tr>
                <td>
                    <div class="title">{{ $title }}</div>
                    <div style="color: #64748b; font-size: 9px; margin-top: 2px;">ShaloTrack Admin Portal</div>
                </td>
                <td class="date">
                    Generated Date: {{ date('Y-m-d H:i A') }}
                </td>
            </tr>
        </table>
    </div>

    <!-- Exact Web Table View Data -->
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th>Customer Name</th>
                <th>Contact</th>
                <th>NIC / ID</th>
                <th style="text-align: center;">Devices</th>
                <th>Dealer ID</th>
                <th>Dealer Name</th>
                <th>Date Added</th>
            </tr>
        </thead>
        <tbody>
            @forelse($customers as $index => $customer)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td><strong>{{ $customer->name ?? 'N/A' }}</strong></td>
                    <td>{{ $customer->contact ?? 'N/A' }}</td>
                    <td>{{ $customer->nic_or_id ?? 'N/A' }}</td>
                    <td style="text-align: center;">
                        <span class="badge">{{ $customer->no_of_devices ?? 0 }}</span>
                    </td>
                    <td>#{{ $customer->dealer_id ?? 'N/A' }}</td>
                    <td>{{ $customer->dealer->name  ?? $customer->dealer->full_name ?? $customer->dealer->user->name ?? $customer->dealer->user->full_name ?? 'N/A' }}</td>
                    <td>{{ $customer->created_at ? $customer->created_at->format('12 Aug 2026, h:i A') : 'N/A' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="text-align: center; color: #94a3b8; padding: 15px;">No dealer-added customers found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>