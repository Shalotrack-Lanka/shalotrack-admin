@php
    $isOld  = old('form_kind') === $kind;
    $o      = fn ($key, $default = '') => $isOld ? old($key, $default) : $default;
    $errStep = $isOld && $errors->any()
        ? ($errors->hasAny(['contact_email','tax_pan','cst_no','vat_tin','gst_pan','security_deposit','deposit_date','network','login_id','password']) ? 2
            : ($errors->hasAny(['payment_modes','profile_photo','copy_of_ma','passport_front','passport_last']) ? 3 : 1))
        : 1;
    $initial = [
        'fullName' => $o('full_name'), 'address' => $o('address'), 'qualification' => $o('qualification'),
        'distributorId' => (string) $o('distributor_id'),
        'region' => $o('region'), 'country' => $o('country', 'Sri Lanka'), 'pinCode' => $o('pin_code'),
        'contactEmail' => $o('contact_email'), 'taxPan' => $o('tax_pan'), 'cstNo' => $o('cst_no'), 'vatTin' => $o('vat_tin'), 'gstPan' => $o('gst_pan'),
        'securityDeposit' => $o('security_deposit', '0'), 'depositDate' => $o('deposit_date'), 'network' => $o('network'), 'userId' => $o('login_id'), 'password' => '',
    ];
    $blank = array_merge(array_fill_keys(array_keys($initial), ''), ['country' => 'Sri Lanka', 'securityDeposit' => '0']);
    $title = $kind === 'distributor' ? 'New Distributor' : 'New Dealer';
    $hint  = $kind === 'distributor'
        ? 'Signs in to the Distributor portal and can pass stock to its own dealers.'
        : 'Signs in to the Dealer portal. Attach it to a distributor, or leave it direct.';
@endphp
<div class="bg-white p-6 border border-gray-100 rounded-2xl shadow-sm"
     x-data="{
        currentStep: {{ $errStep }},
        paymentModes: {{ Illuminate\Support\Js::from($isOld ? old('payment_modes', []) : []) }},
        fileKey: 0,
        dealerData: {{ Illuminate\Support\Js::from($initial) }},
        draftKey: 'dealer-mgmt-draft-{{ $kind }}',
        init() {
            // Drafts live in this tab's sessionStorage only (never the password, never files), so a failed
            // submit, the OTHER form's submit or an accidental refresh does not wipe what was typed.
            try {
                if (@js(session('saved_kind') === $kind)) {
                    sessionStorage.removeItem(this.draftKey);
                } else if (!@js($isOld)) {
                    const d = JSON.parse(sessionStorage.getItem(this.draftKey) || 'null');
                    if (d && d.dealerData) {
                        this.dealerData = Object.assign({}, this.dealerData, d.dealerData, { password: '' });
                        this.paymentModes = Array.isArray(d.paymentModes) ? d.paymentModes : [];
                        this.currentStep = [1, 2, 3].includes(d.currentStep) ? d.currentStep : 1;
                    }
                }
            } catch (e) {}
            const save = () => {
                try {
                    const { password, ...safe } = this.dealerData;
                    sessionStorage.setItem(this.draftKey, JSON.stringify({ dealerData: safe, paymentModes: this.paymentModes, currentStep: this.currentStep }));
                } catch (e) {}
            };
            this.$watch('dealerData', save);
            this.$watch('paymentModes', save);
            this.$watch('currentStep', save);
        },
        resetForm() {
            this.dealerData = {{ Illuminate\Support\Js::from($blank) }};
            this.paymentModes = [];
            this.currentStep = 1;
            this.fileKey++;
            try { sessionStorage.removeItem(this.draftKey); } catch (e) {}
        }
     }">
    <div class="mb-4">
        <h2 class="text-base font-bold text-gray-800">{{ $title }}</h2>
        <p class="text-xs text-gray-500 mt-0.5">{{ $hint }}</p>
    </div>
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
                        <input type="hidden" name="form_kind" value="{{ $kind }}">

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
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Type</label>
                                    <div class="w-full border border-gray-200 bg-gray-50 rounded-lg px-3 py-2 text-sm text-gray-700 font-semibold">{{ $kind === 'distributor' ? 'Distributor' : 'Dealer' }}</div>
                                </div>
                            </div>
                            @if ($kind === 'dealer')
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Distributor <span class="font-normal text-gray-400">(optional: leave empty for a direct dealer)</span></label>
                                    <select x-model="dealerData.distributorId" name="distributor_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-4 focus:ring-cyan-500/10 focus:border-cyan-500 focus:outline-none transition-all bg-white">
                                        <option value="">Direct (no distributor)</option>
                                        @foreach ($distributorOptions as $dist)
                                            <option value="{{ $dist->id }}">{{ $dist->full_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
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
                                <button type="submit" class="flex-1 bg-cyan-600 hover:bg-cyan-700 text-white font-semibold px-4 py-2 rounded-lg text-sm transition-colors shadow-sm shadow-cyan-600/30">{{ $kind === 'distributor' ? 'Save Distributor' : 'Save Dealer' }}</button>
                                <button type="button" @click="resetForm()" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 font-medium px-4 py-2 rounded-lg text-sm transition-colors shadow-sm">Reset</button>
                            </div>
                        </div>

                        <div class="flex justify-between items-center mt-5 pt-4 border-t border-gray-100" x-show="currentStep < 3">
                            <button type="button" @click="currentStep = Math.max(1, currentStep - 1)" :disabled="currentStep === 1" class="bg-white border border-gray-200 text-gray-600 px-4 py-1.5 rounded-lg text-sm disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-50 transition-colors shadow-sm">Previous</button>
                            <button type="button" @click="currentStep = Math.min(3, currentStep + 1)" class="bg-gray-800 text-white px-5 py-1.5 rounded-lg text-sm hover:bg-gray-900 transition-colors shadow-sm">Next Step</button>
                        </div>
                    </form>
</div>