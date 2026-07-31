<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $statusCode }} — ShaloTrack Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #ffffff;
            border-radius: 16px;
            max-width: 480px;
            width: 100%;
            padding: 40px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .code {
            font-size: 56px;
            font-weight: 900;
            color: #1B2E5E;
            line-height: 1;
        }
        .icon-bar {
            width: 48px;
            height: 4px;
            background: #F07A1A;
            border-radius: 2px;
            margin: 16px auto 20px;
        }
        h1 {
            font-size: 20px;
            color: #1B2E5E;
            margin-bottom: 10px;
        }
        p.desc {
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .reference-box {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 24px;
        }
        .reference-box .label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #94a3b8;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .reference-box .value {
            font-family: 'Courier New', monospace;
            font-size: 15px;
            color: #1B2E5E;
            font-weight: 700;
            letter-spacing: 1px;
            word-break: break-all;
        }
        .actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .btn {
            display: inline-block;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: opacity 0.15s;
        }
        .btn:hover { opacity: 0.9; }
        .btn-primary { background: #1B2E5E; color: white; }
        .btn-secondary { background: #f1f5f9; color: #1B2E5E; }
        .support-note {
            margin-top: 20px;
            font-size: 12px;
            color: #94a3b8;
        }
        .support-note a { color: #F07A1A; text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
    <div class="card">
        <div class="code">{{ $statusCode }}</div>
        <div class="icon-bar"></div>
        <h1>{{ $technicalCategory ?? $title }}</h1>
        <p class="desc">{{ $technicalMessage ?? $message }}</p>

        @isset($technicalFile)
            <p style="font-family: monospace; font-size: 11px; color: #94a3b8; margin-bottom: 20px; word-break: break-all;">
                {{ $technicalFile }}
            </p>
        @endisset

        <div class="reference-box">
            <div class="label">Reference ID — quote this if you call support</div>
            <div class="value">{{ $referenceId }}</div>
        </div>

        <div class="actions">
            <a href="{{ url('/admin/dashboard') }}" class="btn btn-primary">Back to dashboard</a>
            <a href="javascript:history.back()" class="btn btn-secondary">Go back</a>
        </div>

        <p class="support-note">
            Still stuck? Call us and give the reference ID above —
            <a href="tel:+94000000000">+94 00 000 0000</a>
        </p>
    </div>
</body>
</html>