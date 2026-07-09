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
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    @forelse($deviceTypes as $type)
                                        <tr>
                                            <td class="p-2.5">{{ $type->id }}</td>
                                            <td class="p-2.5">{{ $type->device_category }}</td>
                                            <td class="p-2.5">{{ $type->model }}</td>
                                            <td class="p-2.5">{{ $type->protocol }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="p-4 text-center text-gray-400">
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

                        <form method="POST" action="{{ route('admin.device-types.store') }}" class="space-y-4">
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
                                <input type="text" name="model" value="{{ old('model') }}"
                                       required
                                       placeholder="e.g. GT06N, TK103"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-xs font-normal focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div>
                                <label class="block mb-1 text-gray-600">Language (Protocol)</label>
                                <input type="text" name="protocol" value="{{ old('protocol') }}"
                                       required
                                       placeholder="e.g. GT06, TCP Binary Protocol V2"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-xs font-normal focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <button type="submit"
                                    class="w-full bg-[#0B1B3F] text-white font-semibold py-2.5 rounded-lg hover:bg-blue-900 transition">
                                Save Device Type
                            </button>
                        </form>

                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

</body>
</html>