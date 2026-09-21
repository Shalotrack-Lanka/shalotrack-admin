<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-100 font-sans antialiased">

<div class="flex h-screen overflow-hidden" x-data="{ sidebarOpen: false }"> @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col h-screen overflow-y-auto">

        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 w-full max-w-full" x-data="{ 
            activeTab: 'feedback', {{-- Feedback ටැබ් එක මුලින්ම පෙනෙන්න සකසා ඇත --}}
            statusFilter: 'Open',
            complaintType: 'All',
            complaintSource: 'All',
            fitterRequest: '',
            feedbackType: 'All',
            ratingFilter: 'All'
        }">
            

            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                
                <div x-show="activeTab === 'view_complaints'" x-transition class="p-6 space-y-6 w-full">
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="#" class="bg-[#17a2b8] hover:bg-[#138496] text-white text-xs font-bold py-2.5 px-4 rounded-lg shadow-sm transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            Add Complaint/Enquiry
                        </a>
                        <button class="bg-green-600 hover:bg-green-700 text-white text-xs font-bold py-2.5 px-4 rounded-lg shadow-sm transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            Export to Excel
                        </button>
                    </div>

                    <form method="GET" action="#" class="bg-gray-50 border border-gray-100 p-5 rounded-xl shadow-sm w-full">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4 items-center">
                            <div class="grid grid-cols-3 items-center gap-2">
                                <label class="text-sm font-semibold text-gray-700 text-left lg:text-right pr-2">From Date</label>
                                <div class="col-span-2"><input type="date" value="2026-06-19" class="w-full rounded-lg border-gray-300 text-sm h-10"></div>
                            </div>
                            <div class="grid grid-cols-3 items-center gap-2">
                                <label class="text-sm font-semibold text-gray-700 text-left lg:text-right pr-2">To Date</label>
                                <div class="col-span-2"><input type="date" value="2026-06-26" class="w-full rounded-lg border-gray-300 text-sm h-10"></div>
                            </div>
                            <div class="grid grid-cols-3 items-center gap-2">
                                <label class="text-sm font-semibold text-gray-700 text-left lg:text-right pr-2">Status</label>
                                <div class="col-span-2">
                                    <select x-model="statusFilter" class="w-full rounded-lg border-gray-300 text-sm h-10">
                                        <option value="Open">Open</option>
                                        <option value="In Progress">In Progress</option>
                                        <option value="Closed">Closed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <div x-show="activeTab === 'feedback'" x-transition class="p-6 space-y-6 w-full">
                    
                    <div class="border-b border-gray-100 pb-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                        <h3 class="text-lg font-bold text-gray-800">Feedback Report</h3>
                        <span class="text-sm text-gray-500 font-semibold bg-gray-100 px-3 py-1 rounded-md flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            26<sup>th</sup> June 2026
                        </span>
                    </div>

                    <form method="GET" action="#" class="bg-gray-50 border border-gray-100 p-5 rounded-xl shadow-sm w-full">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4 max-w-4xl">
                            
                            <div class="grid grid-cols-3 items-center gap-2">
                                <label for="fb_from_date" class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">From</label>
                                <div class="col-span-2">
                                    <input type="date" id="fb_from_date" name="from_date" value="2026-06-19"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                </div>
                            </div>

                            <div class="grid grid-cols-3 items-center gap-2">
                                <label for="fb_to_date" class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">To</label>
                                <div class="col-span-2">
                                    <input type="date" id="fb_to_date" name="to_date" value="2026-06-26"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                </div>
                            </div>

                            <div class="grid grid-cols-3 items-center gap-2">
                                <label for="fb_type" class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Feedback Type:</label>
                                <div class="col-span-2">
                                    <select id="fb_type" name="feedback_type" x-model="feedbackType"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                        <option value="All">All</option>
                                        <option value="app_feedback">App Feedback</option>
                                        <option value="web_feedback">Web Feedback</option>
                                        <option value="fitter_feedback">Fitter Feedback</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-3 items-center gap-2">
                                <label for="fb_rating" class="text-sm font-semibold text-gray-700 text-left md:text-right pr-2">Rating</label>
                                <div class="col-span-2">
                                    <select id="fb_rating" name="rating" x-model="ratingFilter"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                        <option value="really_good">Really Good</option>
                                        <option value="happy">Happy</option>
                                        <option value="good">Good</option>
                                        <option value="not_bad">Not Bad</option>
                                        <option value="very_bad">Very Bad</option>
                                    </select>
                                </div>
                            </div>

                        </div>

                        <div class="mt-4 flex justify-start">
                            <button type="submit" 
                                class="w-full sm:w-36 bg-[#17a2b8] hover:bg-[#138496] text-white font-bold h-10 rounded-lg shadow-sm transition flex items-center justify-center gap-2 text-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                Search
                            </button>
                        </div>
                    </form>

                    <div class="border border-gray-200 rounded-xl overflow-hidden shadow-sm w-full">
                        <div class="p-12 text-center bg-gray-50">
                            <svg class="mx-auto h-12 w-12 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <p class="text-gray-600 font-semibold text-base">No Records Found</p>
                            <p class="text-xs text-gray-400 mt-1">There are no client feedback logs submitted during this specific timestamp parameter.</p>
                        </div>
                    </div>

                </div>

                <div x-show="activeTab === 'replace_request'" x-transition class="p-12 text-center text-gray-500" style="display: none;">Device configuration/replacement submission authorization panels.</div>
                <div x-show="activeTab === 'troubleshoot'" x-transition class="p-12 text-center text-gray-500" style="display: none;">Remote tracking signal inspection terminal pipeline module window.</div>
                <div x-show="activeTab === 'complaint_dashboard'" x-transition class="p-12 text-center text-gray-500" style="display: none;">Analytics overview canvas mapping open vs closed trouble tickets.</div>

            </div> 
        </main>

    </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>