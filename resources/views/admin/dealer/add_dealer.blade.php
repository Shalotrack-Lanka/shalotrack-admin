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
            currentStep: 1, 
            activeTab: 'active',
            paymentModes: [],
            fileKey: 0,
            dealerData: {
                dealerStatus: '', uperChannel: '', companyName: '', contactPerson: '', mobileNo: '', address: '', district: '', country: 'Sri Lanka', state: '', pinCode: '',
                commencementDate: '', email: '', taxPan: '', cstNo: '', vatTin: '', gstPan: '', region: '', area: '', salesPerson: '', priceGroup: '', commissionType: 'Global', commissionGroup: '',
                creditAmount: '0', creditDays: '0', securityDeposit: '0', depositDate: '', deliverToCustomer: false, network: '', userId: '', password: '',
                businessEntity: '', fullDetailsOf: '', ownerName: '', homeAddress: '', qualification: '', ownership: '', involvement: ''
            },
            resetForm() {
                this.dealerData = {
                    dealerStatus: '', uperChannel: '', companyName: '', contactPerson: '', mobileNo: '', address: '', district: '', country: 'Sri Lanka', state: '', pinCode: '',
                    commencementDate: '', email: '', taxPan: '', cstNo: '', vatTin: '', gstPan: '', region: '', area: '', salesPerson: '', priceGroup: '', commissionType: 'Global', commissionGroup: '',
                    creditAmount: '0', creditDays: '0', securityDeposit: '0', depositDate: '', deliverToCustomer: false, network: '', userId: '', password: '',
                    businessEntity: '', fullDetailsOf: '', ownerName: '', homeAddress: '', qualification: '', ownership: '', involvement: ''
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

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                
                <div class="lg:col-span-4 bg-white p-5 border border-gray-200 rounded-xl shadow-sm">
                    
                    <div class="grid grid-cols-5 gap-1 mb-5 border-b pb-4">
                        <template x-for="step in [1,2,3,4,5]">
                            <button type="button" 
                                    @click="currentStep = step"
                                    :class="currentStep === step ? 'bg-cyan-500 text-white shadow-sm font-semibold' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                                    class="py-1 text-[11px] rounded transition-all text-center"
                                    x-text="'Step ' + step">
                            </button>
                        </template>
                    </div>

                    <form method="POST" action="{{ route('admin.dealer.store') }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf

                        <div x-show="currentStep === 1" class="space-y-3">
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
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Upper Channel</label>
                                <select x-model="dealerData.uperChannel" name="upper_channel" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">Select</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Company/Firm Name</label>
                                <input type="text" x-model="dealerData.companyName" name="company_name" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Contact Person Name</label>
                                <input type="text" x-model="dealerData.contactPerson" name="contact_person" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Contact Person Mobile No</label>
                                <input type="text" x-model="dealerData.mobileNo" name="mobile_no" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Registered Address</label>
                                <textarea x-model="dealerData.address" name="address" rows="2" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">District</label>
                                <input type="text" x-model="dealerData.district" name="district" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Country</label>
                                <select x-model="dealerData.country" name="country" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="Sri Lanka">Sri Lanka</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">State</label>
                                <select x-model="dealerData.state" name="state" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="" selected>--Select--</option>
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
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Pin Code</label>
                                <input type="text" x-model="dealerData.pinCode" name="pin_code" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                        </div>

                        <div x-show="currentStep === 2" class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Commencement of Business(Date)</label>
                                <input type="date" x-model="dealerData.commencementDate" name="commencement_date" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">E - Mail Id</label>
                                <input type="email" x-model="dealerData.email" name="email" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
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
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Area</label>
                                <select x-model="dealerData.area" name="area" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="No Data">No Data</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Sales Person</label>
                                <select x-model="dealerData.salesPerson" name="sales_person" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">--Select--</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Price Group</label>
                                <select x-model="dealerData.priceGroup" name="price_group" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="" selected disabled>--Select--</option>
                                    <option value="vat_ba">VAT-BA</option>
                                    <option value="vat_csa">VAT-CSA</option>
                                    <option value="vat_dist">VAT-DIST</option>
                                    <option value="vat_dsa">VAT-DSA</option>
                                    <option value="vat_lbc">VAT-LBC</option>
                                    <option value="vat_ltpoint">VAT-LTPoint</option>
                                    <option value="vat_ret">VAT-RET</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Commission Type</label>
                                <div class="flex items-center space-x-4 mt-1 text-xs">
                                    <label class="flex items-center space-x-1"><input type="radio" x-model="dealerData.commissionType" name="commission_type" value="Global" class="text-cyan-500"> <span>Globle</span></label>
                                    <label class="flex items-center space-x-1"><input type="radio" x-model="dealerData.commissionType" name="commission_type" value="Statewise" class="text-cyan-500"> <span>Statewise</span></label>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Commission Group</label>
                                <select x-model="dealerData.commissionGroup" name="commission_group" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">--Select--</option>
                                </select>
                            </div>
                        </div>

                        <div x-show="currentStep === 3" class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Credit Amount</label>
                                <input type="number" x-model="dealerData.creditAmount" name="credit_amount" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Credit Days</label>
                                <input type="number" x-model="dealerData.creditDays" name="credit_days" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
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
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Product Deliver To Customer</label>
                                <label class="flex items-center space-x-2 mt-1 text-xs cursor-pointer">
                                    <input type="checkbox" x-model="dealerData.deliverToCustomer" name="deliver_to_customer" value="1" class="rounded border-gray-300 text-cyan-500">
                                    <span>Yes</span>
                                </label>
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

                        <div x-show="currentStep === 4" class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Type of Business Entity</label>
                                <select x-model="dealerData.businessEntity" name="business_entity" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="" selected disabled>Select</option>
                                    <option value="sole_proprietorship">Sole Proprietorship</option>
                                    <option value="private_ltd_co">Private Ltd Co.</option>
                                    <option value="partnership">Partnership</option>
                                    <option value="public_ltd_co">Public Ltd Co.</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Full Details of</label>
                                <select x-model="dealerData.fullDetailsOf" name="full_details_of" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="" selected disabled>Select</option>
                                    <option value="proprietor">Proprietor</option>
                                    <option value="partners">Partners</option>
                                    <option value="directors">Directors</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Name</label>
                                <input type="text" x-model="dealerData.ownerName" name="owner_name" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Home Address & Telephone</label>
                                <textarea x-model="dealerData.homeAddress" name="home_address" rows="3" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Qualification</label>
                                <input type="text" x-model="dealerData.qualification" name="qualification" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Ownership</label>
                                <input type="text" x-model="dealerData.ownership" name="ownership" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Involvement in Firm/Company</label>
                                <input type="text" x-model="dealerData.involvement" name="involvement" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                        </div>

                        <div x-show="currentStep === 5" class="space-y-4">
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

                        <div class="flex justify-between items-center mt-4 pt-3 border-t" x-show="currentStep < 5">
                            <button type="button" @click="currentStep = Math.max(1, currentStep - 1)" :disabled="currentStep === 1" class="bg-gray-100 text-gray-600 px-3 py-1 rounded text-xs disabled:opacity-50">Previous</button>
                            <button type="button" @click="currentStep = Math.min(5, currentStep + 1)" class="bg-cyan-500 text-white px-3 py-1 rounded text-xs hover:bg-cyan-600">Next</button>
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
                                        <th class="p-3 min-w-[140px]">Company Name</th>
                                        <th class="p-3">Region</th>
                                        <th class="p-3">Area</th>
                                        <th class="p-3">User Type</th>
                                        <th class="p-3">Created By</th>
                                        <th class="p-3 whitespace-nowrap">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 text-gray-600 bg-white">
                                    @forelse($dealers as $i => $dealer)
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="p-3">{{ $i + 1 }}.</td>
                                            <td class="p-3 font-semibold text-gray-900 whitespace-nowrap">{{ $dealer->company_name }}</td>
                                            <td class="p-3">{{ ucfirst($dealer->region) }}</td>
                                            <td class="p-3">{{ $dealer->area }}</td>
                                            <td class="p-3">{{ ucfirst($dealer->dealer_status) }}</td>
                                            <td class="p-3 text-gray-500">{{ $dealer->created_by }}</td>
                                            <td class="p-3 whitespace-nowrap">{{ $dealer->created_at->format('d M Y') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="p-4 text-center text-gray-400">No dealers yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>

                            <table class="min-w-full divide-y divide-gray-200 text-xs text-left" x-show="activeTab === 'archived'">
                                <thead class="bg-gray-50 text-gray-700 font-semibold sticky top-0">
                                    <tr>
                                        <th class="p-3 w-8">#</th>
                                        <th class="p-3 min-w-[140px]">Company Name</th>
                                        <th class="p-3">Region</th>
                                        <th class="p-3">Area</th>
                                        <th class="p-3 whitespace-nowrap">Date</th>
                                        <th class="p-3">Created By</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 text-gray-500 bg-white">
                                    @forelse($archivedDealers as $i => $dealer)
                                        <tr class="hover:bg-gray-50">
                                            <td class="p-3">{{ $i + 1 }}.</td>
                                            <td class="p-3 font-semibold text-gray-800 whitespace-nowrap">{{ $dealer->company_name }}</td>
                                            <td class="p-3">{{ ucfirst($dealer->region) }}</td>
                                            <td class="p-3">{{ $dealer->area }}</td>
                                            <td class="p-3 whitespace-nowrap">{{ $dealer->created_at->format('d M Y') }}</td>
                                            <td class="p-3">{{ $dealer->created_by }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="p-4 text-center text-gray-400">Nothing archived.</td></tr>
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