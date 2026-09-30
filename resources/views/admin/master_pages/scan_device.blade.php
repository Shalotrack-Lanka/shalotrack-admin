<!DOCTYPE html>
<html lang="en" id="htmlRoot">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ShaloTrack Admin - Scan Device Intake</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <style>
        [x-cloak] {
            display: none !important
        }
    </style>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>

<body x-data="{ sidebarOpen: false }">

    <div class="flex h-screen overflow-hidden">

        @include('partials.sidebars.admin')

        <div class="flex-1 flex flex-col overflow-y-auto">
            @include('partials.header')

            <main class="p-4 md:p-6 flex-1 space-y-6"
                x-data="scanIntake({
                  checkUrl:  @js(route('admin.device.scan.check')),
                  commitUrl: @js(route('admin.device.scan.commit')),
                  listUrl:   @js(route('admin.setup-device')),
                  simUrl:    @js(route('admin.device.scan.sim')),
              })"
                x-init="init()">

                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-sm font-bold text-gray-800">Scan Device Intake</h1>
                        <p class="text-xs text-gray-400 mt-1">
                            Scan each device's IMEI barcode, then its SIM barcode (if it has one). Review the list, then register the batch.
                        </p>
                    </div>
                    <a href="{{ route('admin.setup-device') }}" class="text-xs font-bold text-blue-600 hover:underline">&larr; Back to Setup Device</a>
                </div>

                <!-- SCAN PANEL -->
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">1. Choose device type &amp; scan</div>
                    <div class="p-5 text-xs font-semibold text-gray-700 space-y-4">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block mb-1">Device Category / Type (applies to every device in this batch)</label>
                                <select x-model="deviceTypeId" @change="save(); focusInput()"
                                    class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                                    <option value="">-- Select Device Type --</option>
                                    @foreach($deviceTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->device_category }} {{ $type->model }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="block mb-1">Scan here <span class="font-normal text-gray-400">(click the box, then pull the scanner trigger)</span></label>
                                <input type="text" x-ref="scanInput" x-model="input"
                                    @keydown.enter.prevent="onScan()"
                                    :disabled="!deviceTypeId || committing || simForm.open"
                                    autocomplete="off" autocapitalize="off" spellcheck="false" inputmode="numeric"
                                    placeholder="Waiting for a scan…"
                                    class="w-full rounded-lg border-gray-300 h-10 shadow-sm font-mono disabled:bg-gray-100">
                            </div>
                        </div>

                        <!-- in-line feedback (no toasts/popups) -->
                        <div x-show="flash.text" x-cloak
                            :class="busy ? 'bg-gray-50 border-gray-200 text-gray-600' : (flash.ok ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700')"
                            class="p-3 border rounded-lg text-xs font-bold flex items-center gap-2">
                            <svg x-show="busy" x-cloak class="w-4 h-4 animate-spin shrink-0" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            <span x-text="flash.text"></span>
                        </div>
                        <!-- QUICK-ADD SIM (opens when a scanned ICCID isn't registered) -->
                        <div x-show="simForm.open" x-cloak class="border border-blue-200 bg-blue-50/40 rounded-xl p-4 space-y-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-bold text-gray-800 text-sm">Register this SIM now</p>
                                    <p class="font-normal text-gray-500 mt-0.5">
                                        For a whole box of SIMs, import the carrier's list instead:
                                        <a href="{{ route('admin.add-sim') }}" target="_blank" rel="noopener" class="text-blue-600 font-bold hover:underline">Bulk SIM import (opens Add SIM)</a>.
                                    </p>
                                </div>
                                <button type="button" @click="closeSimForm()" :disabled="simForm.saving" class="text-gray-400 hover:text-gray-700 font-bold">Cancel</button>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block mb-1">ICCID (scanned)</label>
                                    <input type="text" x-model="simForm.iccid" readonly class="w-full rounded-lg border-gray-300 h-10 shadow-sm font-mono bg-gray-100">
                                    <p class="text-red-600 mt-1" x-text="simForm.errors.iccid"></p>
                                </div>
                                <div>
                                    <label class="block mb-1">SIM Number (10 digits)</label>
                                    <input type="text" x-ref="simNumberInput" x-model="simForm.sim_number" maxlength="10" inputmode="numeric" autocomplete="off"
                                        @input="simForm.sim_number = simForm.sim_number.replace(/\D/g, '')"
                                        class="w-full rounded-lg border-gray-300 h-10 shadow-sm font-mono">
                                    <p class="text-red-600 mt-1" x-text="simForm.errors.sim_number"></p>
                                </div>
                                <div>
                                    <label class="block mb-1">IMSI (15 digits)</label>
                                    <input type="text" x-model="simForm.imsi" maxlength="15" inputmode="numeric" autocomplete="off"
                                        @input="simForm.imsi = simForm.imsi.replace(/\D/g, '')"
                                        class="w-full rounded-lg border-gray-300 h-10 shadow-sm font-mono">
                                    <p class="text-red-600 mt-1" x-text="simForm.errors.imsi"></p>
                                </div>
                                <div>
                                    <label class="block mb-1">SIM Type</label>
                                    <input type="text" x-model="simForm.sim_type" list="simTypeList" maxlength="255" autocomplete="off"
                                        class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                                    <datalist id="simTypeList">
                                        @foreach($simTypes as $simType)
                                        <option value="{{ $simType }}"></option>
                                        @endforeach
                                    </datalist>
                                    <p class="text-red-600 mt-1" x-text="simForm.errors.sim_type"></p>
                                </div>
                            </div>

                            <div>
                                <label class="flex items-start gap-2 cursor-pointer">
                                    <input type="checkbox" x-model="simForm.confirm" class="mt-0.5 rounded border-gray-300">
                                    <span>I confirm the carrier has <strong>activated</strong> this SIM. It will be saved as Activated and paired to the device.</span>
                                </label>
                                <p class="text-red-600 mt-1" x-text="simForm.errors.confirm_activated"></p>
                            </div>

                            <p x-show="simForm.errorText" x-cloak class="text-red-600 font-bold" x-text="simForm.errorText"></p>

                            <div class="flex items-center gap-2">
                                <button type="button" @click="saveSim()" :disabled="simForm.saving || !simForm.confirm"
                                    class="bg-[#17a2b8] hover:bg-[#138496] disabled:opacity-40 disabled:cursor-not-allowed text-white px-5 py-2 rounded-lg font-bold shadow-sm transition inline-flex items-center gap-2">
                                    <svg x-show="simForm.saving" x-cloak class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                    <span x-text="simForm.saving ? 'Saving…' : 'Save SIM & pair'"></span>
                                </button>
                            </div>
                        </div>

                        <p x-show="!deviceTypeId" x-cloak class="text-gray-400 font-normal">Select a device type to enable scanning.</p>
                    </div>
                </div>

                <!-- BATCH -->
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                        <span class="font-bold text-gray-800 text-sm">2. Review batch
                            (<span x-text="rows.length"></span> device<span x-show="rows.length !== 1">s</span>)</span>
                        <button type="button" @click="clearAll()" x-show="rows.length" x-cloak :disabled="committing"
                            class="text-xs font-bold text-gray-500 hover:text-red-600">Clear all</button>
                    </div>

                    <div x-show="noSimCount > 0" x-cloak class="mx-5 mt-4 p-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg text-xs font-bold">
                        <span x-text="noSimCount"></span> device<span x-show="noSimCount !== 1">s have</span><span x-show="noSimCount === 1"> has</span> no SIM.
                        A device without a SIM can't be transferred to a dealer or given to a customer until a spare SIM is attached
                        (Cancel Device page). To avoid that step, scan the missing SIM barcodes before registering.
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
                                <tr>
                                    <th class="px-5 py-2">#</th>
                                    <th class="px-5 py-2">IMEI</th>
                                    <th class="px-5 py-2">SIM ICCID</th>
                                    <th class="px-5 py-2">SIM Number</th>
                                    <th class="px-5 py-2">Status</th>
                                    <th class="px-5 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-700">
                                <template x-for="(r, i) in rows" :key="r.imei">
                                    <tr :class="r.error ? 'bg-red-50' : ''">
                                        <td class="px-5 py-2" x-text="i + 1"></td>
                                        <td class="px-5 py-2 font-mono" x-text="r.imei"></td>
                                        <td class="px-5 py-2 font-mono">
                                            <span x-show="!r.checking" x-text="r.iccid || '-'"></span>
                                            <span x-show="r.checking" x-cloak class="inline-flex items-center gap-1.5 text-gray-400 font-sans">
                                                <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                </svg>
                                                Checking…
                                            </span>
                                        </td>
                                        <td class="px-5 py-2" x-text="r.simNumber || '-'"></td>
                                        <td class="px-5 py-2">
                                            <span x-show="r.pending" x-cloak class="inline-flex items-center gap-1.5 text-gray-500 font-bold">
                                                <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                </svg>
                                                Checking…
                                            </span>
                                            <span x-show="!r.error && !r.pending" class="text-green-700 font-bold">Ready</span>
                                            <span x-show="r.error" class="text-red-700 font-bold" x-text="r.error"></span>
                                        </td>
                                        <td class="px-5 py-2 text-right">
                                            <button type="button" @click="removeRow(i)" :disabled="committing || r.pending || r.checking"
                                                class="text-gray-400 hover:text-red-600 font-bold disabled:opacity-40 disabled:cursor-not-allowed">Remove</button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="!rows.length" x-cloak>
                                    <td colspan="6" class="px-5 py-6 text-center text-gray-400">Nothing scanned yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-5 flex items-center gap-3 border-t border-gray-100">
                        <button type="button" @click="commit()" :disabled="!canCommit"
                            class="bg-[#17a2b8] hover:bg-[#138496] disabled:opacity-40 disabled:cursor-not-allowed text-white px-5 py-2 rounded-lg text-xs font-bold shadow-sm transition">
                            <span x-show="!committing">Register <span x-text="rows.length"></span> device<span x-show="rows.length !== 1">s</span></span>
                            <span x-show="committing" x-cloak class="inline-flex items-center gap-2">
                                <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                Registering…
                            </span>
                        </button>
                        <span class="text-xs text-gray-400">Uses one unit of Company Available Stock per device.</span>
                    </div>
                </div>

                <!-- RESULT -->
                <div x-show="doneCount > 0" x-cloak class="p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-xs font-bold">
                    <span x-text="doneCount"></span> device(s) registered in this session.
                    <a :href="listUrl" class="underline ml-2">View Setup Devices</a>
                </div>
            </main>
        </div>
    </div>

    <script>
        function scanIntake(cfg) {
            const DRAFT_KEY = 'shalotrack.scanIntake.draft.v1';

            // 15-digit IMEI check digit (Luhn) — instant misread detection.
            function luhnOk(s) {
                let sum = 0;
                for (let i = 0; i < s.length; i++) {
                    let d = +s[s.length - 1 - i];
                    if (i % 2 === 1) {
                        d *= 2;
                        if (d > 9) d -= 9;
                    }
                    sum += d;
                }
                return sum % 10 === 0;
            }

            return {
                checkUrl: cfg.checkUrl,
                commitUrl: cfg.commitUrl,
                listUrl: cfg.listUrl,
                simUrl: cfg.simUrl,
                deviceTypeId: '',
                input: '',
                rows: [],
                flash: {
                    text: '',
                    ok: false
                },
                committing: false,
                doneCount: 0,
                busy: false,
                queue: [],
                simForm: {
                    open: false,
                    saving: false,
                    iccid: '',
                    imei: '',
                    sim_number: '',
                    imsi: '',
                    sim_type: '',
                    confirm: false,
                    errors: {},
                    errorText: ''
                },

                // A row is "pending" while its server check is in flight.
                get hasPending() {
                    return this.rows.some(r => r.pending || r.checking);
                },
                get canCommit() {
                    return !this.committing && !this.hasPending && this.rows.length > 0 && !!this.deviceTypeId;
                },
                get noSimCount() {
                    return this.rows.filter(r => !r.iccid).length;
                },

                init() {
                    try {
                        const d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null');
                        if (d && Array.isArray(d.rows)) {
                            this.rows = d.rows;
                            this.deviceTypeId = d.deviceTypeId || '';
                            if (this.rows.length) this.say('Restored an unsaved batch of ' + this.rows.length + ' scanned device(s).', true);
                        }
                    } catch (e) {
                        /* storage unavailable: page works without a draft */ }
                    this.$nextTick(() => this.focusInput());
                },

                save() {
                    // Never persist half-checked rows, or a restored draft could get stuck "Checking…".
                    const settled = this.rows.filter(r => !r.pending).map(r => ({
                        ...r,
                        checking: false
                    }));
                    try {
                        localStorage.setItem(DRAFT_KEY, JSON.stringify({
                            deviceTypeId: this.deviceTypeId,
                            rows: settled
                        }));
                    } catch (e) {}
                },
                focusInput() {
                    this.$nextTick(() => this.$refs.scanInput && !this.$refs.scanInput.disabled && this.$refs.scanInput.focus());
                },

                say(text, ok) {
                    this.flash = {
                        text,
                        ok
                    };
                },
                fail(text) {
                    this.say(text, false);
                    this.beep(false);
                },

                beep(ok) {
                    try {
                        const ctx = new(window.AudioContext || window.webkitAudioContext)();
                        const o = ctx.createOscillator(),
                            g = ctx.createGain();
                        o.frequency.value = ok ? 880 : 220;
                        g.gain.value = 0.08;
                        o.connect(g);
                        g.connect(ctx.destination);
                        o.start();
                        o.stop(ctx.currentTime + (ok ? 0.08 : 0.35));
                    } catch (e) {}
                },

                async post(url, body) {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify(body),
                    });
                    if (res.status === 419) throw new Error('Your session expired — reload the page (your scanned list is saved).');
                    if (res.status === 403) throw new Error('You do not have permission to do this.');
                    if (res.status === 429) throw new Error('Too many requests — wait a moment and try again.');
                    if (!res.ok && res.status !== 422) throw new Error('Server error (' + res.status + '). Try again.');
                    return {
                        status: res.status,
                        data: await res.json()
                    };
                },

                // Scanners send Enter after each barcode; scans can arrive faster than
                // the network, so process them strictly one at a time, in order.
                onScan() {
                    const v = this.input.replace(/\s+/g, '');
                    this.input = '';
                    if (!v) return;
                    this.queue.push(v);
                    this.drain();
                },
                async drain() {
                    if (this.busy) return;
                    this.busy = true;
                    while (this.queue.length) {
                        await this.handle(this.queue.shift());
                    }
                    this.busy = false;
                    this.focusInput();
                },

                async handle(v) {
                    try {
                        if (/^\d{15}$/.test(v)) return await this.handleImei(v);
                        if (/^\d{19,20}$/.test(v)) return await this.handleIccid(v);
                        this.fail('Unrecognised barcode "' + v.slice(0, 24) + '". Expected a 15-digit IMEI or a 19–20 digit SIM number.');
                    } catch (e) {
                        this.fail(e.message);
                    }
                },

                async handleImei(imei) {
                    if (!luhnOk(imei)) return this.fail(imei + ': invalid check digit — likely a misread. Rescan.');
                    if (this.rows.some(r => r.imei === imei)) return this.fail(imei + ' is already in this batch.');

                    // Show the row immediately as "Checking…"; settle it when the server answers.
                    this.rows.push({
                        imei,
                        iccid: null,
                        simNumber: null,
                        error: null,
                        pending: true,
                        checking: false
                    });
                    this.say('Checking ' + imei + '…', true);
                    const drop = () => {
                        this.rows = this.rows.filter(r => r.imei !== imei);
                    };

                    let data;
                    try {
                        ({
                            data
                        } = await this.post(this.checkUrl, {
                            imei
                        }));
                    } catch (e) {
                        drop();
                        throw e;
                    }

                    if (data.imei && !data.imei.ok) {
                        drop();
                        this.save();
                        return this.fail(imei + ': ' + data.imei.message);
                    }

                    const row = this.rows.find(r => r.imei === imei);
                    if (row) row.pending = false; // (row is gone if the operator removed it meanwhile)
                    this.save();
                    this.beep(true);
                    this.say('Added ' + imei + '. Scan its SIM barcode next, or the next IMEI.', true);
                },

                async handleIccid(iccid) {
                    const target = [...this.rows].reverse().find(r => !r.iccid);
                    if (!target || this.rows[this.rows.length - 1].iccid) {
                        return this.fail('Scan the device IMEI first, then its SIM.');
                    }
                    if (this.rows.some(r => r.iccid === iccid)) return this.fail('This SIM is already in this batch.');
                    target.checking = true;
                    this.say('Checking SIM ' + iccid + '…', true);

                    let data;
                    try {
                        ({
                            data
                        } = await this.post(this.checkUrl, {
                            iccid
                        }));
                    } catch (e) {
                        target.checking = false;
                        throw e;
                    }

                    target.checking = false;
                    if (data.iccid && !data.iccid.ok) {
                        if (data.iccid.reason === 'not_registered') {
                            this.openSimForm(iccid, target.imei);
                            return this.fail('SIM ' + iccid + ' is not registered yet — fill in the form below to add it, or cancel.');
                        }
                        return this.fail('SIM ' + iccid + ': ' + data.iccid.message);
                    }
                    target.iccid = iccid;
                    target.simNumber = data.iccid.sim_number;
                    this.save();
                    this.beep(true);
                    this.say('SIM ' + data.iccid.sim_number + ' paired with ' + target.imei + '.', true);
                },

                openSimForm(iccid, imei) {
                    this.simForm = {
                        open: true,
                        saving: false,
                        iccid,
                        imei,
                        sim_number: '',
                        imsi: '',
                        sim_type: '',
                        confirm: false,
                        errors: {},
                        errorText: ''
                    };
                    this.queue = []; // drop scans queued behind it; they'd pair to the wrong device
                    this.$nextTick(() => this.$refs.simNumberInput && this.$refs.simNumberInput.focus());
                },
                closeSimForm() {
                    this.simForm.open = false;
                    this.say('', true);
                    this.focusInput();
                },
                async saveSim() {
                    const f = this.simForm;
                    if (f.saving || !f.confirm) return;
                    f.saving = true;
                    f.errors = {};
                    f.errorText = '';
                    try {
                        const {
                            status,
                            data
                        } = await this.post(this.simUrl, {
                            iccid: f.iccid,
                            sim_number: f.sim_number,
                            imsi: f.imsi,
                            sim_type: f.sim_type,
                            confirm_activated: f.confirm,
                        });
                        if (status === 422) {
                            const errs = data.errors || {};
                            f.errors = Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0]]));
                            if (!Object.keys(f.errors).length) f.errorText = data.message || 'The SIM was rejected.';
                            return;
                        }
                        // Saved as Activated: pair it to the device it was scanned for (if that row still exists).
                        const row = this.rows.find(r => r.imei === f.imei);
                        if (row && !row.iccid) {
                            row.iccid = f.iccid;
                            row.simNumber = data.sim_number;
                        }
                        this.save();
                        this.beep(true);
                        this.say('SIM ' + data.sim_number + ' registered' + (row ? ' and paired with ' + f.imei + '.' : '.'), true);
                        this.simForm.open = false;
                        this.focusInput();
                    } catch (e) {
                        f.errorText = e.message;
                    } finally {
                        f.saving = false;
                    }
                },

                removeRow(i) {
                    this.rows.splice(i, 1);
                    this.save();
                    this.focusInput();
                },
                clearAll() {
                    this.rows = [];
                    this.save();
                    this.say('', true);
                    this.focusInput();
                },

                async commit() {
                    if (!this.canCommit) return;
                    this.committing = true;
                    this.say('', true);
                    try {
                        const payload = {
                            device_type_id: this.deviceTypeId,
                            rows: this.rows.map(r => ({
                                imei: r.imei,
                                iccid: r.iccid
                            }))
                        };
                        const {
                            status,
                            data
                        } = await this.post(this.commitUrl, payload);
                        if (status === 422) {
                            this.fail(Object.values(data.errors || {}).flat()[0] || 'The batch was rejected.');
                            return;
                        }
                        const failed = {};
                        data.results.forEach(r => {
                            if (!r.ok) failed[r.imei] = r.message;
                        });
                        this.rows = this.rows.filter(r => failed[r.imei]).map(r => ({
                            ...r,
                            error: failed[r.imei]
                        }));
                        this.doneCount += data.created;
                        this.save();
                        const syncNote = data.sync_failed ?
                            ' ' + data.sync_failed + ' device(s) are saved but could not be synced to the app server just now — ask your developer to re-run devices:backfill.' :
                            '';
                        if (data.failed) this.fail(data.created + ' registered, ' + data.failed + ' need attention (shown in red). Remove them or fix and rescan.' + syncNote);
                        else if (syncNote) this.fail(data.created + ' device(s) registered.' + syncNote);
                        else {
                            this.say(data.created + ' device(s) registered successfully.', true);
                            this.beep(true);
                        }
                    } catch (e) {
                        this.fail(e.message);
                    } finally {
                        this.committing = false;
                        this.focusInput();
                    }
                },
            };
        }
    </script>
</body>

</html>