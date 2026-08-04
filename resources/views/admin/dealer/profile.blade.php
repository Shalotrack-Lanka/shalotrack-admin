@extends('layouts.dealer')

@section('content')

<div class="max-w-6xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    
    <!-- Main Profile Card -->
    <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-950 to-blue-900 border-b-4 border-orange-500 p-6 sm:p-8 flex items-center gap-4 relative overflow-hidden">
            <div class="relative z-10 flex items-center gap-4">
                <div class="bg-white/10 p-3 rounded-2xl backdrop-blur-sm border border-white/20">
                    <i class="fas fa-user-circle text-3xl text-orange-400"></i>
                </div>
                <div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">My Profile</h2>
                    <p class="text-blue-200 text-sm mt-1">Manage your personal information and security settings</p>
                </div>
            </div>
            <!-- Background Decoration -->
            <div class="absolute right-0 top-0 opacity-10 transform translate-x-1/4 -translate-y-1/4">
                <i class="fas fa-id-badge" style="font-size: 12rem;"></i>
            </div>
        </div>

        <div class="p-6 sm:p-8 md:p-10">

            {{-- Success Message --}}
            @if(session('success'))
                <div class="mb-8 bg-emerald-50 border border-emerald-200 text-emerald-700 p-4 rounded-2xl flex items-start gap-3 shadow-sm">
                    <i class="fas fa-check-circle text-emerald-500 text-xl mt-0.5"></i>
                    <div>
                        <strong class="font-bold block text-emerald-800">success!</strong> 
                        <span class="text-sm">{{ session('success') }}</span>
                    </div>
                </div>
            @endif

            {{-- Validation Errors --}}
            @if($errors->any())
                <div class="mb-8 bg-red-50 border border-red-200 text-red-700 p-5 rounded-2xl shadow-sm">
                    <div class="flex items-center gap-3 mb-3">
                        <i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>
                        <strong class="font-bold text-red-800">Please fix the following errors:</strong>
                    </div>
                    <ul class="list-disc list-inside text-sm space-y-1 ml-2 text-red-600">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Update Form --}}
            <form action="{{ route('dealer.profile.update') }}" method="POST">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-10">
                    
                    <!-- Left Column: Personal Info -->
                    <div class="bg-slate-50/50 border border-slate-200 rounded-3xl p-6 sm:p-8">
                        <h3 class="text-lg font-extrabold text-blue-950 flex items-center gap-3 border-b border-slate-200 pb-4 mb-6 uppercase tracking-wider">
                            <span class="bg-orange-100 text-orange-600 w-10 h-10 rounded-xl flex items-center justify-center border border-orange-200">
                                <i class="fas fa-id-card"></i>
                            </span>
                            Personal Details
                        </h3>
                        
                        <div class="space-y-6">
                            <!-- Username (Readonly) -->
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Username (Login ID)</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-at text-slate-400"></i>
                                    </div>
                                    <input type="text" class="w-full pl-11 pr-4 py-3 bg-slate-100 border border-slate-200 text-slate-500 rounded-xl focus:outline-none cursor-not-allowed font-medium text-sm" value="{{ $admin->username }}" readonly>
                                </div>
                                <p class="text-xs text-slate-500 mt-2 flex items-center gap-1.5"><i class="fas fa-info-circle text-orange-400"></i> Username cannot be changed.</p>
                            </div>

                            <!-- Full Name -->
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Full Name <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-user text-slate-400"></i>
                                    </div>
                                    <input type="text" name="full_name" class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all font-medium text-sm text-blue-950 shadow-sm" value="{{ old('full_name', $dealer->full_name) }}" required>
                                </div>
                            </div>

                            <!-- Email -->
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Email Address <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-envelope text-slate-400"></i>
                                    </div>
                                    <input type="email" name="contact_email" class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all font-medium text-sm text-blue-950 shadow-sm" value="{{ old('contact_email', $dealer->contact_email) }}" required>
                                </div>
                            </div>

                            <!-- Phone Number -->
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Phone Number</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-phone-alt text-slate-400"></i>
                                    </div>
                                    <input type="text" name="phone_number" class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all font-medium text-sm text-blue-950 shadow-sm" value="{{ old('phone_number', $admin->phone_number) }}">
                                </div>
                            </div>

                            <!-- Qualification -->
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Qualification</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-graduation-cap text-slate-400"></i>
                                    </div>
                                    <input type="text" name="qualification" class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all font-medium text-sm text-blue-950 shadow-sm" value="{{ old('qualification', $dealer->qualification) }}">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Location & Password Info -->
                    <div class="space-y-8 lg:space-y-10">
                        
                        <!-- Location Info -->
                        <div class="bg-slate-50/50 border border-slate-200 rounded-3xl p-6 sm:p-8">
                            <h3 class="text-lg font-extrabold text-blue-950 flex items-center gap-3 border-b border-slate-200 pb-4 mb-6 uppercase tracking-wider">
                                <span class="bg-blue-100 text-blue-800 w-10 h-10 rounded-xl flex items-center justify-center border border-blue-200">
                                    <i class="fas fa-map-marker-alt"></i>
                                </span>
                                Location Details
                            </h3>

                            <div class="space-y-6">
                                <!-- Address -->
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">Address</label>
                                    <textarea name="address" rows="3" class="w-full p-4 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all font-medium text-sm text-blue-950 shadow-sm placeholder-slate-400" placeholder="Enter your full address">{{ old('address', $dealer->address) }}</textarea>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                    <!-- Region -->
                                    <div>
                                        <label class="block text-sm font-bold text-slate-700 mb-2">Region</label>
                                        <div class="relative">
                                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                                <i class="fas fa-map text-slate-400"></i>
                                            </div>
                                            <input type="text" name="region" class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all font-medium text-sm text-blue-950 shadow-sm" value="{{ old('region', $dealer->region) }}">
                                        </div>
                                    </div>

                                    <!-- Country -->
                                    <div>
                                        <label class="block text-sm font-bold text-slate-700 mb-2">Country</label>
                                        <div class="relative">
                                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                                <i class="fas fa-globe text-slate-400"></i>
                                            </div>
                                            <input type="text" name="country" class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all font-medium text-sm text-blue-950 shadow-sm" value="{{ old('country', $dealer->country) }}">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Password Change Section -->
                        <div class="bg-white border-2 border-dashed border-slate-200 rounded-3xl p-6 sm:p-8 relative overflow-hidden">
                            <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-blue-950"></div>
                            
                            <h3 class="text-lg font-extrabold text-blue-950 flex items-center gap-3 border-b border-slate-100 pb-4 mb-4 uppercase tracking-wider">
                                <span class="bg-slate-100 text-slate-600 w-10 h-10 rounded-xl flex items-center justify-center border border-slate-200">
                                    <i class="fas fa-shield-alt"></i>
                                </span>
                                Security & Password
                            </h3>
                            
                            <div class="space-y-5">
                                <!-- New Password -->
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">New Password</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <i class="fas fa-lock text-slate-400"></i>
                                        </div>
                                        <input type="password" name="new_password" class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-950 focus:border-blue-950 transition-all font-medium text-sm text-blue-950 placeholder-slate-400" placeholder="Enter new password">
                                    </div>
                                </div>

                                <!-- Confirm Password -->
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">Confirm New Password</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <i class="fas fa-check-circle text-slate-400"></i>
                                        </div>
                                        <input type="password" name="new_password_confirmation" class="w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-950 focus:border-blue-950 transition-all font-medium text-sm text-blue-950 placeholder-slate-400" placeholder="Re-enter new password">
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Action Button -->
                <div class="mt-10 pt-8 border-t border-slate-200 flex justify-end">
                    <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-3.5 px-8 rounded-xl shadow-lg shadow-orange-500/30 transition-all duration-300 hover:-translate-y-1 flex items-center gap-2">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
                
            </form>

        </div>
    </div>
</div>

@endsection