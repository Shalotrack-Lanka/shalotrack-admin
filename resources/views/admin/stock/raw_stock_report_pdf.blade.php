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
            position: fixed; top: 30%; left: 10%; width: 80%;
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
        .text-right { text-align: right; }
    </style>
</head>
<body>

    <!-- Watermark Section -->
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

    <!-- Data Tables -->
    @if($type === 'stock')
        <!-- COMPANY AVAILABLE STOCK TABLE -->
        <table class="report-table">
            <thead>
                <tr>
                    <th style="width: 10%;">Stock ID</th>
                    <th>Device Category / Type</th>
                    <th class="text-right">Company Available Stock</th>
                    <th>Last Edited Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse($data as $stock)
                    <tr>
                        <td>#{{ $stock->id }}</td>
                        <td><strong>{{ $stock->device_category_type }}</strong></td>
                        <td class="text-right" style="color: #16a34a; font-weight: bold;">{{ $stock->company_available_stock }}</td>
                        <td>{{ optional($stock->updated_at)->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align: center; color: #94a3b8; padding: 15px;">No stock recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    @else
        <!-- STOCK TRANSFER LEDGER TABLE -->
        <table class="report-table">
            <thead>
                <tr>
                    <th style="width: 3%;">#</th>
                    <th>Device Category / Type</th>
                    <th>Supplier ID</th>
                    <th>Supplier</th>
                    <th class="text-right">Stock In</th>
                    <th>Description</th>
                    <th>Stocked-In Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse($data as $index => $entry)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td><strong>{{ $entry->device_category_type }}</strong></td>
                        <td>#{{ $entry->supplier_id }}</td>
                        <td>{{ $entry->supplier }}</td>
                        <td class="text-right" style="font-weight: bold;">{{ $entry->stock_in }}</td>
                        <td>{{ $entry->description ?? '-' }}</td>
                        <td>{{ optional($entry->stocked_in_date)->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align: center; color: #94a3b8; padding: 15px;">No stock transfer ledger records yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

</body>
</html>