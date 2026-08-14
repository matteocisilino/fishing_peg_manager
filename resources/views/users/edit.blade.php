@extends('layouts.app')

@section('content')

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <h1 class="h4 mb-3">Edit user</h1>
                <form action="{{ route('users.update', $user) }}" method="post">
                    @csrf
                    @method('put')

                    <fieldset class="border-0 p-0 mb-3">
                        <div class="mb-3">
                            <label class="form-label" for="name">Name</label>
                            <input type="text"
                                   id="name"
                                   name="name"
                                   class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name', $user->name) }}"
                                   required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="email">Email</label>
                            <input type="email"
                                   id="email"
                                   name="email"
                                   class="form-control @error('email') is-invalid @enderror"
                                   value="{{ old('email', $user->email) }}"
                                   required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="secondary_email">Backup email <span class="text-muted">(recommended)</span></label>
                            <input type="email"
                                   id="secondary_email"
                                   name="secondary_email"
                                   class="form-control @error('secondary_email') is-invalid @enderror"
                                   value="{{ old('secondary_email', $user->secondary_email) }}"
                                   placeholder="e.g. admin@venue.com">
                            @error('secondary_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Use it to receive OTP or recover access in case of issues.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="role">Role</label>
                            <select id="role"
                                    name="role"
                                    class="form-select @error('role') is-invalid @enderror"
                                    required>
                                @foreach([\App\Models\User::ROLE_ADMIN => 'Admin', \App\Models\User::ROLE_RILEVATORE => 'Data Entry'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('role', $user->role) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>



                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $user->is_active))>
                            <label class="form-check-label" for="is_active">Active account</label>
                        </div>
                    </fieldset>

                    <div class="mb-3">
                        <label class="form-label" for="password">
                            New password <span class="text-muted">(optional)</span>
                        </label>
                        <input type="password"
                               id="password"
                               name="password"
                               class="form-control @error('password') is-invalid @enderror"
                               placeholder="Leave blank to keep current">
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Leave blank to keep current credentials.</div>
                    </div>

                    <div class="text-end">
                        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            Save changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>


@endsection
