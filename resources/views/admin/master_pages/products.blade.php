<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        /* ── LIGHT MODE (default) ── *
        body                          { background:#f1f5f9; color:#1e293b; }
        .main-content                 { background:#f1f5f9; }
        .header-bar                   { background:#ffffff; border-bottom:1px solid #e2e8f0; }
        .card                         { background:#ffffff; border:1px solid #e2e8f0; color:#1e293b; }
        .card h3                      { color:#1e293b; }
        .table-head tr                { background:#f1f5f9; }
        .table-head th                { color:#1e293b; border-bottom:1px solid #e2e8f0; }
        .table-body td                { color:#1e293b; border-bottom:1px solid #e2e8f0; }
        .table-body tr:hover          { background:#f8fafc; }
        .username-text                { color:#374151; }
        .dropdown-menu                { background:#ffffff; border:1px solid #e2e8f0; }
        .dropdown-item                { color:#374151; }
        .dropdown-item:hover          { background:#f1f5f9; }
        .dropdown-info p              { color:#1e293b; }
        .dropdown-info span           { color:#6b7280; }
        .page-title                   { color:#1e293b; }
        .toggle-btn                   { background:#f1f5f9; border:1px solid #e2e8f0; }
        .chart-card                   { background:#ffffff; border:1px solid #e2e8f0; }
        .chart-card h3                { color:#1e293b; }

        /* ── DARK MODE ── *
        .dark body                    { background:#0f172a; color:#f1f5f9; }
        .dark .main-content           { background:#0f172a; }
        .dark .header-bar             { background:#1e293b; border-bottom:1px solid #334155; }
        .dark .card                   { background:#1e293b; border:1px solid #334155; color:#f1f5f9; }
        .dark .card h3                { color:#f1f5f9; }
        .dark .table-head tr          { background:#0f172a; }
        .dark .table-head th          { color:#f1f5f9; border-bottom:1px solid #334155; }
        .dark .table-body td          { color:#f1f5f9; border-bottom:1px solid #334155; }
        .dark .table-body tr:hover    { background:#334155; }
        .dark .username-text          { color:#f1f5f9; }
        .dark .dropdown-menu          { background:#1e293b; border:1px solid #334155; }
        .dark .dropdown-item          { color:#f1f5f9; }
        .dark .dropdown-item:hover    { background:#334155; }
        .dark .dropdown-info p        { color:#f1f5f9; }
        .dark .dropdown-info span     { color:#94a3b8; }
        .dark .page-title             { color:#f1f5f9; }
        .dark .toggle-btn             { background:#334155; border:1px solid #475569; }
        .dark .chart-card             { background:#1e293b; border:none; }
        .dark .chart-card h3          { color:#ffffff; } */
    </style>
</head>
<body x-data="{ sidebarOpen: false }"> <!-- Added Alpine state for Mobile Menu Toggle -->

