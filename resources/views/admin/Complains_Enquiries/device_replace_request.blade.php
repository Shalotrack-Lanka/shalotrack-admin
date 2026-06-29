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
            activeTab: 'replace_request', {{-- Device Replace Request ටැබ් එක මුලින්ම පෙනෙන්න සකසා ඇත --}}
            statusFilter: 'Open',
            complaintType: 'All',
            complaintSource: 'All',
            fitterRequest: '',
            feedbackType: 'All',
            ratingFilter: 'All',
            subTab: 'new_request' {{-- Sub-tabs සඳහා විචල්‍යය --}}
        }">
            

            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                
                <div x-show="activeTab === 'view_complaints'" x-transition class="p-6 space-y-6 w-full">
                    {{-- පෙර කේතයන් එලෙසම පවතී --}}
                </div>

                <div x-show="activeTab === 'feedback'" x-transition class="p-6 space-y-6 w-full">
                    {{-- පෙර කේතයන් එලෙසම පවතී --}}
                </div>

                <div x-show="activeTab === 'replace_request'" x-transition class="p-6 space-y-6 w-full">
                    
                    <div class="border-b border-gray-100 pb-4">
                        <h3 class="text-lg font-bold text-gray-800">Approve Device Replacement Request</h3>
                    </div>

                    <form method="GET" action="#" class="bg-gray-50 border border-gray-100 p-5 rounded-xl shadow-sm w-full">
                        <div class="flex flex-wrap items-center gap-6 max-w-4xl">
                            
                            <div class="flex items-center gap-2">
                                <label for="req_from_date" class="text-sm font-semibold text-gray-700 whitespace-nowrap">From Date :</label>
                                <input type="date" id="req_from_date" name="from_date" value="2026-06-19"
                                    class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10 w-48">
                            </div>

                            <div class="flex items-center gap-2">
                                <label for="req_to_date" class="text-sm font-semibold text-gray-700 whitespace-nowrap">To Date :</label>
                                <input type="date" id="req_to_date" name="to_date" value="2026-06-26"
                                    class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10 w-48">
                            </div>

                            <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white font-bold h-10 px-6 rounded-lg shadow-sm transition text-sm">
                                Submit
                            </button>
                        </div>
                    </form>

                    <div class="w-full">
                        <div class="flex border-b border-gray-200 bg-gray-50 rounded-t-lg overflow-hidden">
                            <button @click="subTab = 'new_request'" :class="subTab === 'new_request' ? 'bg-white text-gray-800 font-bold border-t-2 border-t-blue-500' : 'text-gray-600 hover:bg-gray-100'"
                                class="px-6 py-2.5 text-xs font-semibold border-r border-gray-200 transition duration-150">
                                New Request
                            </button>
                            <button @click="subTab = 'approve_request'" :class="subTab === 'approve_request' ? 'bg-white text-gray-800 font-bold border-t-2 border-t-blue-500' : 'text-gray-600 hover:bg-gray-100'"
                                class="px-6 py-2.5 text-xs font-semibold border-r border-gray-200 transition duration-150">
                                Approve Request
                            </button>
                            <button @click="subTab = 'rejected_request'" :class="subTab === 'rejected_request' ? 'bg-white text-gray-800 font-bold border-t-2 border-t-blue-500' : 'text-gray-600 hover:bg-gray-100'"
                                class="px-6 py-2.5 text-xs font-semibold transition duration-150">
                                Rejected Request
                            </button>
                        </div>

                        <div class="border border-t-0 border-gray-200 rounded-b-lg p-6 bg-gray-50/50 min-h-[200px] flex flex-col items-center justify-center">
                            
                            <div x-show="subTab === 'new_request'" x-transition class="text-center text-gray-400 text-sm">
                                <svg class="mx-auto h-10 w-10 text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <p class="font-medium text-gray-500">No new device replacement requests found.</p>
                            </div>

                            <div x-show="subTab === 'approve_request'" x-transition class="text-center text-gray-400 text-sm" style="display: none;">
                                <svg class="mx-auto h-10 w-10 text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <p class="font-medium text-gray-500">No approved history records found.</p>
                            </div>

                            <div x-show="subTab === 'rejected_request'" x-transition class="text-center text-gray-400 text-sm" style="display: none;">
                                <svg class="mx-auto h-10 w-10 text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <p class="font-medium text-gray-500">No rejected replacement logs found.</p>
                            </div>

                        </div>
                    </div>

                </div>

                <div x-show="activeTab === 'troubleshoot'" x-transition class="p-12 text-center text-gray-500" style="display: none;">Remote tracking signal inspection terminal pipeline module window.</div>
                <div x-show="activeTab === 'complaint_dashboard'" x-transition class="p-12 text-center text-gray-500" style="display: none;">Analytics overview canvas mapping open vs closed trouble tickets.</div>

            </div> </main>

    </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>