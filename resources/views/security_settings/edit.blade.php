@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12 col-lg-8">
        <h1 class="mb-3">Security settings</h1>
        <p class="text-muted">Configure when to require OTP verification for different roles.</p>

        <form method="post" action="{{ route('security-settings.update') }}" class="card">
            @csrf
            <div class="card-body d-flex flex-column gap-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="require_admin_otp" name="require_admin_otp" value="1" {{ $settings->require_admin_otp ? 'checked' : '' }}>
                    <label class="form-check-label" for="require_admin_otp">
                        Require OTP for <strong>Admin</strong> accounts
                    </label>
                    <div class="form-text">If enabled, administrators must enter OTP unless using trusted devices.</div>
                </div>

                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="require_rilevatore_otp" name="require_rilevatore_otp" value="1" {{ $settings->require_rilevatore_otp ? 'checked' : '' }}>
                    <label class="form-check-label" for="require_rilevatore_otp">
                        Require OTP for <strong>Data Entry</strong>
                    </label>
                    <div class="form-text">If enabled, data entry users must confirm login with OTP.</div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">Save settings</button>
            </div>
        </form>
    </div>
</div>
@endsection
