@extends('layouts.app')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <h1 class="h4 mb-3">New user</h1>
                <form action="{{ route('users.store') }}" method="post">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input type="text"
                               id="name"
                               name="name"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}"
                               required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input type="email"
                               id="email"
                               name="email"
                               class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email') }}"
                               required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="secondary_email">Backup email <span class="text-muted">(recommended)</span></label>
                        <input type="email"
                               id="secondary_email"
                               name="secondary_email"
                               class="form-control @error('secondary_email') is-invalid @enderror"
                               value="{{ old('secondary_email') }}"
                               placeholder="e.g. admin@facility.com">
                        @error('secondary_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Used for OTP and password recovery in case of issues with the primary address.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="role">Role</label>
                        <select id="role"
                                name="role"
                                class="form-select @error('role') is-invalid @enderror"
                                required>
                            @foreach([\App\Models\User::ROLE_ADMIN => 'Admin', \App\Models\User::ROLE_RILEVATORE => 'Data Entry'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('role') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>



                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input type="password"
                               id="password"
                               name="password"
                               class="form-control @error('password') is-invalid @enderror"
                               required>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', true))>
                        <label class="form-check-label" for="is_active">Active account</label>
                    </div>

                    <div class="text-end">
                        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Create user</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>


@endsection
