@if (session('success'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
         class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex justify-between items-center shadow-sm">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <span class="font-bold text-sm">{{ session('success') }}</span>
        </div>
        <button @click="show = false" class="text-emerald-600 hover:text-emerald-800 font-bold" aria-label="Dismiss">&times;</button>
    </div>
@endif
@if ($errors->any())
    <div x-data="{ show: true }" x-show="show" class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-2xl flex justify-between items-start shadow-sm">
        <div class="space-y-1">
            <p class="font-bold text-sm">Please check the following:</p>
            @foreach ($errors->all() as $e)
                <p class="text-sm">&bull; {{ $e }}</p>
            @endforeach
        </div>
        <button @click="show = false" class="text-red-600 hover:text-red-800 font-bold" aria-label="Dismiss">&times;</button>
    </div>
@endif
