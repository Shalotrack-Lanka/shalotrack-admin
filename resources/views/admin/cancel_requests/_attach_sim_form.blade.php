{{-- Shown for a device registered without a SIM. A tracker with no SIM cannot report, and Stock
     Transfer / the customer bind form both need one, so the admin gives it a spare Activated SIM. --}}
@if($spareSims->isEmpty())
    <span data-no-spare-sim class="block text-[10px] font-normal text-amber-600">
        No SIM &middot; none spare.
        <a href="{{ route('admin.add-sim') }}" class="underline font-bold">Add / activate a SIM</a>
    </span>
@else
    <form action="{{ route('admin.cancel_device.attach_sim', $device->shdevice_id) }}" method="POST"
          data-attach-sim-form
          onsubmit="return confirm('Put the selected SIM into device {{ $device->imei_number }}? The SIM leaves the spare pool.');"
          class="flex items-center gap-1.5">
        @csrf
        @method('PATCH')
        <select name="sim_number" required
                class="rounded-lg border-gray-300 text-[11px] h-8 py-0 shadow-sm">
            <option value="" disabled selected>No SIM &mdash; pick one</option>
            @foreach($spareSims as $sim)
                <option value="{{ $sim->sim_number }}">{{ $sim->sim_number }}@if($sim->sim_type) ({{ $sim->sim_type }})@endif</option>
            @endforeach
        </select>
        <button type="submit"
                class="px-2.5 h-8 rounded-lg bg-[#17a2b8] hover:bg-[#138496] text-white text-[11px] font-bold">
            Attach
        </button>
    </form>
@endif
