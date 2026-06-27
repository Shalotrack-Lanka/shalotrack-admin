<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden bg-gray-50">

    @include('partials.sidebars.admin')
    @include('partials.header')

    <main class="flex-1 overflow-y-auto p-6">
        @yield('content')

        {{-- ── Top Action Buttons ── --}}
        <div class="flex flex-wrap gap-2 mb-5">
            <a href="#"
               class="inline-flex items-center gap-1.5 bg-sky-500 hover:bg-sky-600 active:bg-sky-700
                      text-white text-sm font-medium px-4 py-2 rounded-md shadow-sm transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round"
                     d="M12 4v16m8-8H4"/></svg>
                Add Supplier
            </a>
            <a href="#"
               class="inline-flex items-center gap-1.5 bg-sky-500 hover:bg-sky-600 active:bg-sky-700
                      text-white text-sm font-medium px-4 py-2 rounded-md shadow-sm transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round"
                     d="M9 12h6m-3-3v6M4 6h16M4 18h16"/></svg>
                Create PO
            </a>
            <a href="#"
               class="inline-flex items-center gap-1.5 bg-sky-500 hover:bg-sky-600 active:bg-sky-700
                      text-white text-sm font-medium px-4 py-2 rounded-md shadow-sm transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round"
                     d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M16 12l-4-4-4 4M12 8v8"/></svg>
                Stock Upload
            </a>
        </div>

        {{-- ── Page Title ── --}}
        <div class="mb-6">
            <h1 class="text-3xl font-light text-gray-700 tracking-tight">Supplier Master</h1>
            <div class="mt-2 h-0.5 w-full bg-gradient-to-r from-sky-400 to-transparent rounded"></div>
        </div>

        {{-- ── Main Two-Column Layout ── --}}
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">

            {{-- ── LEFT: Add Supplier Form ── --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-base font-semibold text-gray-700 mb-5 flex items-center gap-2">
                    <span class="inline-block w-1 h-4 bg-sky-500 rounded"></span>
                    Supplier Details
                </h2>

                <form>
                    <div class="space-y-4">

                        {{-- Supplier Name --}}
                        <div class="grid grid-cols-3 items-center gap-3">
                            <label class="text-sm text-gray-600 font-medium col-span-1">
                                Supplier Name
                                <span class="text-red-400">*</span>
                            </label>
                            <input type="text"
                                   placeholder="Enter supplier name"
                                   class="col-span-2 w-full border border-gray-300 rounded-lg px-3 py-2
                                          text-sm shadow-sm focus:outline-none focus:ring-2
                                          focus:ring-sky-400 focus:border-transparent transition">
                        </div>

                        {{-- Address --}}
                        <div class="grid grid-cols-3 items-start gap-3">
                            <label class="text-sm text-gray-600 font-medium col-span-1 pt-2">Address</label>
                            <textarea rows="3"
                                      placeholder="Street, City, ZIP"
                                      class="col-span-2 w-full border border-gray-300 rounded-lg px-3 py-2
                                             text-sm shadow-sm focus:outline-none focus:ring-2
                                             focus:ring-sky-400 focus:border-transparent transition resize-none"></textarea>
                        </div>

                        {{-- Country --}}
                        <div class="grid grid-cols-3 items-center gap-3">
                            <label class="text-sm text-gray-600 font-medium col-span-1">Country</label>
                            <select class="col-span-2 w-full border border-gray-300 rounded-lg px-3 py-2
                                           text-sm shadow-sm focus:outline-none focus:ring-2
                                           focus:ring-sky-400 focus:border-transparent transition bg-white">
                                <option value="">-- Select Country --</option>
                            </select>
                        </div>

                        {{-- State --}}
                        <div class="grid grid-cols-3 items-center gap-3">
                            <label class="text-sm text-gray-600 font-medium col-span-1">State</label>
                            <select class="col-span-2 w-full border border-gray-300 rounded-lg px-3 py-2
                                           text-sm shadow-sm focus:outline-none focus:ring-2
                                           focus:ring-sky-400 focus:border-transparent transition bg-white">
                                <option value="">-- Select State --</option>
                            </select>
                        </div>

                        {{-- Phone --}}
                        <div class="grid grid-cols-3 items-center gap-3">
                            <label class="text-sm text-gray-600 font-medium col-span-1">Phone Number</label>
                            <input type="tel"
                                   placeholder="+94 71 234 5678"
                                   class="col-span-2 w-full border border-gray-300 rounded-lg px-3 py-2
                                          text-sm shadow-sm focus:outline-none focus:ring-2
                                          focus:ring-sky-400 focus:border-transparent transition">
                        </div>

                        {{-- Email --}}
                        <div class="grid grid-cols-3 items-center gap-3">
                            <label class="text-sm text-gray-600 font-medium col-span-1">Email ID</label>
                            <input type="email"
                                   placeholder="supplier@example.com"
                                   class="col-span-2 w-full border border-gray-300 rounded-lg px-3 py-2
                                          text-sm shadow-sm focus:outline-none focus:ring-2
                                          focus:ring-sky-400 focus:border-transparent transition">
                        </div>

                        {{-- Website --}}
                        <div class="grid grid-cols-3 items-center gap-3">
                            <label class="text-sm text-gray-600 font-medium col-span-1">Website</label>
                            <input type="url"
                                   placeholder="https://example.com"
                                   class="col-span-2 w-full border border-gray-300 rounded-lg px-3 py-2
                                          text-sm shadow-sm focus:outline-none focus:ring-2
                                          focus:ring-sky-400 focus:border-transparent transition">
                        </div>

                        {{-- GSTIN --}}
                        <div class="grid grid-cols-3 items-center gap-3">
                            <label class="text-sm text-gray-600 font-medium col-span-1">GSTIN Number</label>
                            <input type="text"
                                   placeholder="22AAAAA0000A1Z5"
                                   class="col-span-2 w-full border border-gray-300 rounded-lg px-3 py-2
                                          text-sm shadow-sm focus:outline-none focus:ring-2
                                          focus:ring-sky-400 focus:border-transparent transition uppercase tracking-wider">
                        </div>

                    </div>

                    {{-- Form Actions --}}
                    <div class="mt-6 pt-4 border-t border-gray-100 flex items-center gap-3">
                        <button type="reset"
                                class="px-5 py-2 text-sm font-medium text-gray-600 bg-gray-100
                                       hover:bg-gray-200 rounded-lg transition-colors">
                            Reset
                        </button>
                        <button type="submit"
                                class="px-7 py-2 text-sm font-medium text-white bg-sky-500
                                       hover:bg-sky-600 active:bg-sky-700 rounded-lg shadow-sm
                                       transition-colors">
                            Save Supplier
                        </button>
                    </div>
                </form>
            </div>

            {{-- ── RIGHT: Supplier List ── --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col">

                {{-- Tab bar --}}
                <div class="flex border-b border-gray-200 bg-gray-50">
                    <button
                        class="px-6 py-3 text-sm font-semibold text-sky-600 border-b-2 border-sky-500
                               bg-white -mb-px transition-colors">
                        Active
                    </button>
                    <button
                        class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700
                               border-b-2 border-transparent hover:border-gray-300 transition-colors">
                        Archived
                    </button>

                    {{-- Search bar pushed to right --}}
                    <div class="ml-auto flex items-center px-3">
                        <div class="relative">
                            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400"
                                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                            </svg>
                            <input type="text" placeholder="Search…"
                                   class="pl-8 pr-3 py-1.5 text-xs border border-gray-300 rounded-lg
                                          focus:outline-none focus:ring-2 focus:ring-sky-400 w-36">
                        </div>
                    </div>
                </div>

                {{-- Table --}}
                <div class="overflow-y-auto flex-1" style="max-height: 430px;">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0 z-10">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide w-10">#</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Supplier Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Mobile No.</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
                                <th class="px-4 py-3 w-20"></th>
                            </tr>
                            <tr><td colspan="5"><div class="h-px bg-gray-200"></div></td></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @for($i = 1; $i <= 8; $i++)
                            <tr class="hover:bg-sky-50 transition-colors group">
                                <td class="px-4 py-3 text-gray-400 text-xs">{{ $i }}</td>
                                <td class="px-4 py-3 font-medium text-gray-800">Test Supplier {{ $i }}</td>
                                <td class="px-4 py-3 text-gray-600 tabular-nums">0711 2345 {{ $i }}</td>
                                <td class="px-4 py-3 text-gray-500 text-xs">21 Nov 2026</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button title="Edit"
                                                class="p-1.5 rounded-md bg-gray-100 hover:bg-sky-100
                                                       hover:text-sky-600 text-gray-500 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                 stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="M15.232 5.232l3.536 3.536M9 13l6.586-6.586a2 2 0 012.828 2.828L11.828 15.828A2 2 0 0 1 10 16H8v-2a2 2 0 0 1 .586-1.414z"/>
                                            </svg>
                                        </button>
                                        <button title="Delete"
                                                class="p-1.5 rounded-md bg-gray-100 hover:bg-red-100
                                                       hover:text-red-500 text-gray-500 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                 stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7h6m2 0a1 1 0 00-1-1h-4a1 1 0 00-1 1m-4 0h10"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>

                {{-- Table footer --}}
                <div class="px-4 py-2.5 border-t border-gray-100 bg-gray-50 text-xs text-gray-400 flex justify-between items-center">
                    <span>Showing 8 suppliers</span>
                    <span class="text-sky-500 font-medium cursor-pointer hover:underline">View all →</span>
                </div>
            </div>
        </div>

        {{-- ── Supplier Products ── --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="text-base font-semibold text-gray-700 flex items-center gap-2">
                    <span class="inline-block w-1 h-4 bg-sky-500 rounded"></span>
                    Supplier Products
                </h2>
                <button class="text-xs text-sky-500 hover:underline font-medium">+ Add Product</button>
            </div>

            <div class="flex flex-col items-center justify-center py-16 text-gray-400">
                <svg class="w-12 h-12 mb-3 text-gray-300" fill="none" stroke="currentColor"
                     stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0H4m8-5v5"/>
                </svg>
                <p class="text-sm font-medium text-gray-500">No products linked yet</p>
                <p class="text-xs text-gray-400 mt-1">Select a supplier from the list to view their products.</p>
            </div>
        </div>

    </main>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
</body>
</html>