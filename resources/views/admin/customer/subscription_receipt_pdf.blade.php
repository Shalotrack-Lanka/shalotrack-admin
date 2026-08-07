<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Subscription Receipt - {{ $customer->full_name }}</title>

    <style>
        body {
            font-family: 'Helvetica', Arial, sans-serif;
            font-size: 12px;
            color: #1f2937;
            margin: 30px;
        }

        .watermark {
            position: fixed;
            top: 300px;
            left: 50%;
            width: 440px;
            margin-left: -220px;
            opacity: 0.18;
            z-index: -1;
            text-align: center;
        }
        .watermark .wordmark {
            margin-top: 6px;
            font-family: Arial, Helvetica, sans-serif;
            font-weight: 900;
            font-size: 2.4rem;
            letter-spacing: 3px;
        }
        .watermark .wordmark .shalo { color: #1B2E5E; }
        .watermark .wordmark .track { color: #F07A1A; }
        .watermark .tagline {
            margin-top: 8px;
            font-size: 0.9rem;
            letter-spacing: 6px;
            color: #1B2E5E;
        }
        .watermark .subtitle {
            margin-top: 6px;
            font-size: 0.6rem;
            letter-spacing: 2px;
            color: #888;
        }

        .header {
            border-bottom: 3px solid #0b1f4d;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .company {
            font-size: 25px;
            font-weight: bold;
            color: #0b1f4d;
        }

        .title {
            font-size: 16px;
            font-weight: bold;
            margin-top: 5px;
            color: #17a2b8;
        }

        .status-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: bold;
            background-color: #e6f7ec;
            color: #1a7f43;
        }

        table.details {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }

        table.details td {
            vertical-align: top;
            padding: 8px 4px;
            border-bottom: 1px solid #eee;
        }

        .label {
            font-weight: bold;
            color: #6b7280;
            font-size: 10px;
            text-transform: uppercase;
            display: block;
            margin-bottom: 2px;
        }

        .value {
            font-size: 13px;
            color: #1f2937;
        }

        .section-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            color: #0b1f4d;
            margin-top: 25px;
            margin-bottom: 8px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 4px;
        }

        .footer {
            margin-top: 50px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #777;
            font-size: 10px;
        }
    </style>
</head>

<body>

    {{-- WATERMARK: same one used on every other generated PDF report --}}
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

    {{-- HEADER --}}
    <div class="header">
        <div class="company">ShaloTrack</div>
        <div class="title">SUBSCRIPTION CONFIRMATION / RECEIPT</div>
    </div>

    <span class="status-pill">Active Subscription</span>

    {{-- CUSTOMER DETAILS --}}
    <div class="section-title">Customer Details</div>
    <table class="details">
        <tr>
            <td width="50%">
                <span class="label">Full Name</span>
                <span class="value">{{ $customer->full_name ?? '-' }}</span>
            </td>
            <td width="50%">
                <span class="label">NIC Number</span>
                <span class="value">{{ $customer->nic_number ?? '-' }}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="label">Phone Number</span>
                <span class="value">{{ $customer->phone_number ?? '-' }}</span>
            </td>
            <td>
                <span class="label">Email Address</span>
                <span class="value">{{ $customer->email ?? '-' }}</span>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <span class="label">Address</span>
                <span class="value">{{ $customer->address ?? '-' }}</span>
            </td>
        </tr>
    </table>

    {{-- DEVICE DETAILS --}}
    <div class="section-title">Device Details</div>
    <table class="details">
        <tr>
            <td width="33%">
                <span class="label">Device Type</span>
                <span class="value">{{ $customer->device_type ?? '-' }}</span>
            </td>
            <td width="33%">
                <span class="label">IMEI Number</span>
                <span class="value">{{ $customer->imei_number ?? '-' }}</span>
            </td>
            <td width="34%">
                <span class="label">SIM Number</span>
                <span class="value">{{ $customer->sim_number ?? '-' }}</span>
            </td>
        </tr>
    </table>

    {{-- SUBSCRIPTION DETAILS --}}
    <div class="section-title">Subscription Details</div>
    <table class="details">
        <tr>
            <td width="33%">
                <span class="label">Subscription Period</span>
                <span class="value">{{ $customer->subscription_period ? ucwords(str_replace('_', ' ', $customer->subscription_period)) : '-' }}</span>
            </td>
            <td width="33%">
                <span class="label">Start Date</span>
                <span class="value">{{ $customer->subscription_start_date ? $customer->subscription_start_date->format('d M Y') : '-' }}</span>
            </td>
            <td width="34%">
                <span class="label">End Date</span>
                <span class="value">{{ $customer->subscription_end_date ? $customer->subscription_end_date->format('d M Y') : '-' }}</span>
            </td>
        </tr>
    </table>

    {{-- FOOTER --}}
    <div class="footer">
        This is a system-generated subscription confirmation and does not represent a tax invoice or payment amount.
        <br>
        Generated by ShaloTrack Admin Portal on {{ now()->format('d M Y, h:i A') }}
    </div>

</body>
</html>