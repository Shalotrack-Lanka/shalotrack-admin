<div class="inline-flex items-center gap-2 select-none">
    <label class="relative inline-flex h-6 w-12 shrink-0 cursor-pointer overflow-hidden border-2 border-gray-900">
        <input type="checkbox"
               class="peer sr-only"
               {{ $c->cus_status === 'verified' ? 'checked' : '' }}
               @change="toggleStatus('{{ $c->customer_id }}', $event.target.checked)">
        <span class="h-full w-1/2 bg-white transition-colors duration-200 peer-checked:bg-green-500"></span>
        <span class="h-full w-1/2 bg-red-600 transition-colors duration-200 peer-checked:bg-white"></span>
    </label>
    <span class="text-xs font-bold {{ $c->cus_status === 'verified' ? 'text-green-600' : 'text-red-600' }}">
        {{ $c->cus_status === 'verified' ? 'Verified' : 'Not Verified' }}
    </span>
</div>
