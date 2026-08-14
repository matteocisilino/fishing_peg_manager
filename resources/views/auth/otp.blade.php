@extends('layouts.app')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h5 text-center mb-3">OTP Verification</h1>
                <p class="text-muted text-center">
                    Enter the code received via email to complete login.
                </p>
                <form method="post" action="{{ route('otp.verify') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="code">OTP Code</label>
                        <input type="text"
                               id="code"
                               name="code"
                               class="form-control @error('code') is-invalid @enderror"
                               value="{{ old('code') }}"
                               maxlength="6"
                               required>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3 d-none" id="device-name-wrapper">
                        <label class="form-label" for="device_name">Device Name</label>
                        <input type="text"
                               id="device_name"
                               name="device_name"
                               class="form-control @error('device_name') is-invalid @enderror"
                               maxlength="100"
                               placeholder="e.g., Office Laptop">
                        @error('device_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" value="1" id="remember_device" name="remember_device">
                        <label class="form-check-label" for="remember_device">
                            Remember this device (skip OTP in the future)
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Verify</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkbox = document.getElementById('remember_device');
    const wrapper = document.getElementById('device-name-wrapper');
    if (!checkbox || !wrapper) {
        return;
    }
    const toggle = () => {
        wrapper.classList.toggle('d-none', !checkbox.checked);
    };
    checkbox.addEventListener('change', toggle);
    toggle();
});
</script>
@endpush
