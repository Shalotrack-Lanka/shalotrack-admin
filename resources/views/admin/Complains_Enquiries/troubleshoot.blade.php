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


        <main class="p-4 md:p-6 flex-1">
            @yield('content')



             <div class="w-full space-y-6" x-data="{ activeTab: 'details', searchType: 'imei', searchText: '' }">

                    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-4 w-full">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                            
                            <div class="flex flex-wrap items-center gap-3 flex-1">
                                <button class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold py-2 px-4 rounded-lg shadow-sm transition flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                                    </svg>
                                    Back
                                </button>

                                <div class="w-full sm:w-64">
                                    <select x-model="searchType" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                        <option value="imei">Search By Device IMEI</option>
                                        <option value="sim">Search By Sim Serial No.</option>
                                        <option value="mobile_assigned">Search By Assigned Mobile No.</option>
                                        <option value="mobile_reg">Search By Reg. User Mobile No.</option>
                                    </select>
                                </div>

                                <div class="w-full sm:flex-1 max-w-md">
                                    <input type="text" x-model="searchText" :placeholder="'Enter ' + (searchType === 'imei' ? 'Device IMEI' : searchType === 'sim' ? 'SIM Serial Number' : 'Mobile Number') + '...'"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                </div>

                                <button class="bg-gray-800 hover:bg-gray-900 text-white text-sm font-semibold h-10 px-5 rounded-lg shadow-sm transition flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                    </svg>
                                    Search
                                </button>
                            </div>

                            <div class="text-right text-xs space-y-1 font-medium lg:border-l lg:pl-4 border-gray-200">
                                <div class="text-red-600">Total Offline Device : <span class="font-bold text-sm">9416</span></div>
                                <div class="text-orange-500">Information Access Limit = ( Allowed - Used ) = Remain = ( 50 - 13 ) = <span class="font-bold text-sm">37</span></div>
                            </div>

                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                        
                        <div class="flex flex-wrap border-b border-gray-200 bg-gray-50 px-2 pt-2">
                            <button @click="activeTab = 'details'" :class="activeTab === 'details' ? 'bg-white border-t border-x border-gray-200 text-blue-600 font-bold' : 'text-gray-600 hover:bg-gray-100'" 
                                class="px-5 py-3 text-sm font-semibold rounded-t-lg transition duration-150 -mb-[1px]">
                                Device Details
                            </button>
                            <button @click="activeTab = 'management'" :class="activeTab === 'management' ? 'bg-white border-t border-x border-gray-200 text-blue-600 font-bold' : 'text-gray-600 hover:bg-gray-100'"
                                class="px-5 py-3 text-sm font-semibold rounded-t-lg transition duration-150 -mb-[1px]">
                                Device List/Management
                            </button>
                            <button @click="activeTab = 'command'" :class="activeTab === 'command' ? 'bg-white border-t border-x border-gray-200 text-blue-600 font-bold' : 'text-gray-600 hover:bg-gray-100'"
                                class="px-5 py-3 text-sm font-semibold rounded-t-lg transition duration-150 -mb-[1px]">
                                Command Console
                            </button>
                            <button @click="activeTab = 'application'" :class="activeTab === 'application' ? 'bg-white border-t border-x border-gray-200 text-blue-600 font-bold' : 'text-gray-600 hover:bg-gray-100'"
                                class="px-5 py-3 text-sm font-semibold rounded-t-lg transition duration-150 -mb-[1px]">
                                Application Console
                            </button>
                            <button @click="activeTab = 'fuel'" :class="activeTab === 'fuel' ? 'bg-white border-t border-x border-gray-200 text-blue-600 font-bold' : 'text-gray-600 hover:bg-gray-100'"
                                class="px-5 py-3 text-sm font-semibold rounded-t-lg transition duration-150 -mb-[1px]">
                                Fuel Input
                            </button>
                        </div>

                        <div class="p-6 bg-white min-h-[400px]">
                            
                            <div x-show="activeTab === 'details'" x-transition class="space-y-6 w-full">
                                
                                <div class="w-full h-48 bg-gray-100 border border-dashed border-gray-300 rounded-xl flex items-center justify-center text-gray-400 text-sm">
                                    Search result diagnostics overview canvas will populate here...
                                </div>

                                <div class="border border-gray-200 rounded-xl overflow-hidden shadow-sm w-full">
                                    <div class="bg-gray-50 border-b border-gray-200 px-4 py-3 font-semibold text-sm text-gray-700 text-center">
                                        Configure Port
                                    </div>
                                    
                                    <div class="p-6 max-w-2xl mx-auto space-y-4">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
                                            <label for="cfg_imei" class="text-sm font-semibold text-gray-700 md:text-right">IMEI</label>
                                            <div class="md:col-span-2">
                                                <input type="text" id="cfg_imei" name="imei" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
                                            <label for="lt_device_type" class="text-sm font-semibold text-gray-700 md:text-right">LT Device Type</label>
                                            <div class="md:col-span-2">
                                                <select id="lt_device_type" name="lt_device_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                                    <option value="" selected disable>Select</option>
                                                    <option value="APM-AIS140">APM-AIS140</option>
                                                    <option value="Atlanta Personal">Atlanta Personal</option>
                                                    <option value="BMS EDS">BMS EDS</option>
                                                    <option value="BMS IOT aeidth">BMS IOT aeidth</option>
                                                    <option value="J14 - Vivaan">J14 - Vivaan</option>
                                                    <option value="LT02 - ODB New">LT02 - ODB New</option>
                                                    <option value="LT02 - Prima - Black Connector - 4 Wires">LT02 - Prima - Black Connector - 4 Wires</option>
                                                    <option value="LT02 - Prima - Black Connector - 8 Wires">LT02 - Prima - Black Connector - 8 Wires</option>
                                                    <option value="LT02 - Prima - White Connector - 8 Wires">LT02 - Prima - White Connector - 8 Wires</option>
                                                    <option value="LT02 - Prima Fuel - White Connector - 12 Wires">LT02 - Prima Fuel - White Connector - 12 Wires</option>
                                                    <option value="LT04 - GT800-EmbededSIM">LT04 - GT800-EmbededSIM</option>
                                                    <option value="LT04 - HVT001-USB_Charger">LT04 - HVT001-USB_Charger</option>
                                                    <option value="LT04 - WeTrack">LT04 - WeTrack</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
                                            <label for="device_raw_type" class="text-sm font-semibold text-gray-700 md:text-right">Device Raw Type</label>
                                            <div class="md:col-span-2">
                                                <select id="device_raw_type" name="device_raw_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                                    <option value="" selected disable>Select</option>
                                                    <option value="lt04_buzzer_gt800">LT04 - Buzzer - GT800</option>
                                                    <option value="lt04_gt800_embededsim">LT04 - GT800-EmbededSIM</option>
                                                    <option value="lt04_hvt001_usb_charger">LT04 - HVT001-USB_Charger</option>
                                                    <option value="lt04_wetrack">LT04 - WeTrack</option>
                                                    <option value="lt04_wetrack_light">LT04 - WeTrack Light</option>
                                                    <option value="lt04_wetrack_sos">LT04 - WeTrack-SOS</option>
                                                    <option value="lt04_ais140_wetrack140_icat">LT04-AIS140-Wetrack140-ICAT</option>
                                                    <option value="lt04_cycle_lock">LT04-Cycle-Lock</option>
                                                    <option value="lt04_electricbike_v5">LT04-ElectricBike-V5</option>
                                                    <option value="lt04_hotspot">LT04-Hotspot</option>
                                                    <option value="lt04_nic_ais140">LT04-NIC-AIS140</option>
                                                    <option value="lt04_nic_ais140_with_meter">LT04-NIC-AIS140-WITH-METER</option>
                                                    <option value="lt04_obd">LT04-OBD</option>
                                                    <option value="lt04_obd_can">LT04-OBD-CAN</option>
                                                    <option value="lt04_personal">LT04-Personal</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div x-show="activeTab === 'management'" x-transition class="text-center py-12 text-gray-500">
                                <p class="font-medium">Device List and Management utilities window module panel.</p>
                            </div>

                            <div x-show="activeTab === 'command'" x-transition class="text-center py-12 text-gray-500">
                                <p class="font-medium">Direct remote terminal SMS command delivery logs window.</p>
                            </div>

                            <div x-show="activeTab === 'application'" x-transition class="text-center py-12 text-gray-500">
                                <p class="font-medium">Core system data stream listener pipelines console window.</p>
                            </div>

                            <div x-show="activeTab === 'fuel'" x-transition class="text-center py-12 text-gray-500">
                                <p class="font-medium">Fuel tank parameter mapping telemetry tables view panel.</p>
                            </div>

                        </div>
                    </div>
                </div>

        </main>

    </div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

</body>
</html>