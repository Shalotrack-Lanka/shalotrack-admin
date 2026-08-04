@extends('layouts.supplier')

@section('title', 'My Profile')

@section('content')

@if(session('success'))
    <div class="mb-6 bg-green-50 border border-green-200 text-green-700 rounded-2xl px-5 py-3 text-sm font-semibold">
        {{ session('success') }}
    </div>
@endif

@if($errors->any())
    <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-3 text-sm font-semibold">
        <ul class="list-disc pl-5 space-y-1">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(!$supplier)
    <div class="bg-amber-50 border border-amber-200 rounded-3xl p-6 text-amber-700 text-sm">
        Your account isn't linked to a supplier record yet — contact an administrator.
    </div>
@else

<form method="POST" action="{{ route('supplier.profile.update') }}" class="grid grid-cols-1 lg:grid-cols-2 gap-8">
    @csrf
    @method('PUT')

    <!-- Company Details -->
    <div class="bg-white p-7 rounded-3xl border border-slate-200 shadow-sm">
        <h2 class="font-extrabold text-blue-950 text-base mb-6 uppercase tracking-wider">Company Details</h2>

        <div class="space-y-4">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Your Name (login display name)</label>
                <input type="text" name="full_name" value="{{ old('full_name', $admin->full_name) }}"
                       class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Company Name</label>
                <input type="text" name="name" value="{{ old('name', $supplier->name) }}"
                       class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Email</label>
                <input type="email" name="email" value="{{ old('email', $supplier->email) }}"
                       class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Phone</label>
                <input type="text" name="phone_number" value="{{ old('phone_number', $supplier->phone_number) }}"
                       class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Website</label>
                <input type="text" name="website" value="{{ old('website', $supplier->website) }}"
                       class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Country</label>
                    <input type="text" name="country" value="{{ old('country', $supplier->country) }}"
                           class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">State / Region</label>
                    <input type="text" name="state" value="{{ old('state', $supplier->state) }}"
                           class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Address</label>
                <textarea name="address" rows="2"
                          class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">{{ old('address', $supplier->address) }}</textarea>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">GSTIN Number</label>
                <input type="text" name="gstin_number" value="{{ old('gstin_number', $supplier->gstin_number) }}"
                       class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">
            </div>
        </div>
    </div>

    <!-- Password Change -->
    <div class="space-y-8">
        <div class="bg-white p-7 rounded-3xl border border-slate-200 shadow-sm">
            <h2 class="font-extrabold text-blue-950 text-base mb-2 uppercase tracking-wider">Change Password</h2>
            <p class="text-xs text-slate-400 mb-6">Leave blank if you don't want to change your password.</p>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Current Password</label>
                    <input type="password" name="current_password"
                           class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">New Password</label>
                    <input type="password" name="new_password"
                           class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5">Confirm New Password</label>
                    <input type="password" name="new_password_confirmation"
                           class="w-full border border-slate-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-950/10 focus:border-blue-950 outline-none">
                </div>
            </div>
        </div>

        <button type="submit" class="w-full bg-blue-950 hover:bg-blue-900 text-white font-bold py-3.5 rounded-2xl transition-colors shadow-md">
            Save Changes
        </button>
    </div>

</form>

@endif

@endsection