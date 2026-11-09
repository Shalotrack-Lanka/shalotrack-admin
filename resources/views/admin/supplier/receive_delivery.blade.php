<!DOCTYPE html>
<html lang="en" id="htmlRoot">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ShaloTrack Admin - Receive Delivery</title>

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

            {{-- All values the script needs are passed as plain data (no comments inside @js). --}}
            <main class="p-4 md:p-6 flex-1 space-y-6"
                x-data="deliveryScan({ postUrl: @js(route('admin.suppliers.deliveries.store', $supplier->id)), max: @js($maxScans) })"
                x-init="init()">

                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-sm font-bold text-gray-800">Receive delivery from {{ $supplier->name }}</h1>
                        <p class="text-xs text-gray-400 mt-1">
                            Scan the IMEI of every device (or the ICCID of every SIM) on the delivery, then save. This records which supplier each unit came from.
                            The stock count changes when you add the delivery to stock on Manage Stock (choose this delivery there; its quantity is the number of scanned units).
                        </p>
                    </div>
                    <a href="{{ route('admin.suppliers') }}" class="text-xs font-bold text-blue-600 hover:underline">&larr; Back to Supplier Management</a>
                </div>

                @if(session('success'))
                    <div class="p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-xs font-bold">{{ session('success') }}</div>
                @endif
                @if($errors->any())
                    <div class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-xs font-bold">
                        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                    </div>
                @endif

                {{-- 1. WHAT IS ARRIVING --}}
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">1. What is arriving</div>
                    <div class="p-5 text-xs font-semibold text-gray-700 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block mb-1">Delivery type</label>
                            <select x-model="kind" :disabled="rows.length > 0" class="w-full rounded-lg border-gray-300 h-10 shadow-sm disabled:bg-gray-100">
                                <option value="DEVICE">Devices (scan IMEI)</option>
                                <option value="SIM">SIM cards (scan ICCID)</option>
                            </select>
                            <p x-show="rows.length > 0" x-cloak class="text-[11px] text-gray-400 mt-1">Clear the list to change the delivery type.</p>
                        </div>
                        <div x-show="kind === 'DEVICE'" x-cloak>
                            <label class="block mb-1">Device Category / Type (applies to the whole delivery)</label>
                            <select x-model="deviceTypeId" :disabled="rows.length > 0" class="w-full rounded-lg border-gray-300 h-10 shadow-sm disabled:bg-gray-100">
                                <option value="">-- Select Device Type --</option>
                                @foreach($deviceTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->device_category }} {{ $type->model }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block mb-1">Supplier delivery note / invoice no. (optional)</label>
                            <input type="text" x-model="reference" maxlength="100" class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                        </div>
                        <div>
                            <label class="block mb-1">Note (optional)</label>
                            <input type="text" x-model="note" maxlength="500" class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                        </div>
                    </div>
                </div>

                {{-- 2. SCAN --}}
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">2. Scan</div>
                    <div class="p-5 text-xs font-semibold text-gray-700 space-y-3">
                        <div>
                            <label class="block mb-1">Scan here <span class="font-normal text-gray-400">(click the box, then pull the scanner trigger)</span></label>
                            <input type="text" x-ref="scanInput" x-model="input" @keydown.enter.prevent="onScan()"
                                :disabled="kind === 'DEVICE' && !deviceTypeId" autocomplete="off" inputmode="numeric"
                                class="w-full rounded-lg border-gray-300 h-11 shadow-sm font-mono text-sm disabled:bg-gray-100"
                                :placeholder="kind === 'DEVICE' ? (deviceTypeId ? 'Scan an IMEI...' : 'Choose the device type first') : 'Scan a SIM ICCID...'">
                        </div>
                        <div x-show="flash.text" x-cloak class="p-3 border rounded-lg text-xs font-bold"
                            :class="flash.ok ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'"
                            x-text="flash.text"></div>
                    </div>
                </div>

                {{-- 3. REVIEW AND SAVE --}}
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                        <span class="font-bold text-gray-800 text-sm">3. Review and save (<span x-text="rows.length"></span> scanned)</span>
                        <button type="button" x-show="rows.length" x-cloak @click="clearAll()" class="text-xs font-bold text-red-600 hover:underline">Clear list</button>
                    </div>
                    <div class="max-h-80 overflow-y-auto">
                        <table class="w-full text-left text-xs">
                            <tbody class="divide-y divide-gray-100 font-mono">
                                <template x-for="(r, i) in rows" :key="r">
                                    <tr>
                                        <td class="px-5 py-2 text-gray-400" x-text="i + 1"></td>
                                        <td class="px-2 py-2" x-text="r"></td>
                                        <td class="px-5 py-2 text-right"><button type="button" @click="remove(r)" class="text-red-600 font-sans font-bold hover:underline">Remove</button></td>
                                    </tr>
                                </template>
                                <tr x-show="!rows.length"><td colspan="3" class="px-5 py-6 text-center text-gray-400 font-sans">Nothing scanned yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-5 flex items-center gap-3 border-t border-gray-100">
                        <button type="button" @click="submit()" :disabled="!canSave"
                            class="bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed text-white px-5 py-2 rounded-lg font-bold shadow-sm text-xs">
                            <span x-show="!saving">Save delivery</span><span x-show="saving" x-cloak>Saving...</span>
                        </button>
                        <span class="text-[11px] text-gray-400">Up to <span x-text="max"></span> scans at a time.</span>
                    </div>
                </div>

                {{-- RESULT OF THE LAST SAVE --}}
                <div x-show="result" x-cloak class="space-y-3">
                    <div class="p-3 border rounded-lg text-xs font-bold"
                        :class="result && result.accepted > 0 ? 'bg-green-50 border-green-200 text-green-700' : 'bg-amber-50 border-amber-200 text-amber-800'">
                        <span x-text="result ? result.accepted : 0"></span> recorded<span x-show="result && result.delivery_id" x-cloak> on delivery #<span x-text="result ? result.delivery_id : ''"></span></span>,
                        <span x-text="result ? result.rejected.length : 0"></span> not recorded.
                    </div>
                    <div x-show="result && result.delivery_id" x-cloak class="text-xs font-bold">
                        <template x-if="kind === 'DEVICE'">
                            <a href="{{ route('admin.stock.manage') }}" class="text-blue-700 hover:underline mr-4">Add this delivery to stock</a>
                        </template>
                        <a href="" class="text-gray-600 hover:underline">Refresh the list below</a>
                    </div>
                    <div x-show="result && result.rejected.length" x-cloak class="bg-white border border-red-200 rounded-xl overflow-hidden">
                        <div class="px-5 py-2 bg-red-50 text-red-700 font-bold text-xs">Not recorded (fix or rescan these)</div>
                        <table class="w-full text-left text-xs">
                            <tbody class="divide-y divide-gray-100">
                                <template x-for="(x, i) in (result ? result.rejected : [])" :key="i">
                                    <tr>
                                        <td class="px-5 py-2 font-mono" x-text="x.identifier"></td>
                                        <td class="px-2 py-2 text-gray-700" x-text="x.reason"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- RECENT DELIVERIES --}}
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">Recent deliveries from {{ $supplier->name }}</div>
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 text-[11px] text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="p-3">#</th>
                                <th class="p-3">Date</th>
                                <th class="p-3">Type</th>
                                <th class="p-3">Units</th>
                                <th class="p-3">Reference</th>
                                <th class="p-3">Status</th>
                                <th class="p-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            @forelse($deliveries as $d)
                                <tr>
                                    <td class="p-3">{{ $d->id }}</td>
                                    <td class="p-3">{{ $d->received_on?->format('Y-m-d') }}</td>
                                    <td class="p-3">{{ $d->kind === 'DEVICE' ? trim(($d->deviceType->device_category ?? '') . ' ' . ($d->deviceType->model ?? '')) : 'SIM cards' }}</td>
                                    <td class="p-3 font-bold">{{ $d->units_count }}</td>
                                    <td class="p-3">{{ $d->reference ?: '-' }}</td>
                                    <td class="p-3">
                                        @if($d->kind === 'SIM')
                                            <span class="text-gray-500">Recorded</span>
                                        @elseif($d->stocked_in_at)
                                            <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 font-bold text-[10px]">In stock</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-bold text-[10px]">Waiting for stock-in</span>
                                        @endif
                                    </td>
                                    <td class="p-3 text-right whitespace-nowrap">
                                        @if($d->kind === 'DEVICE' && ! $d->stocked_in_at)
                                            <a href="{{ route('admin.stock.manage') }}" class="text-blue-700 font-bold hover:underline mr-3">Add to stock</a>
                                        @endif
                                        @if(! $d->stocked_in_at)
                                            <form method="POST" action="{{ route('admin.suppliers.deliveries.void', [$supplier->id, $d->id]) }}" class="inline"
                                                onsubmit="const r = prompt('Why is this delivery being cancelled? (its scans will be removed)'); if (!r || r.trim().length < 3) { return false; } this.reason.value = r.trim(); return true;">
                                                @csrf @method('DELETE')
                                                <input type="hidden" name="reason" value="">
                                                <button type="submit" class="text-red-600 font-bold hover:underline">Cancel</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="p-5 text-center text-gray-400">No deliveries recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>

    <script>
        function deliveryScan(cfg) {
            // 15-digit IMEI check digit (Luhn): instant misread detection.
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
                postUrl: cfg.postUrl,
                max: cfg.max,
                kind: 'DEVICE',
                deviceTypeId: '',
                reference: '',
                note: '',
                input: '',
                rows: [],
                flash: { text: '', ok: false },
                saving: false,
                result: null,

                get canSave() {
                    return !this.saving && this.rows.length > 0 && (this.kind === 'SIM' || !!this.deviceTypeId);
                },

                init() {
                    this.$nextTick(() => this.focusInput());
                },
                focusInput() {
                    this.$nextTick(() => this.$refs.scanInput && !this.$refs.scanInput.disabled && this.$refs.scanInput.focus());
                },
                say(text, ok) {
                    this.flash = { text, ok };
                },
                beep(ok) {
                    try {
                        const ctx = new (window.AudioContext || window.webkitAudioContext)();
                        const o = ctx.createOscillator(), g = ctx.createGain();
                        o.frequency.value = ok ? 880 : 220;
                        g.gain.value = 0.08;
                        o.connect(g);
                        g.connect(ctx.destination);
                        o.start();
                        o.stop(ctx.currentTime + (ok ? 0.08 : 0.35));
                    } catch (e) {}
                },

                onScan() {
                    const v = this.input.replace(/\s+/g, '');
                    this.input = '';
                    if (!v) return;
                    this.result = null;

                    if (this.rows.length >= this.max) {
                        this.say('The list is full (' + this.max + '). Save this delivery, then scan the rest as a new one.', false);
                        return this.beep(false);
                    }
                    if (this.kind === 'DEVICE') {
                        if (!/^\d{15}$/.test(v) || !luhnOk(v)) {
                            this.say(v.slice(0, 24) + ': not a valid IMEI (15 digits, correct check digit). Rescan it.', false);
                            return this.beep(false);
                        }
                    } else if (!/^\d{19,20}$/.test(v)) {
                        this.say(v.slice(0, 24) + ': not a valid SIM ICCID (19 or 20 digits). Rescan it.', false);
                        return this.beep(false);
                    }
                    if (this.rows.includes(v)) {
                        this.say(v + ' is already in this list.', false);
                        return this.beep(false);
                    }

                    this.rows.push(v);
                    this.say('Added ' + v + ' (' + this.rows.length + ' scanned).', true);
                    this.beep(true);
                    this.focusInput();
                },
                remove(v) {
                    this.rows = this.rows.filter(r => r !== v);
                    this.focusInput();
                },
                clearAll() {
                    this.rows = [];
                    this.say('', true);
                    this.focusInput();
                },

                async submit() {
                    if (!this.canSave) return;
                    this.saving = true;
                    try {
                        const res = await fetch(this.postUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({
                                kind: this.kind,
                                device_type_id: this.kind === 'DEVICE' ? Number(this.deviceTypeId) : null,
                                reference: this.reference || null,
                                note: this.note || null,
                                scans: this.rows,
                            }),
                        });

                        if (res.status === 419) throw new Error('Your session expired. Reload the page and scan again.');
                        if (res.status === 403) throw new Error('You do not have permission to do this.');
                        if (res.status === 404) throw new Error('Delivery scanning is switched off.');
                        if (res.status === 429) throw new Error('Too many requests. Wait a moment and try again.');
                        const data = await res.json().catch(() => ({}));
                        if (res.status === 422) {
                            const first = data.errors ? Object.values(data.errors).flat()[0] : null;
                            throw new Error(first || data.message || 'The delivery was not accepted.');
                        }
                        if (!res.ok) throw new Error('Server error (' + res.status + '). Nothing was confirmed; check recent deliveries before retrying.');

                        this.result = data;
                        this.rows = [];
                        this.say('', true);
                        this.beep(data.accepted > 0);
                    } catch (e) {
                        this.say(e.message, false);
                        this.beep(false);
                    } finally {
                        this.saving = false;
                        this.focusInput();
                    }
                },
            };
        }
    </script>
</body>

</html>
