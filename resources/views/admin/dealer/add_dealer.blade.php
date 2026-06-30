<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

</head>
<body x-data="{ sidebarOpen: false }"> <!-- Added Alpine state for Mobile Menu Toggle -->

<div class="flex h-screen overflow-hidden"> <!-- Prevent double scrollbars -->

    @include('partials.sidebars.admin')


    @include('partials.header')


<main class="p-4 md:p-6 flex-1 w-full" x-data="{ 
            currentStep: 1, 
            activeTab: 'active',
            paymentModes: [],
            dealerData: {
                dealerStatus: '', uperChannel: '', companyName: '', contactPerson: '', mobileNo: '', address: '', district: '', country: 'Sri Lanka', state: '', pinCode: '',
                commencementDate: '', email: '', taxPan: '', cstNo: '', vatTin: '', gstPan: '', region: '', area: '', salesPerson: '', priceGroup: '', commissionType: 'Global', commissionGroup: '',
                creditAmount: '0', creditDays: '0', securityDeposit: '0', depositDate: '', deliverToCustomer: false, network: '', userId: '', password: '',
                businessEntity: '', fullDetailsOf: '', ownerName: '', homeAddress: '', qualification: '', ownership: '', involvement: ''
            }
        }">
            
            <div class="mb-5">
                <h1 class="text-xl font-bold text-gray-800">Add Channel (Dealer)</h1>
            </div>

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

                    <form @submit.prevent="alert('Submitted Data: ' + JSON.stringify(dealerData))" class="space-y-4">
                        
                        <div x-show="currentStep === 1" class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Dealer Status</label>
                                <select x-model="dealerData.dealerStatus" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">Select</option>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Upper Channel</label>
                                <select x-model="dealerData.uperChannel" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">Select</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Company/Firm Name</label>
                                <input type="text" x-model="dealerData.companyName" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Contact Person Name</label>
                                <input type="text" x-model="dealerData.contactPerson" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Contact Person Mobile No</label>
                                <input type="text" x-model="dealerData.mobileNo" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Registered Address</label>
                                <textarea x-model="dealerData.address" rows="2" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">District</label>
                                <input type="text" x-model="dealerData.district" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Country</label>
                                <select x-model="dealerData.country" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="Sri Lanka">Sri Lanka</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">State</label>
                                <select x-model="dealerData.state" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">--Select--</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Pin Code</label>
                                <input type="text" x-model="dealerData.pinCode" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                        </div>

                        <div x-show="currentStep === 2" class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Commencement of Business(Date)</label>
                                <input type="date" x-model="dealerData.commencementDate" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">E - Mail Id</label>
                                <input type="email" x-model="dealerData.email" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Income Tax PAN NO</label>
                                <input type="text" x-model="dealerData.taxPan" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">CST NO</label>
                                <input type="text" x-model="dealerData.cstNo" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">VAT TIN NO</label>
                                <input type="text" x-model="dealerData.vatTin" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">GST/PAN NO</label>
                                <input type="text" x-model="dealerData.gstPan" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Region</label>
                                <select x-model="dealerData.region" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">--Select--</option>
                                    <option value="Western">Western</option>
                                    <option value="Eastern">Eastern</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Area</label>
                                <select x-model="dealerData.area" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="No Data">No Data</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Sales Person</label>
                                <select x-model="dealerData.salesPerson" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">--Select--</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Price Group</label>
                                <select x-model="dealerData.priceGroup" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">--Select--</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Commission Type</label>
                                <div class="flex items-center space-x-4 mt-1 text-xs">
                                    <label class="flex items-center space-x-1"><input type="radio" x-model="dealerData.commissionType" value="Global" class="text-cyan-500"> <span>Globle</span></label>
                                    <label class="flex items-center space-x-1"><input type="radio" x-model="dealerData.commissionType" value="Statewise" class="text-cyan-500"> <span>Statewise</span></label>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Commission Group</label>
                                <select x-model="dealerData.commissionGroup" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">--Select--</option>
                                </select>
                            </div>
                        </div>

                        <div x-show="currentStep === 3" class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Credit Amount</label>
                                <input type="number" x-model="dealerData.creditAmount" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Credit Days</label>
                                <input type="number" x-model="dealerData.creditDays" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Security Deposit</label>
                                <input type="number" x-model="dealerData.securityDeposit" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Security Deposit Date</label>
                                <input type="date" x-model="dealerData.depositDate" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Product Deliver To Customer</label>
                                <label class="flex items-center space-x-2 mt-1 text-xs cursor-pointer">
                                    <input type="checkbox" x-model="dealerData.deliverToCustomer" class="rounded border-gray-300 text-cyan-500">
                                    <span>Yes</span>
                                </label>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Best Available Network</label>
                                <select x-model="dealerData.network" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">--Select--</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">User Id</label>
                                <input type="text" x-model="dealerData.userId" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Password</label>
                                <input type="password" x-model="dealerData.password" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                        </div>

                        <div x-show="currentStep === 4" class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Type of Business Entity</label>
                                <select x-model="dealerData.businessEntity" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">Select</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Full Details of</label>
                                <select x-model="dealerData.fullDetailsOf" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                                    <option value="">Select</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Name</label>
                                <input type="text" x-model="dealerData.ownerName" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Home Address & Telephone</label>
                                <textarea x-model="dealerData.homeAddress" rows="3" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Qualification</label>
                                <input type="text" x-model="dealerData.qualification" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Ownership</label>
                                <input type="text" x-model="dealerData.ownership" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Involvement in Firm/Company</label>
                                <input type="text" x-model="dealerData.involvement" class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 focus:outline-none">
                            </div>
                        </div>

                        <div x-show="currentStep === 5" class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1.5">Payment Modes</label>
                                <div class="grid grid-cols-2 gap-2 bg-gray-50 p-2.5 border border-gray-200 rounded-lg text-xs text-gray-700">
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Pay Online" x-model="paymentModes" class="rounded text-cyan-500"> <span>Pay Online</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Cash On Delivery" x-model="paymentModes" class="rounded text-cyan-500"> <span>Cash On Delivery</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Collect Cash" x-model="paymentModes" class="rounded text-cyan-500"> <span>Collect Cash</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Cheque" x-model="paymentModes" class="rounded text-cyan-500"> <span>Cheque</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Payment Pending" x-model="paymentModes" class="rounded text-cyan-500"> <span>Payment Pending</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="IMPS" x-model="paymentModes" class="rounded text-cyan-500"> <span>IMPS</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="NEFT" x-model="paymentModes" class="rounded text-cyan-500"> <span>NEFT</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="RTGS" x-model="paymentModes" class="rounded text-cyan-500"> <span>RTGS</span></label>
                                    <label class="flex items-center space-x-1.5 cursor-pointer"><input type="checkbox" value="Online Payment" x-model="paymentModes" class="rounded text-cyan-500"> <span>Online Payment</span></label>
                                </div>
                            </div>
                            
                            <div class="space-y-2 text-xs">
                                <div>
                                    <label class="block font-semibold text-gray-600 mb-1">Profile Photo</label>
                                    <input type="file" class="w-full text-gray-500 file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-gray-100 hover:file:bg-gray-200">
                                </div>
                                <div>
                                    <label class="block font-semibold text-gray-600 mb-1">Copy of M/A</label>
                                    <input type="file" class="w-full text-gray-500 file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-gray-100 hover:file:bg-gray-200">
                                </div>
                                <div>
                                    <label class="block font-semibold text-gray-600 mb-1">Passport Front Page</label>
                                    <input type="file" class="w-full text-gray-500 file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-gray-100 hover:file:bg-gray-200">
                                </div>
                                <div>
                                    <label class="block font-semibold text-gray-600 mb-1">Passport Last Page</label>
                                    <input type="file" class="w-full text-gray-500 file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:bg-gray-100 hover:file:bg-gray-200">
                                </div>
                            </div>

                            <div class="flex gap-2 pt-3 border-t">
                                <button type="submit" class="bg-cyan-500 hover:bg-cyan-600 text-white font-medium px-4 py-1.5 rounded text-xs transition-colors">Save</button>
                                <button type="reset" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium px-4 py-1.5 rounded text-xs transition-colors">Reset</button>
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
                        <button type="button" 
                                @click="activeTab = 'active'"
                                :class="activeTab === 'active' ? 'border-cyan-500 text-cyan-600 border-b-2 font-semibold' : 'text-gray-400 hover:text-gray-600'"
                                class="pb-2 text-xs transition-all focus:outline-none">
                            Active
                        </button>
                        <button type="button" 
                                @click="activeTab = 'archived'"
                                :class="activeTab === 'archived' ? 'border-cyan-500 text-cyan-600 border-b-2 font-semibold' : 'text-gray-400 hover:text-gray-600'"
                                class="pb-2 text-xs transition-all focus:outline-none">
                            Archived
                        </button>
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
                                        <th class="p-3 text-center w-24">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 text-gray-600 bg-white">
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="p-3">1.</td>
                                        <td class="p-3 font-semibold text-gray-900 whitespace-nowrap">A M T Motors</td>
                                        <td class="p-3">Eastern</td>
                                        <td class="p-3">Akkaraipattu</td>
                                        <td class="p-3">Retailer</td>
                                        <td class="p-3 text-gray-500">LetsTrack(Primary)</td>
                                        <td class="p-3 whitespace-nowrap">22 Mar 2025</td>
                                        <td class="p-3 text-center flex items-center justify-center space-x-1.5">
                                            <button type="button" class="bg-gray-50 hover:bg-gray-200 text-gray-700 px-2 py-0.5 rounded border border-gray-300 text-[11px]">Edit</button>
                                            <button type="button" class="text-red-500 hover:bg-red-50 p-1 rounded"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                        </td>
                                    </tr>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="p-3">2.</td>
                                        <td class="p-3 font-semibold text-gray-900 whitespace-nowrap">A to z direct</td>
                                        <td class="p-3">Western</td>
                                        <td class="p-3">Colombo</td>
                                        <td class="p-3">Retailer</td>
                                        <td class="p-3 text-gray-500">LetsTrack(Primary)</td>
                                        <td class="p-3 whitespace-nowrap">22 Mar 2025</td>
                                        <td class="p-3 text-center flex items-center justify-center space-x-1.5">
                                            <button type="button" class="bg-gray-50 hover:bg-gray-200 text-gray-700 px-2 py-0.5 rounded border border-gray-300 text-[11px]">Edit</button>
                                            <button type="button" class="text-red-500 hover:bg-red-50 p-1 rounded"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                        </td>
                                    </tr>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="p-3">3.</td>
                                        <td class="p-3 font-semibold text-gray-900 whitespace-nowrap">A TO Z Marketing</td>
                                        <td class="p-3">Western</td>
                                        <td class="p-3">Colombo</td>
                                        <td class="p-3">Retailer</td>
                                        <td class="p-3 text-gray-500">LetsTrack(Primary)</td>
                                        <td class="p-3 whitespace-nowrap">22 Mar 2025</td>
                                        <td class="p-3 text-center flex items-center justify-center space-x-1.5">
                                            <button type="button" class="bg-gray-50 hover:bg-gray-200 text-gray-700 px-2 py-0.5 rounded border border-gray-300 text-[11px]">Edit</button>
                                            <button type="button" class="text-red-500 hover:bg-red-50 p-1 rounded"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                        </td>
                                    </tr>
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
                                        <th class="p-3 text-center w-20">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 text-gray-500 bg-white">
                                    <tr class="hover:bg-gray-50">
                                        <td class="p-3">1.</td>
                                        <td class="p-3 font-semibold text-gray-800 whitespace-nowrap">Brilliace Auto care</td>
                                        <td class="p-3">Western</td>
                                        <td class="p-3">Galle</td>
                                        <td class="p-3 whitespace-nowrap">15 Oct 2019</td>
                                        <td class="p-3">LetsTrack(Primary)</td>
                                        <td class="p-3 text-center">
                                            <button type="button" class="text-cyan-600 hover:text-cyan-700 font-semibold hover:underline">Restore</button>
                                        </td>
                                    </tr>
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