<div class="flex h-screen overflow-hidden"> <!-- Prevent double scrollbars -->

    @include('partials.sidebars.admin')

    @include('partials.header')

        <main class="p-4 md:p-6 flex-1">
            @yield('content')

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start w-full">
                    
                    <div class="lg:col-span-5 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">Add Product Type</div>
                        <div class="p-5 space-y-4 text-xs font-semibold text-gray-700">
                            <form method="POST" action="#">
                                @csrf
                                <div class="space-y-3">
                                    <div>
                                        <label class="block mb-1">Type</label>
                                        <input type="text" name="type" class="w-full rounded-lg border-gray-300 h-9">
                                    </div>
                                    <label class="inline-flex items-center gap-2 cursor-pointer pt-1">
                                        <input type="checkbox" name="upload_serial_required" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"> Upload Serial No Required: Yes
                                    </label>
                                    <div class="flex gap-2 pt-2">
                                        <button type="reset" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded font-bold shadow-sm">Reset</button>
                                        <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white px-4 py-2 rounded font-bold shadow-sm">Save Details</button>
                                    </div>
                                </div>
                            </form>

                            <div class="border border-gray-200 rounded-lg overflow-hidden mt-6 max-h-60 overflow-y-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead class="bg-gray-50 border-b border-gray-200">
                                        <tr>
                                            <th class="p-2.5">Product Type</th>
                                            <th class="p-2.5">Upload Serial Required</th>
                                            <th class="p-2.5 text-center w-24">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 bg-white">
                                        <tr><td class="p-2.5">12 Pager Brochure</td><td class="p-2.5">No</td><td class="p-2.5 text-center flex justify-center gap-1.5">
                                            <button class="text-gray-500 hover:text-blue-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg></button>
                                            <button class="text-gray-500 hover:text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                                        </td></tr>
                                        <tr><td class="p-2.5">Device</td><td class="p-2.5">Yes</td><td class="p-2.5 text-center flex justify-center gap-1.5"><button class="text-gray-500 hover:text-blue-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg></button><button class="text-gray-500 hover:text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="lg:col-span-7 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">Add Products</div>
                        <div class="p-5 text-xs font-semibold text-gray-700 space-y-4">
                            <form method="POST" action="#" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                @csrf
                                <div><label class="block mb-1">Product Type</label>
                                <select name="product_type" class="w-full rounded-lg border-gray-300 h-9 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10"">
                                    <option value="" selected >--Select--</option>
                                    <option value="new_company_brochure">New Company Brochure</option>
                                    <option value="news_paper_insert_day_1_20k">news paper insert day 1 20k</option>
                                    <option value="news_paper_insert_day_2_20k">news paper insert day 2 20k</option>
                                    <option value="news_paper_insert_day_3_20k">news paper insert day 3 20k</option>
                                    <option value="photo_frame">photo frame</option>
                                    <option value="port_in_device">Port In Device</option>
                                    <option value="pragati_maidan_backdrop">pragati maidan Backdrop+fascia+2table front</option>
                                    <option value="price_sticker_activation_box">price sticker activation box</option>
                                    <option value="price_sticker_bottom">price sticker bottom</option>
                                    <option value="printing_karnal">Printing (Karnal)</option>
                                    <option value="printing_new_delhi">Printing (New Delhi)</option>
                                    <option value="pvc_colour_tape_grey">PVC Colour tape(Grey)</option>
                                    <option value="pvc_id_card">PVC I D Card</option>
                                    <option value="rewards_points">Rewards Points</option>
                                    <option value="sandee">Sandee</option>
                                    <option value="sim">SIM</option>
                                    <option value="single_sheet_leaflet">single sheet leaflet</option>
                                    <option value="sl_1">SL 1</option>
                                    <option value="t_shirts">T-Shirts</option>
                                    <option value="visiting_card">visiting card</option>
                                    <option value="12_pager_brochure">12 Pager Brochure</option>
                                    <option value="a2_poster">A2 Poster</option>
                                    <option value="a3_posters">A3 Posters</option>
                                    <option value="accessories">Accessories</option>
                                    <option value="activation_box_eva_shrink_packaging">activation box with eva+ shrink+ packaging</option>
                                    <option value="canopy">canopy</option>
                                    <option value="device">Device</option>
                                    <option value="device_box">Device Box</option>
                                    <option value="device_box_shrink">device box shrink</option>
                                    <option value="device_sticker_mega">DEVICE STICKER MEGA</option>
                                    <option value="fitters_required_item">Fitter(s) Required Item</option>
                                    <option value="frame">Frame</option>
                                    <option value="letter_head_extra">Letter Head Extra</option>
                                    <option value="letter_head_normal">Letter Head Normal</option>
                                    <option value="lt_branding_kit">LT Branding Kit</option>
                                    <option value="mega_eva_sheet">MEGA EVA SHEET</option>
                                    <option value="new_a5_promotional_leaflet">New A5 promotional leaflet</option>
                                    <option value="new_company_brochure">New Company Brochure</option>
                                    <option value="news_paper_insert_day_1_20k">news paper insert day 1 20k</option>
                                </select>
                            </div>

                                <div>
                                    <label class="block mb-1">Product Name</label>
                                    <input type="text" name="product_name" class="w-full rounded-lg border-gray-300 h-9">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block mb-1">Product Description</label>
                                    <textarea name="product_description" rows="2" class="w-full rounded-lg border-gray-300"></textarea>
                                </div>
                                <div>
                                    <label class="block mb-1">Product Price</label>
                                    <input type="text" name="product_price" class="w-full rounded-lg border-gray-300 h-9">
                                </div>
                                <div>
                                    <label class="block mb-1">Device Price</label>
                                    <input type="text" name="device_price" class="w-full rounded-lg border-gray-300 h-9">
                                </div>
                                <div>
                                    <label class="block mb-1">Service Price</label>
                                    <input type="text" name="service_price" class="w-full rounded-lg border-gray-300 h-9">
                                </div>
                                <div>
                                    <label class="block mb-1">Product Image</label>
                                    <input type="file" name="product_image" class="w-full border border-gray-300 rounded-lg p-1.5 h-9 bg-white text-[11px]">
                                </div>
                                <div>
                                    <label class="block mb-1">Trade Type</label>
                                    <select name="trade_type" class="w-full rounded-lg border-gray-300 h-9 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                        <option value="" selected disabled >Select</option>
                                        <option value="none">None</option>
                                        <option value="online" >Online</option>
                                        <option value="offline">Offline</option>
                                        <option value="both">Both</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block mb-1">Minimum Alert Qty</label>
                                    <input type="text" name="min_alert_qty" class="w-full rounded-lg border-gray-300 h-9">
                                </div>
                                <div class="md:col-span-2 flex items-center gap-2">
                                    <input type="checkbox" name="testing_required" value="1" class="rounded border-gray-300 text-blue-600 w-4 h-4">
                                    <label>Testing required</label>
                                </div>
                                <div class="md:col-span-2 flex gap-2 pt-2">
                                    <button type="reset" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded font-bold shadow-sm">Reset</button>
                                    <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white px-4 py-2 rounded font-bold shadow-sm">Add Product</button>
                                </div>
                            </form>
                        </div>
                    </div>

                </div>
            </div>
        </main>

<!-- Dark Mode Script --
<script>
const html = document.documentElement;
const themeToggle = document.getElementById('themeToggle');
const moonIcon = document.getElementById('moonIcon');
const sunIcon = document.getElementById('sunIcon');

function updateIcons() {
    if (html.classList.contains('dark')) {
        moonIcon.classList.add('hidden');
        sunIcon.classList.remove('hidden');
    } else {
        moonIcon.classList.remove('hidden');
        sunIcon.classList.add('hidden');
    }
}

// Load saved theme
if (localStorage.getItem('theme') === 'dark') {
    html.classList.add('dark');
}

updateIcons();

// Toggle theme
themeToggle.addEventListener('click', () => {
    html.classList.toggle('dark');

    if (html.classList.contains('dark')) {
        localStorage.setItem('theme', 'dark');
    } else {
        localStorage.setItem('theme', 'light');
    }

    updateIcons();
});
</script> -->

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>