<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Add Device Types</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden">

    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

        <main class="p-4 md:p-6 flex-1">
            @yield('content')

            @if(session('import_success_count') !== null)
                <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-xs font-medium">
                    {{ session('import_success_count') }} device type(s) imported successfully.
                </div>
            @endif

            @if(session('import_failures') && count(session('import_failures')) > 0)
                <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-xs font-medium">
                    <p class="font-bold mb-2">{{ count(session('import_failures')) }} row(s) skipped:</p>
                    <ul class="list-disc pl-5 space-y-1">
                        @foreach(session('import_failures') as $failure)
                            <li>Row {{ $failure->row() }}: {{ implode(', ', $failure->errors()) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- BULK IMPORT -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden mb-6">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                    <span class="font-bold text-gray-800 text-sm">Bulk Import Device Types (Excel)</span>
                    <a href="{{ route('admin.device-types.import-template') }}"
                       class="text-xs font-bold text-blue-600 hover:underline">
                        Download Template
                    </a>
                </div>
                <div class="p-5 text-xs font-semibold text-gray-700">
                    <p class="text-gray-400 font-normal mb-3">
                        Columns: <span class="font-mono">device_category, model, protocol, features</span>.
                        Model must be exactly Basic, Plus, or Customize. Features is optional — comma-separated
                        feature names that must already exist (add them via Add Feature first).
                    </p>
                    <form action="{{ route('admin.device-types.import') }}" method="POST" enctype="multipart/form-data" class="flex items-center gap-3">
                        @csrf
                        <input type="file" name="excel_file" accept=".xlsx,.csv" required
                               class="text-xs border border-gray-300 rounded-lg p-2 flex-1">
                        <button type="submit"
                                class="bg-[#0B1B3F] hover:bg-blue-900 text-white px-5 py-2 rounded-lg font-bold shadow-sm transition whitespace-nowrap">
                            Upload &amp; Import
                        </button>
                    </form>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start w-full">

                <!-- LIST: Existing Device Types -->
                <div class="lg:col-span-7 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                        Device Types
                    </div>
                    <div class="p-5">
                        <div class="border border-gray-200 rounded-lg overflow-hidden max-h-[28rem] overflow-y-auto text-xs font-semibold text-gray-700">
                            <table class="w-full text-left border-collapse">
                                <thead class="bg-gray-50 border-b border-gray-200 sticky top-0">
                                    <tr>
                                        <th class="p-2.5">Dev ID</th>
                                        <th class="p-2.5">Device Category</th>
                                        <th class="p-2.5">Model</th>
                                        <th class="p-2.5">Language (Protocol)</th>
                                        <th class="p-2.5">Features</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    @forelse($deviceTypes as $type)
                                        <tr>
                                            <td class="p-2.5">{{ $type->id }}</td>
                                            <td class="p-2.5">{{ $type->device_category }}</td>
                                            <td class="p-2.5">{{ $type->model }}</td>
                                            <td class="p-2.5">{{ $type->protocol }}</td>
                                            <td class="p-2.5">
                                                @if(!empty($type->features) && count($type->features) > 0)

                                                    {{ $features
                                                        ->whereIn('id', $type->features)
                                                        ->pluck('name')
                                                        ->join(', ') }}

                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="p-4 text-center text-gray-400">
                                                No device types added yet.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- FORM: Add New Device Type -->
                <div class="lg:col-span-5 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                        Add New Device Type
                    </div>
                    <div class="p-5 text-xs font-semibold text-gray-700 space-y-4">

                        @if(session('success'))
                            <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-xs font-medium">
                                {{ session('success') }}
                            </div>
                        @endif

                        @if($errors->any())
                            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-xs font-medium">
                                <ul class="list-disc list-inside space-y-1">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('admin.device-types.store') }}" class="space-y-4"
                              x-data="{ model: '{{ old('model', 'Basic') }}' }">
                            @csrf

                            <div>
                                <label class="block mb-1 text-gray-600">Device Category</label>
                                <input type="text" name="device_category" value="{{ old('device_category') }}"
                                       required
                                       placeholder="e.g. GPS Tracker, OBD Device"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-xs font-normal focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div>
                                <label class="block mb-1 text-gray-600">Model</label>
                                <select name="model" x-model="model" required
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-xs font-normal bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="Basic" {{ old('model') === 'Basic' ? 'selected' : '' }}>Basic</option>
                                    <option value="Plus" {{ old('model') === 'Plus' ? 'selected' : '' }}>Plus</option>
                                    <option value="Customize" {{ old('model') === 'Customize' ? 'selected' : '' }}>Customize</option>
                                </select>
                            </div>

                            <div>
                                <label class="block mb-1 text-gray-600">Language (Protocol)</label>
                                <input type="text" name="protocol" value="{{ old('protocol') }}"
                                       required
                                       placeholder="e.g. GT06, TCP Binary Protocol V2"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-xs font-normal focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            {{-- Add Features --}}

                            <div>
                                <label class="block mb-1 text-gray-600">
                                    Add Features
                                </label>

                                <select name="features[]"
                                        multiple
                                        class="w-full border border-gray-300
                                            rounded-lg px-3 py-2
                                            text-xs font-normal bg-white
                                            focus:outline-none
                                            focus:ring-2 focus:ring-blue-500
                                            min-h-[120px]">

                                    @forelse($features as $feature)

                                        <option
                                            value="{{ $feature->id }}"
                                            {{ collect(old('features', []))
                                                ->contains($feature->id) ? 'selected' : '' }}
                                        >
                                            {{ $feature->name }}
                                        </option>

                                    @empty

                                        <option disabled>
                                            No features available
                                        </option>

                                    @endforelse

                                </select>

                                <p class="text-[10px] text-gray-400 mt-1">
                                    Hold Ctrl (Windows) or Command (Mac) to select multiple features.
                                </p>
                            </div>

                            <button type="submit"
                                    class="w-full bg-[#0B1B3F] text-white font-semibold py-2.5 rounded-lg hover:bg-blue-900 transition">
                                Save Device Type
                            </button>
                        </form>

                    </div>
                </div>

                <!-- NEW CARD: Add Features -->
                <div class="lg:col-span-5 lg:col-start-8 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                        Add Feature
                    </div>
                    <div class="p-5 text-xs font-semibold text-gray-700 space-y-4">

                        <form method="POST" action="{{ route('admin.features.store') }}" class="space-y-4">
                            @csrf
                            <div>
                                <label class="block mb-1 text-gray-600">Feature Name</label>
                                <input type="text" name="name" value="{{ old('name') }}"
                                       required
                                       placeholder="e.g. Geofencing, Ignition Alert, Speed Alert"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-xs font-normal focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <button type="submit"
                                    class="w-full bg-gray-100 text-gray-700 font-semibold py-2.5 rounded-lg hover:bg-gray-200 transition border border-gray-300">
                                Add Feature
                            </button>
                        </form>

                        <div class="pt-3 border-t border-gray-100">
                            <p class="text-gray-500 mb-2">Existing Features</p>
                            <div class="flex flex-wrap gap-2">
                                @forelse($features as $feature)
                                    <span class="px-2.5 py-1 bg-gray-100 rounded-full text-gray-600">{{ $feature->name }}</span>
                                @empty
                                    <span class="text-gray-400">None yet.</span>
                                @endforelse
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

</body>
</html>