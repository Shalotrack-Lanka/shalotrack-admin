<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page {
            margin: 30px;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11px;
            color: #333;
            margin: 0;
            padding: 0;
        }
        /* Watermark Styles */
        .watermark {
            position: fixed;
            top: 35%;
            left: 10%;
            width: 80%;
            text-align: center;
            opacity: 0.08;
            z-index: -1000;
        }
        .watermark img {
            width: 450px;
            height: auto;
        }
        .watermark-text {
            font-size: 48px;
            font-weight: bold;
            color: #1a365d;
            letter-spacing: 4px;
        }
        /* Header & Table Styles */
        .header {
            border-b: 2px solid #1a365d;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header table {
            width: 100%;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
            color: #1a365d;
            text-transform: uppercase;
        }
        .date {
            text-align: right;
            font-size: 10px;
            color: #666;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .report-table th {
            background-color: #f2f4f8;
            color: #1a365d;
            font-weight: bold;
            text-align: left;
            padding: 8px;
            border-bottom: 1px solid #cbd5e1;
            text-transform: uppercase;
            font-size: 9px;
        }
        .report-table td {
            padding: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .status-badge {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9px;
        }
        .verified { color: #16a34a; }
        .not_verified { color: #dc2626; }
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
                    <div style="color: #64748b; font-size: 10px; margin-top: 3px;">ShaloTrack Admin Portal</div>
                </td>
                <td class="date">
                    Generated Date: {{ date('Y-m-d H:i A') }}
                </td>
            </tr>
        </table>
    </div>

    <!-- Data Table Section -->
    <table class="report-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Full Name</th>
                <th>Phone Number</th>
                <th>Email Address</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($customers as $index => $customer)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td><strong>{{ $customer->full_name ?? 'N/A' }}</strong></td>
                    <td>{{ $customer->phone_number ?? 'N/A' }}</td>
                    <td>{{ $customer->email ?? 'N/A' }}</td>
                    <td>
                        <span class="status-badge {{ $customer->cus_status === 'verified' ? 'verified' : 'not_verified' }}">
                            {{ $customer->cus_status === 'verified' ? 'Verified' : 'Not Verified' }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; color: #94a3b8; padding: 20px;">No customers found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>