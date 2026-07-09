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

<div class="flex h-screen overflow-hidden">

    @include('partials.sidebars.admin')
    @include('partials.header')

<main class="p-4 md:p-6 flex-1 w-full" x-data="{
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

            <div class="mb-5">
                <h1 class="text-xl font-bold text-gray-800">Add Channel (Dealer)</h1>
            </div>

            @if(session('success'))
                <div class="mb-4 bg-green-50 border border-green-200 text-green-700 text-xs px-4 py-2 rounded-lg">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-4 bg-red-50 border border-red-200 text-red-700 text-xs px-4 py-3 rounded-lg">
                    <p class="font-semibold mb-1">Dealer was NOT saved — fix these first:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                <div class="lg:col-span-4 bg-white p-5 border border-gray-200 rounded-xl shadow-sm">

                    <div class="grid grid-cols-3 gap-1 mb-5 border-b pb-4">
                        <template x-for="step in [1,2,3]">
                            <button type="button"
                                    @click="currentStep = step"
                                    :class="currentStep === step ?
                                        'bg-cyan-500 text-white shadow-sm font-semibold' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                                    class="py-1 text-[11px] rounded transition-all text-center"
                                    x-text="'Step ' + step">
                            </button>
                        </template>
                    </div>

                    <form method="POST" action="{{ route('admin.dealer.store') }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf

                        {{-- STEP 1: Basics --}}
                        <div x-show="currentStep === 1" class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Full Name</label>
                                <input type="text" x-model="dealerData.fullName" name="full_name" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Address</label>
                                <textarea x-model="dealerData.address" name="address" rows="2" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Qualifications</label>
                                <input type="text" x-model="dealerData.qualification" name="qualification" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Dealer Status</label>
                                <select x-model="dealerData.dealerStatus" name="dealer_status" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
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
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Region</label>
                                <select x-model="dealerData.region" name="region" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
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
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Country</label>
                                <select x-model="dealerData.country" name="country" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="Sri Lanka">Sri Lanka</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Pin Code</label>
                                <input type="text" x-model="dealerData.pinCode" name="pin_code" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                        </div>

                        {{-- STEP 2: Compliance & Access --}}
                        <div x-show="currentStep === 2" class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Contact Email</label>
                                <input type="email" x-model="dealerData.contactEmail" name="contact_email" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Income Tax PAN NO</label>
                                <input type="text" x-model="dealerData.taxPan" name="tax_pan" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">CST NO</label>
                                <input type="text" x-model="dealerData.cstNo" name="cst_no" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">VAT TIN NO</label>
                                <input type="text" x-model="dealerData.vatTin" name="vat_tin" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">GST/PAN NO</label>
                                <input type="text" x-model="dealerData.gstPan" name="gst_pan" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Security Deposit</label>
                                <input type="number" x-model="dealerData.securityDeposit" name="security_deposit" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Security Deposit Date</label>
                                <input type="date" x-model="dealerData.depositDate" name="deposit_date" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Best Available Network</label>
                                <select x-model="dealerData.network" name="network" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="" selected disabled>--Select--</option>
                                    <option value="dialog_axiata">Dialog Axiata</option>
                                    <option value="mobitel_sri_lanka">Mobitel Sri Lanka</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">User Id</label>
                                <input type="text" x-model="dealerData.userId" name="login_id" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Password</label>
                                <input type="password" x-model="dealerData.password" name="password" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                        </div>

                        {{-- STEP 3: Documents & Payment --}}
                        <div x-show="currentStep === 3" class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1.5">Payment Modes</label>
                                <div class="grid grid-cols-2 gap-2 bg-gray-50 p-2.5 border border-gray-200 rounded-lg text-xs text-gray-700">
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Pay Online" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500"> <span>Pay Online</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Cash On Delivery" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500"> <span>Cash On Delivery</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Collect Cash" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500"> <span>Collect Cash</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Cheque" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500"> <span>Cheque</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Payment Pending" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500"> <span>Payment Pending</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="IMPS" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500"> <span>IMPS</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="NEFT" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500"> <span>NEFT</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="RTGS" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500"> <span>RTGS</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Online Payment" x-model="paymentModes" name="payment_modes[]" class="rounded text-cyan-500"> <span>Online Payment</span></label>
                                </div>
                            </div>

                            <div class="space-y-2 text-xs" :key="fileKey">
                                <div>
                                    <label class="block font-semibold text-gray-600 mb-1">Profile Photo</label>
                                    <input type="file" name="profile_photo" class="w-full text-gray-500 file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-gray-100 hover:file:bg-gray-200">
                                </div>
                                <div>
                                    <label class="block font-semibold text-gray-600 mb-1">Copy of M/A</label>
                                    <input type="file" name="copy_of_ma" class="w-full text-gray-500 file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-gray-100 hover:file:bg-gray-200">
                                </div>
                                <div>
                                    <label class="block font-semibold text-gray-600 mb-1">Passport Front Page</label>
                                    <input type="file" name="passport_front" class="w-full text-gray-500 file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-gray-100 hover:file:bg-gray-200">
                                </div>
                                <div>
                                    <label class="block font-semibold text-gray-600 mb-1">Passport Last Page</label>
                                    <input type="file" name="passport_last" class="w-full text-gray-500 file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-gray-100 hover:file:bg-gray-200">
                                </div>
                            </div>

                            <div class="flex gap-2 pt-3 border-t">
                                <button type="submit" class="bg-cyan-500 hover:bg-cyan-600 text-white font-medium px-4 py-1.5 rounded text-xs transition-colors">Save</button>
                                <button type="button" @click="resetForm()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium px-4 py-1.5 rounded text-xs transition-colors">Reset</button>
                            </div>
                        </div>

                        <div class="flex justify-between items-center mt-4 pt-3 border-t" x-show="currentStep < 3">
                            <button type="button" @click="currentStep = Math.max(1, currentStep - 1)" :disabled="currentStep === 1" class="bg-gray-100 text-gray-600 px-3 py-1 rounded text-xs disabled:opacity-50">Previous</button>
                            <button type="button" @click="currentStep = Math.min(3, currentStep + 1)" class="bg-cyan-500 text-white px-3 py-1 rounded text-xs hover:bg-cyan-600">Next</button>
                        </div>
                    </form>
                </div>

                <div class="lg:col-span-8 bg-white p-5 border border-gray-200 rounded-xl shadow-sm flex flex-col">

                    <div class="flex space-x-6 border-b border-gray-200 mb-4">
                        <button type="button" @click="activeTab = 'active'" :class="activeTab === 'active' ? 'border-cyan-500 text-cyan-600 border-b-2 font-semibold' : 'text-gray-400 hover:text-gray-600'" class="pb-2 text-xs transition-all focus:outline-none">Active</button>
                        <button type="button" @click="activeTab = 'archived'" :class="activeTab === 'archived' ? 'border-cyan-500 text-cyan-600 border-b-2 font-semibold' : 'text-gray-400 hover:text-gray-600'" class="pb-2 text-xs transition-all focus:outline-none">Archived</button>
                    </div>

                    <div class="w-full overflow-x-auto rounded-lg border border-gray-200 shadow-inner max-h-[500px]">
                        <div class="inline-block min-w-full align-middle">

                            <table class="min-w-full divide-y divide-gray-200 text-xs text-left" x-show="activeTab === 'active'">
                                <thead class="bg-gray-50 text-gray-700 font-semibold sticky top-0">
                                    <tr>
                                        <th class="p-3 w-8">#</th>
                                        <th class="p-3 min-w-[140px]">Full Name</th>
                                        <th class="p-3 min-w-[100px]">Login ID</th>
                                        <th class="p-3 min-w-[160px]">Contact Email</th>
                                        <th class="p-3">Region</th>
                                        <th class="p-3">User Type</th>
                                        <th class="p-3">Created By</th>
                                        <th class="p-3 whitespace-nowrap">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 text-gray-600 bg-white">
                                    @forelse($dealers as $i => $dealer)
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="p-3">{{ $i + 1 }}.</td>
                                            <td class="p-3 font-semibold text-gray-900 whitespace-nowrap">{{ $dealer->full_name }}</td>
                                            <td class="p-3">{{ $dealer->login_id }}</td>
                                            <td class="p-3">{{ $dealer->contact_email }}</td>
                                            <td class="p-3">{{ ucfirst($dealer->region) }}</td>
                                            <td class="p-3">{{ ucfirst($dealer->dealer_status) }}</td>
                                            <td class="p-3 text-gray-500">{{ $dealer->created_by }}</td>
                                            <td class="p-3 whitespace-nowrap">{{ $dealer->created_at->format('d M Y') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="8" class="p-4 text-center text-gray-400">No dealers yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>

                            <table class="min-w-full divide-y divide-gray-200 text-xs text-left" x-show="activeTab === 'archived'">
                                <thead class="bg-gray-50 text-gray-700 font-semibold sticky top-0">
                                    <tr>
                                        <th class="p-3 w-8">#</th>
                                        <th class="p-3 min-w-[140px]">Full Name</th>
                                        <th class="p-3 min-w-[100px]">Login ID</th>
                                        <th class="p-3 min-w-[160px]">Contact Email</th>
                                        <th class="p-3">Region</th>
                                        <th class="p-3 whitespace-nowrap">Date</th>
                                        <th class="p-3">Created By</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 text-gray-500 bg-white">
                                    @forelse($archivedDealers as $i => $dealer)
                                        <tr class="hover:bg-gray-50">
                                            <td class="p-3">{{ $i + 1 }}.</td>
                                            <td class="p-3 font-semibold text-gray-800 whitespace-nowrap">{{ $dealer->full_name }}</td>
                                            <td class="p-3">{{ $dealer->login_id }}</td>
                                            <td class="p-3">{{ $dealer->contact_email }}</td>
                                            <td class="p-3">{{ ucfirst($dealer->region) }}</td>
                                            <td class="p-3 whitespace-nowrap">{{ $dealer->created_at->format('d M Y') }}</td>
                                            <td class="p-3">{{ $dealer->created_by }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="p-4 text-center text-gray-400">Nothing archived.</td></tr>
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