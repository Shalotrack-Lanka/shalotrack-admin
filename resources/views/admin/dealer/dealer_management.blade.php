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

<main class="p-4 md:p-6 flex-1 w-full overflow-y-auto" x-data="{
            searchQuery: '',
            currentStep: {{ $errors->any() ? ($errors->hasAny(['contact_email','tax_pan','cst_no','vat_tin','gst_pan','security_deposit','deposit_date','network','login_id','password']) ? 2 : ($errors->hasAny(['payment_modes','profile_photo','copy_of_ma','passport_front','passport_last']) ? 3 : 1)) : 1 }},
            activeTab: 'active',
            paymentModes: {!! json_encode(old('payment_modes', [])) !!},
            fileKey: 0,
            dealerData: {
                fullName: '{{ old('full_name') }}', address: '{{ old('address') }}', qualification: '{{ old('qualification') }}',
                dealerStatus: '{{ old('dealer_status') }}', region: '{{ old('region') }}', country: '{{ old('country', 'Sri Lanka') }}', pinCode: '{{ old('pin_code') }}',
                contactEmail: '{{ old('contact_email') }}', taxPan: '{{ old('tax_pan') }}', cstNo: '{{ old('cst_no') }}', vatTin: '{{ old('vat_tin') }}', gstPan: '{{ old('gst_pan') }}',
                securityDeposit: '{{ old('security_deposit', '0') }}', depositDate: '{{ old('deposit_date') }}', network: '{{ old('network') }}', userId: '{{ old('login_id') }}', password: ''
            },
            resetForm() {
                this.dealerData = {
                    fullName: '', address: '', qualification: '',
                    dealerStatus: '', region: '', country: 'Sri Lanka', pinCode: '',
                    contactEmail: '', taxPan: '', cstNo: '', vatTin: '', gstPan: '',
                    securityDeposit: '0', depositDate: '', network: '', userId: '', password: ''
                };
                this.paymentModes = [];
                this.currentStep = 1;
                this.fileKey++;
            }
        }">

            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 tracking-tight">Dealer Management</h1>
                    <p class="text-xs text-gray-500 mt-1">Manage and register new dealers into the system.</p>
                </div>
            </div>

            @if(session('success'))
                <div class="mb-5 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 text-sm px-4 py-3 rounded shadow-sm flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-5 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm px-4 py-3 rounded shadow-sm">
                    <p class="font-bold mb-1 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        Dealer was NOT saved — fix these first:
                    </p>
                    <ul class="list-disc list-inside space-y-1 ml-7">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                <!-- Left Column: Add Dealer Form -->
                <div class="lg:col-span-4 bg-white p-6 border border-gray-100 rounded-2xl shadow-sm">

                    <div class="flex space-x-2 mb-6 border-b border-gray-100 pb-5">
                        <template x-for="step in [1,2,3]">
                            <button type="button"
                                    @click="currentStep = step"
                                    :class="currentStep === step ?
                                        'bg-cyan-500 text-white shadow-md font-semibold ring-2 ring-cyan-500/20' : 'bg-gray-50 text-gray-500 hover:bg-gray-100 border border-gray-200'"
                                    class="flex-1 py-1.5 text-xs rounded-md transition-all text-center relative"
                                    x-text="'Step ' + step">
                            </button>
                        </template>
                    </div>

                    <form method="POST" action="{{ route('admin.dealer.store') }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf

                        {{-- STEP 1: Basics --}}
                        <div x-show="currentStep === 1" x-transition.opacity class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Full Name</label>
                                <input type="text" x-model="dealerData.fullName" name="full_name" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Address</label>
                                <textarea x-model="dealerData.address" name="address" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all"></textarea>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="col-span-2 sm:col-span-1">
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Qualifications</label>
                                    <input type="text" x-model="dealerData.qualification" name="qualification" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                                </div>
                                <div class="col-span-2 sm:col-span-1">
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Dealer Status</label>
                                    <select x-model="dealerData.dealerStatus" name="dealer_status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all bg-white">
                                        <option value="" selected disabled>Select</option>
                                        <option value="lbc">LBC</option>
                                        <option value="distributor">Distributor</option>
                                        <option value="retailer">Retailer</option>
                                        <option value="dsa">DSA</option>
                                        <option value="ba">BA</option>
                                        <option value="csa">CSA</option>
                                        <option value="lt_point">LT Point</option>
                                    </select>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="col-span-2 sm:col-span-1">
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Region</label>
                                    <select x-model="dealerData.region" name="region" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all bg-white">
                                        <option value="" selected disabled>--Select--</option>
                                        <option value="central">Central</option>
                                        <option value="colombo">Colombo</option>
                                        <option value="eastern">Eastern</option>
                                        <option value="gampaha">Gamapaha</option>
                                        <option value="north_central">North Central</option>
                                        <option value="north_western">North Western</option>
                                        <option value="northern">Northern</option>
                                        <option value="sabaragamuwa">Sabaragamuwa</option>
                                        <option value="southern">Southern</option>
                                        <option value="uva">Uva</option>
                                        <option value="western">Western</option>
                                    </select>
                                </div>
                                <div class="col-span-2 sm:col-span-1">
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Country</label>
                                    <select x-model="dealerData.country" name="country" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all bg-white">
                                        <option value="Sri Lanka">Sri Lanka</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Pin Code</label>
                                <input type="text" x-model="dealerData.pinCode" name="pin_code" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                            </div>
                        </div>

                        {{-- STEP 2: Compliance & Access --}}
                        <div x-show="currentStep === 2" x-transition.opacity class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Contact Email</label>
                                <input type="email" x-model="dealerData.contactEmail" name="contact_email" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Income Tax PAN</label>
                                    <input type="text" x-model="dealerData.taxPan" name="tax_pan" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">CST NO</label>
                                    <input type="text" x-model="dealerData.cstNo" name="cst_no" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">VAT TIN NO</label>
                                    <input type="text" x-model="dealerData.vatTin" name="vat_tin" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">GST/PAN NO</label>
                                    <input type="text" x-model="dealerData.gstPan" name="gst_pan" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Security Deposit</label>
                                    <input type="number" x-model="dealerData.securityDeposit" name="security_deposit" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Deposit Date</label>
                                    <input type="date" x-model="dealerData.depositDate" name="deposit_date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Best Available Network</label>
                                <select x-model="dealerData.network" name="network" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all bg-white">
                                    <option value="" selected disabled>--Select--</option>
                                    <option value="dialog_axiata">Dialog Axiata</option>
                                    <option value="mobitel_sri_lanka">Mobitel Sri Lanka</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">User ID</label>
                                    <input type="text" x-model="dealerData.userId" name="login_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Password</label>
                                    <input type="password" x-model="dealerData.password" name="password" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all">
                                </div>
                            </div>
                        </div>

                        {{-- STEP 3: Documents & Payment --}}
                        <div x-show="currentStep === 3" x-transition.opacity class="space-y-5">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-2">Payment Modes</label>
                                <div class="grid grid-cols-2 gap-2.5 bg-gray-50/50 p-3.5 border border-gray-200 rounded-xl text-xs text-gray-700">
                                    <label class="flex items-center space-x-2 cursor-pointer"><input type="checkbox" value="Pay Online" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500 focus:ring-cyan-500 w-4 h-4"> <span>Pay Online</span></label>
                                    <label class="flex items-center space-x-2 cursor-pointer"><input type="checkbox" value="Cash On Delivery" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500 focus:ring-cyan-500 w-4 h-4"> <span>Cash On Delivery</span></label>
                                    <label class="flex items-center space-x-2 cursor-pointer"><input type="checkbox" value="Collect Cash" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500 focus:ring-cyan-500 w-4 h-4"> <span>Collect Cash</span></label>
                                    <label class="flex items-center space-x-2 cursor-pointer"><input type="checkbox" value="Cheque" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500 focus:ring-cyan-500 w-4 h-4"> <span>Cheque</span></label>
                                    <label class="flex items-center space-x-2 cursor-pointer"><input type="checkbox" value="Payment Pending" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500 focus:ring-cyan-500 w-4 h-4"> <span>Payment Pending</span></label>
                                    <label class="flex items-center space-x-2 cursor-pointer"><input type="checkbox" value="IMPS" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500 focus:ring-cyan-500 w-4 h-4"> <span>IMPS</span></label>
                                    <label class="flex items-center space-x-2 cursor-pointer"><input type="checkbox" value="NEFT" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500 focus:ring-cyan-500 w-4 h-4"> <span>NEFT</span></label>
                                    <label class="flex items-center space-x-2 cursor-pointer"><input type="checkbox" value="RTGS" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500 focus:ring-cyan-500 w-4 h-4"> <span>RTGS</span></label>
                                </div>
                            </div>

                            <div class="space-y-3.5 text-sm" :key="fileKey">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Profile Photo</label>
                                    <input type="file" name="profile_photo" class="w-full text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-cyan-50 file:text-cyan-700 hover:file:bg-cyan-100 transition-all border border-gray-200 rounded-lg p-1">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Copy of M/A</label>
                                    <input type="file" name="copy_of_ma" class="w-full text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-cyan-50 file:text-cyan-700 hover:file:bg-cyan-100 transition-all border border-gray-200 rounded-lg p-1">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Passport Front Page</label>
                                    <input type="file" name="passport_front" class="w-full text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-cyan-50 file:text-cyan-700 hover:file:bg-cyan-100 transition-all border border-gray-200 rounded-lg p-1">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Passport Last Page</label>
                                    <input type="file" name="passport_last" class="w-full text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-cyan-50 file:text-cyan-700 hover:file:bg-cyan-100 transition-all border border-gray-200 rounded-lg p-1">
                                </div>
                            </div>

                            <div class="flex gap-3 pt-5 border-t border-gray-100">
                                <button type="submit" class="flex-1 bg-cyan-600 hover:bg-cyan-700 text-white font-semibold px-4 py-2 rounded-lg text-sm transition-colors shadow-sm shadow-cyan-600/30">Save Dealer</button>
                                <button type="button" @click="resetForm()" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 font-medium px-4 py-2 rounded-lg text-sm transition-colors shadow-sm">Reset</button>
                            </div>
                        </div>

                        <div class="flex justify-between items-center mt-5 pt-4 border-t border-gray-100" x-show="currentStep < 3">
                            <button type="button" @click="currentStep = Math.max(1, currentStep - 1)" :disabled="currentStep === 1" class="bg-white border border-gray-200 text-gray-600 px-4 py-1.5 rounded-lg text-sm disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-50 transition-colors shadow-sm">Previous</button>
                            <button type="button" @click="currentStep = Math.min(3, currentStep + 1)" class="bg-gray-800 text-white px-5 py-1.5 rounded-lg text-sm hover:bg-gray-900 transition-colors shadow-sm">Next Step</button>
                        </div>
                    </form>
                </div>

                <!-- Right Column: Dealer List Table & Search -->
                <div class="lg:col-span-8 bg-white p-6 border border-gray-100 rounded-2xl shadow-sm flex flex-col h-full min-h-[500px]">

                    <!-- Header with Tabs and Search -->
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center border-b border-gray-100 pb-3 mb-5 gap-4">
                        <div class="flex space-x-6">
                            <button type="button" @click="activeTab = 'active'" :class="activeTab === 'active' ? 'border-cyan-500 text-cyan-600 border-b-2 font-bold' : 'text-gray-400 hover:text-gray-700'" class="pb-3 text-sm transition-all focus:outline-none px-1">
                                Active Dealers
                                <span class="ml-1 bg-gray-100 text-gray-600 py-0.5 px-2 rounded-full text-[10px]">{{ count($dealers) }}</span>
                            </button>
                            <button type="button" @click="activeTab = 'archived'" :class="activeTab === 'archived' ? 'border-cyan-500 text-cyan-600 border-b-2 font-bold' : 'text-gray-400 hover:text-gray-700'" class="pb-3 text-sm transition-all focus:outline-none px-1">
                                Archived
                                <span class="ml-1 bg-gray-100 text-gray-600 py-0.5 px-2 rounded-full text-[10px]">{{ count($archivedDealers) }}</span>
                            </button>
                        </div>
                        
                        <!-- Search Bar -->
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
                                        <tr class="hover:bg-cyan-50/30 transition-colors"
                                            x-show="searchQuery === '' || $el.innerText.toLowerCase().includes(searchQuery.toLowerCase())">
                                            <td class="px-4 py-3 text-xs text-gray-400">{{ $i + 1 }}.</td>
                                            <td class="px-4 py-3">
                                                <div class="font-bold text-gray-900">{{ $dealer->full_name }}</div>
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
                                            </td>
                                            <td class="px-4 py-3 text-xs text-gray-500">{{ $dealer->created_by }}</td>
                                            <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">{{ $dealer->created_at->format('d M Y') }}</td>
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
                                        <tr class="hover:bg-gray-50 transition-colors"
                                            x-show="searchQuery === '' || $el.innerText.toLowerCase().includes(searchQuery.toLowerCase())">
                                            <td class="px-4 py-3 text-xs text-gray-400">{{ $i + 1 }}.</td>
                                            <td class="px-4 py-3">
                                                <div class="font-bold text-gray-700">{{ $dealer->full_name }}</div>
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
                                            <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $dealer->created_at->format('d M Y') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="p-8 text-center text-gray-400">Nothing archived yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>

                        </div>
                    </div>
                </div>
            </div>
</main>

</div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>