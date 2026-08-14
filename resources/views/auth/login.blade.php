@extends('layouts.app')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 text-center mb-4">Login</h1>
                <form method="post" action="{{ route('login.attempt') }}" autocomplete="on" data-allow-autocomplete="1">
                    @csrf
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email"
                               id="email"
                               name="email"
                               class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email') }}"
                               required
                               autofocus>
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password"
                               id="password"
                               name="password"
                               class="form-control @error('password') is-invalid @enderror"
                               required>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember">
                        <label class="form-check-label" for="remember">
                            Remember me
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        Sign in
                    </button>
                </form>
                <div class="text-center mt-3 text-muted small">
                    You can recover your password using your primary or backup email.
                </div>
                <div class="text-center mt-2">
                    <a href="{{ route('password.request') }}" class="fw-semibold text-decoration-none">
                        Forgot password?
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
