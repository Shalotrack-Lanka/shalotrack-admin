@extends('layouts.admin') {{-- ඔබගේ ප්‍රධාන Layout ෆයිල් එකෙහි නම ලබා දෙන්න --}}

@section('content')
<div class="container-fluid mt-4">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0">My Dealer Profile</h4>
        </div>
        <div class="card-body">

            {{-- Success Message --}}
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            {{-- Validation Errors --}}
            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
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

                <div class="row">
                    <!-- Left Column: Personal Info -->
                    <div class="col-md-6 mb-3">
                        <h5 class="text-secondary border-bottom pb-2">Personal Details</h5>
                        
                        <div class="mb-3">
                            <label class="form-label">Username (Login ID)</label>
                            <input type="text" class="form-control bg-light" value="{{ $admin->username }}" readonly>
                            <small class="text-muted">Username වෙනස් කළ නොහැක.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="{{ old('full_name', $dealer->full_name) }}" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="contact_email" class="form-control" value="{{ old('contact_email', $dealer->contact_email) }}" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone_number" class="form-control" value="{{ old('phone_number', $admin->phone_number) }}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Qualification</label>
                            <input type="text" name="qualification" class="form-control" value="{{ old('qualification', $dealer->qualification) }}">
                        </div>
                    </div>

                    <!-- Right Column: Location & Password Info -->
                    <div class="col-md-6 mb-3">
                        <h5 class="text-secondary border-bottom pb-2">Location & Security</h5>

                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="3">{{ old('address', $dealer->address) }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Region</label>
                            <input type="text" name="region" class="form-control" value="{{ old('region', $dealer->region) }}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Country</label>
                            <input type="text" name="country" class="form-control" value="{{ old('country', $dealer->country) }}">
                        </div>

                        <!-- Password Change Section -->
                        <div class="p-3 border rounded bg-light mt-4">
                            <h6 class="text-primary mb-3"><i class="fas fa-lock"></i> Change Password (Optional)</h6>
                            <p class="small text-muted">Password එක වෙනස් කිරීමට අවශ්‍ය නම් පමණක් පහත තීරුව පුරවන්න.</p>
                            
                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" class="form-control" placeholder="Enter new password">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" name="new_password_confirmation" class="form-control" placeholder="Re-enter new password">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-end mt-3">
                    <button type="submit" class="btn btn-primary px-5">Save Changes</button>
                </div>
            </form>

        </div>
    </div>
</div>
@endsection