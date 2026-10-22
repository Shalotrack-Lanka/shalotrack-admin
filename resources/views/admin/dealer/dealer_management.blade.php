<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-gray-50/50">

<div class="flex h-screen overflow-hidden">

    @include('partials.sidebars.admin')
    @include('partials.header')

<main class="p-4 md:p-6 flex-1 w-full overflow-y-auto" x-data="{ searchQuery: '', activeTab: 'active', typeFilter: 'all' }">

            @if(session('success'))
                @if(is_array(session('success')))
                    <div class="mb-5 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 text-sm px-4 py-3 rounded shadow-sm">
                        <div class="flex items-center mb-2">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            {{ session('success')['message'] }}
                        </div>
                        <div class="bg-white border border-emerald-200 rounded-lg p-3 mt-2">
                            <p class="text-xs font-bold text-emerald-800 mb-2">System login created — copy this now, the password cannot be shown again:</p>
                            <div class="flex gap-4 text-xs font-mono">
                                <span>Username: <strong>{{ session('success')['username'] }}</strong></span>
                                <span>Password: <strong>{{ session('success')['password'] }}</strong></span>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="mb-5 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 text-sm px-4 py-3 rounded shadow-sm flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        {{ session('success') }}
                    </div>
                @endif
            @endif

            @if($errors->any())
                <div class="mb-5 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm px-4 py-3 rounded shadow-sm">
                    <p class="font-bold mb-1 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        Nothing was saved — fix these first:
                    </p>
                    <ul class="list-disc list-inside space-y-1 ml-7">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif


            {{-- Registration forms: distributor first, dealer second. One shared form, used twice. --}}
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start mb-6">
                @include('admin.dealer.partials.register-form', ['kind' => 'distributor'])
                @include('admin.dealer.partials.register-form', ['kind' => 'dealer'])
            </div>

                <!-- Right Column: Dealer List Table & Search -->
                <div class="bg-white p-6 border border-gray-100 rounded-2xl shadow-sm flex flex-col">

                    <!-- Header with Tabs and Search -->
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center border-b border-gray-100 pb-3 mb-5 gap-4">
                        <div class="flex space-x-6">
                            <button type="button" @click="activeTab = 'active'" :class="activeTab === 'active' ? 'border-cyan-500 text-cyan-600 border-b-2 font-bold' : 'text-gray-400 hover:text-gray-700'" class="pb-3 text-sm transition-all focus:outline-none px-1">
                                Active
                                <span class="ml-1 bg-gray-100 text-gray-600 py-0.5 px-2 rounded-full text-[10px]">{{ count($dealers) }}</span>
                            </button>
                            <button type="button" @click="activeTab = 'archived'" :class="activeTab === 'archived' ? 'border-cyan-500 text-cyan-600 border-b-2 font-bold' : 'text-gray-400 hover:text-gray-700'" class="pb-3 text-sm transition-all focus:outline-none px-1">
                                Archived
                                <span class="ml-1 bg-gray-100 text-gray-600 py-0.5 px-2 rounded-full text-[10px]">{{ count($archivedDealers) }}</span>
                            </button>
                        </div>

                        <!-- Type filter + Search Bar -->
                        <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
                        <select x-model="typeFilter" aria-label="Filter by type" class="border border-gray-200 rounded-xl bg-gray-50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500">
                            <option value="all">All types</option>
                            <option value="distributor">Distributors only</option>
                            <option value="dealer">Dealers only</option>
                        </select>
                        <div class="relative w-full sm:w-72">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                            <input type="text" x-model="searchQuery" placeholder="Search by name, ID, email..." class="block w-full pl-9 pr-3 py-2 border border-gray-200 rounded-xl leading-5 bg-gray-50 placeholder-gray-400 focus:outline-none focus:bg-white focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 text-sm transition-all shadow-sm">
                            <!-- Clear Search Icon (Shows only when typing) -->
                            <button type="button" x-show="searchQuery.length > 0" @click="searchQuery = ''" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>
                        </div>
                    </div>

                    <div class="w-full overflow-x-auto rounded-xl border border-gray-100 shadow-sm flex-1">
                        <div class="inline-block min-w-full align-middle">

                            <!-- ACTIVE DEALERS TABLE -->
                            <table class="min-w-full divide-y divide-gray-200 text-sm text-left" x-show="activeTab === 'active'">
                                <thead class="bg-gray-50/80 text-gray-600 font-semibold sticky top-0 backdrop-blur-sm">
                                    <tr>
                                        <th class="px-4 py-3.5 w-10 text-xs uppercase tracking-wider">#</th>
                                        <th class="px-4 py-3.5 min-w-[150px] text-xs uppercase tracking-wider">Dealer Info</th>
                                        <th class="px-4 py-3.5 min-w-[120px] text-xs uppercase tracking-wider">Login / Region</th>
                                        <th class="px-4 py-3.5 text-xs uppercase tracking-wider">Type</th>
                                        <th class="px-4 py-3.5 text-xs uppercase tracking-wider">Created By</th>
                                        <th class="px-4 py-3.5 whitespace-nowrap text-xs uppercase tracking-wider">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 text-gray-600 bg-white">
                                    @forelse($dealers as $i => $dealer)
                                        <tr class="hover:bg-cyan-50/30 transition-colors" data-type="{{ $dealer->dealer_status === 'distributor' ? 'distributor' : 'dealer' }}"
                                            x-show="(typeFilter === 'all' || $el.dataset.type === typeFilter) && (searchQuery === '' || $el.innerText.toLowerCase().includes(searchQuery.toLowerCase()))">
                                            <td class="px-4 py-3 text-xs text-gray-400">{{ $i + 1 }}.</td>
                                            <td class="px-4 py-3">
                                                <a href="{{ route('admin.dealer.profile', $dealer->id) }}" class="font-bold text-gray-900 hover:text-cyan-600 hover:underline">{{ $dealer->full_name }}</a>
                                                <div class="text-xs text-gray-500 mt-0.5">{{ $dealer->contact_email }}</div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="font-medium text-cyan-700 bg-cyan-50 inline-block px-2 py-0.5 rounded text-xs mb-1">{{ $dealer->login_id }}</div>
                                                <div class="text-xs text-gray-500">{{ ucfirst($dealer->region) }}</div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                    {{ ucfirst($dealer->dealer_status) }}
                                                </span>
                                                @if ($dealer->distributor_id && isset($distributorNames[$dealer->distributor_id]))
                                                    <div class="text-[11px] text-gray-400 mt-1">under {{ $distributorNames[$dealer->distributor_id] }}</div>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-xs text-gray-500">{{ $dealer->created_by }}</td>
                                            <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">{{ $dealer->created_at->local()->format('d M Y') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="p-8 text-center text-gray-400 flex-col items-center justify-center">
                                            <div class="mb-2">No active dealers found.</div>
                                        </td></tr>
                                    @endforelse
                                </tbody>
                            </table>

                            <!-- ARCHIVED DEALERS TABLE -->
                            <table class="min-w-full divide-y divide-gray-200 text-sm text-left" x-show="activeTab === 'archived'" style="display: none;">
                                <thead class="bg-gray-50/80 text-gray-600 font-semibold sticky top-0 backdrop-blur-sm">
                                    <tr>
                                        <th class="px-4 py-3.5 w-10 text-xs uppercase tracking-wider">#</th>
                                        <th class="px-4 py-3.5 min-w-[150px] text-xs uppercase tracking-wider">Dealer Info</th>
                                        <th class="px-4 py-3.5 min-w-[120px] text-xs uppercase tracking-wider">Login / Region</th>
                                        <th class="px-4 py-3.5 text-xs uppercase tracking-wider">Type</th>
                                        <th class="px-4 py-3.5 text-xs uppercase tracking-wider">Created By</th>
                                        <th class="px-4 py-3.5 whitespace-nowrap text-xs uppercase tracking-wider">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 text-gray-500 bg-white opacity-80">
                                    @forelse($archivedDealers as $i => $dealer)
                                        <tr class="hover:bg-gray-50 transition-colors" data-type="{{ $dealer->dealer_status === 'distributor' ? 'distributor' : 'dealer' }}"
                                            x-show="(typeFilter === 'all' || $el.dataset.type === typeFilter) && (searchQuery === '' || $el.innerText.toLowerCase().includes(searchQuery.toLowerCase()))">
                                            <td class="px-4 py-3 text-xs text-gray-400">{{ $i + 1 }}.</td>
                                            <td class="px-4 py-3">
                                                <a href="{{ route('admin.dealer.profile', $dealer->id) }}" class="font-bold text-gray-700 hover:text-cyan-600 hover:underline">{{ $dealer->full_name }}</a>
                                                <div class="text-xs mt-0.5">{{ $dealer->contact_email }}</div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="font-medium bg-gray-100 inline-block px-2 py-0.5 rounded text-xs mb-1">{{ $dealer->login_id }}</div>
                                                <div class="text-xs">{{ ucfirst($dealer->region) }}</div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-200 text-gray-600">
                                                    {{ ucfirst($dealer->dealer_status ?? 'Archived') }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-xs">{{ $dealer->created_by }}</td>
                                            <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $dealer->created_at->local()->format('d M Y') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="p-8 text-center text-gray-400">Nothing archived yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>

                        </div>
                    </div>
                </div>
</main>

</div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>