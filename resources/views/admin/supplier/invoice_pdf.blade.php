<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->invoice_number }}</title>

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #1f2937;
            margin: 30px;
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

        /* Watermark: same icon + wordmark + tagline used across every other
           PDF report (activated_devices_pdf, gps_trackings_pdf, etc.).
           position:fixed makes dompdf repeat it on every page; z-index -1 +
           low opacity keeps it behind content, not on top of it. Table rows
           below must not use opaque backgrounds or they'll block it wherever
           they overlap — same reasoning as those other reports. */
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

        .title {
            font-size: 18px;
            font-weight: bold;
            margin-top: 5px;
        }

        .invoice-info {
            width: 100%;
            margin-bottom: 25px;
        }

        .invoice-info td {
            vertical-align: top;
            padding: 4px;
        }

        .label {
            font-weight: bold;
            color: #6b7280;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        table.items th {
            background: #0b1f4d;
            color: white;
            padding: 9px 6px;
            font-size: 10px;
        }

        table.items td {
            border-bottom: 1px solid #ddd;
            padding: 9px 6px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .total-box {
            margin-top: 25px;
            text-align: right;
        }

        .grand-total {
            font-size: 18px;
            font-weight: bold;
            color: #0b1f4d;
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
        <div class="title">PURCHASE ORDER / SUPPLIER INVOICE</div>
    </div>

    {{-- INVOICE + SUPPLIER DETAILS --}}
    <table class="invoice-info">
        <tr>
            <td width="50%">
                <span class="label">Invoice Number:</span><br>
                {{ $invoice->invoice_number }}
            </td>

            <td width="50%">
                <span class="label">Invoice Date:</span><br>
                {{ $invoice->invoice_date
                    ? $invoice->invoice_date->format('d M Y')
                    : '-' }}
            </td>
        </tr>

        <tr>
            <td>
                <span class="label">Supplier:</span><br>
                {{ $invoice->supplier->name ?? '-' }}
            </td>

            <td>
                <span class="label">Phone:</span><br>
                {{ $invoice->supplier->phone_number ?? '-' }}
            </td>
        </tr>

        <tr>
            <td>
                <span class="label">Email:</span><br>
                {{ $invoice->supplier->email ?? '-' }}
            </td>

            <td>
                <span class="label">Tax / VAT Reg. No:</span><br>
                {{ $invoice->supplier->gstin_number ?? '-' }}
            </td>
        </tr>

        <tr>
            <td colspan="2">
                <span class="label">Address:</span><br>
                {{ $invoice->supplier->address ?? '-' }}
            </td>
        </tr>
    </table>


    {{-- ITEMS --}}
    <table class="items">

        <thead>
            <tr>
                <th>SR.</th>
                <th>TYPE</th>
                <th>PRODUCT</th>
                <th>QTY</th>
                <th>UNIT PRICE</th>
                <th>DISC %</th>
                <th>FACE VALUE</th>
                <th>NET AMOUNT</th>
            </tr>
        </thead>

        <tbody>

            @forelse($invoice->items as $index => $item)

                <tr>

                    <td class="text-center">
                        {{ $index + 1 }}
                    </td>

                    <td>
                        {{ $item->type ?? '-' }}
                    </td>

                    <td>
                        {{ $item->product->product_name ?? '-' }}
                    </td>

                    <td class="text-center">
                        {{ $item->order_qty }}
                    </td>

                    <td class="text-right">
                        {{ number_format($item->unit_price, 2) }}
                    </td>

                    <td class="text-center">
                        {{ number_format($item->discount ?? 0, 2) }}
                    </td>

                    <td class="text-right">
                        {{ number_format($item->face_value ?? 0, 2) }}
                    </td>

                    <td class="text-right">
                        <strong>
                            {{ number_format($item->net_amount, 2) }}
                        </strong>
                    </td>

                </tr>

            @empty

                <tr>
                    <td colspan="8" class="text-center">
                        No invoice items found.
                    </td>
                </tr>

            @endforelse

        </tbody>

    </table>


    {{-- GRAND TOTAL --}}
    <div class="total-box">

        <span class="label">
            GRAND TOTAL
        </span>

        <br>

        <span class="grand-total">
            LKR {{ number_format($invoice->grand_total, 2) }}
        </span>

    </div>


    {{-- FOOTER --}}
    <div class="footer">
        Generated by ShaloTrack Admin Portal
        <br>
        {{ now()->format('d M Y h:i A') }}
    </div>

</body>
</html>