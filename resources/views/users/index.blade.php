@extends('layouts.app')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3">
    <div>
        <h1 class="h3 mb-0">User management</h1>
        <p class="text-muted mb-0">
            Activate/deactivate users and modify assigned roles.
        </p>
    </div>
    <a href="{{ route('users.create') }}" class="btn btn-primary">+ New user</a>
</div>

@if($users->isEmpty())
    <div class="alert alert-info">
        There are no registered users.
    </div>
@else
    <div class="table-responsive">
        <table class="table align-middle table-striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>

                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td class="text-uppercase small">{{ str_replace('_', ' ', $user->role) }}</td>

                        <td>
                            @if($user->is_active)
                                <span class="badge bg-success">Active</span>
                            @else
                                <span class="badge bg-danger">Inactive</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-primary">
                                Edit
                            </a>
                            @if(auth()->id() !== $user->id)
                                <form action="{{ route('users.toggle', $user) }}" method="post" class="d-inline">
                                    @csrf
                                    @method('patch')
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                        {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>

                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
