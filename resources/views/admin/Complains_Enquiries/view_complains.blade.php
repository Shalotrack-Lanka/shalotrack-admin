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
            activeTab: 'view_complaints',
            statusFilter: 'Open',
            complaintType: 'All',
            complaintSource: 'All',
            fitterRequest: ''
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
                                <label for="from_date" class="text-sm font-semibold text-gray-700 text-left lg:text-right pr-2">From Date</label>
                                <div class="col-span-2">
                                    <input type="date" id="from_date" name="from_date" value="2026-06-19"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                </div>
                            </div>

                            <div class="grid grid-cols-3 items-center gap-2">
                                <label for="to_date" class="text-sm font-semibold text-gray-700 text-left lg:text-right pr-2">To Date</label>
                                <div class="col-span-2">
                                    <input type="date" id="to_date" name="to_date" value="2026-06-26"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                </div>
                            </div>

                            <div class="grid grid-cols-3 items-center gap-2">
                                <label for="status" class="text-sm font-semibold text-gray-700 text-left lg:text-right pr-2">Status</label>
                                <div class="col-span-2">
                                    <select id="status" name="status" x-model="statusFilter"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                        <option value="Open">Open</option>
                                        <option value="In Progress">In Progress</option>
                                        <option value="Closed">Closed</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-3 items-center gap-2">
                                <label for="type" class="text-sm font-semibold text-gray-700 text-left lg:text-right pr-2">Type</label>
                                <div class="col-span-2">
                                    <select id="type" name="type" x-model="complaintType"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                        <option value="All">All</option>
                                        <option value="Complaint">Complaint</option>
                                        <option value="Enquiry">Enquiry</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-3 items-center gap-2">
                                <label for="source" class="text-sm font-semibold text-gray-700 text-left lg:text-right pr-2">Source</label>
                                <div class="col-span-2">
                                    <select id="source" name="source" x-model="complaintSource"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                        <option value="All">All</option>
                                        <option value="App">Mobile App</option>
                                        <option value="Web">Web Portal</option>
                                        <option value="Call">Hotline Call</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-3 items-center gap-2">
                                <div class="col-span-3">
                                    <input type="text" id="search_text" name="search_text" placeholder="Search description/ticket no..."
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                </div>
                            </div>

                            <div class="grid grid-cols-3 lg:grid-cols-4 items-center gap-2 lg:col-span-2">
                                <label for="fitter_visit" class="text-sm font-semibold text-gray-700 col-span-1 text-left lg:text-right pr-2 whitespace-nowrap">
                                    Device Replacement / Fitter visit Request
                                </label>
                                <div class="col-span-2 lg:col-span-3">
                                    <select id="fitter_visit" name="fitter_visit" x-model="fitterRequest"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                        <option value="">--Select--</option>
                                        <option value="fitter_visit">Fitter Visit</option>
                                        <option value="device_replacement">Device Replacement</option>
                                    </select>
                                </div>
                            </div>

                            <div class="flex items-end justify-end col-span-1 lg:col-span-1">
                                <button type="submit" 
                                    class="w-full lg:w-40 bg-[#17a2b8] hover:bg-[#138496] text-white font-bold h-10 rounded-lg shadow-sm transition flex items-center justify-center gap-2 text-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                    </svg>
                                    Search
                                </button>
                            </div>

                        </div>
                    </form>

                    <div class="border border-gray-200 rounded-xl overflow-hidden shadow-sm w-full">
                        <div class="p-12 text-center bg-gray-50">
                            <svg class="mx-auto h-12 w-12 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0a2 2 0 01-2 2H6a2 2 0 01-2-2m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                            </svg>
                            <p class="text-gray-600 font-semibold text-base">No Record Found</p>
                            <p class="text-xs text-gray-400 mt-1">There are no custom reports or complaints filed under the current timeline.</p>
                        </div>
                    </div>

                </div>

                <div x-show="activeTab === 'feedback'" x-transition class="p-12 text-center text-gray-500" style="display: none;">Feedback and review metrics table dashboard module viewport.</div>
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