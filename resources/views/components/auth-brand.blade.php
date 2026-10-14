{{-- ShaloTrack logo + wordmark shown above the sign-in, forgot-password and change-password forms. --}}
    <div class="flex flex-col items-center mb-6">
        <picture>
            <source srcset="{{ asset('images/shalotrack-logo.webp') }}" type="image/webp">
            <img src="{{ asset('images/shalotrack-logo.png') }}"
                 alt="ShaloTrack logo"
                 width="160" height="160"
                 fetchpriority="high"
                 decoding="async"
                 style="width:160px;height:160px;user-select:none;">
        </picture>

        <!-- Wordmark -->
        <div class="mt-1">
            <span style="font-family: Arial Black, sans-serif; font-weight: 900; font-size: 1.6rem; color: #1B2E5E; letter-spacing: 2px;">SHALO</span><span style="font-family: Arial Black, sans-serif; font-weight: 900; font-size: 1.6rem; color: #F07A1A; letter-spacing: 2px;">TRACK</span>
        </div>
        <div class="flex items-center gap-2 mt-1">
            <div style="height:1px; width:50px; background:#1B2E5E;"></div>
            <span style="font-size: 0.6rem; letter-spacing: 3px; color: #1B2E5E;">ALWAYS CONNECTED</span>
            <div style="height:1px; width:50px; background:#F07A1A;"></div>
        </div>
        <p style="font-size: 0.55rem; letter-spacing: 1.5px; color: #888; margin-top: 4px;">GPS TRACKING &nbsp;|&nbsp; VEHICLE SECURITY &nbsp;|&nbsp; FLEET MANAGEMENT</p>
    </div>