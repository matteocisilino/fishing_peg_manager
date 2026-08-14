@extends('layouts.app')

@section('content')
@php
    $user = auth()->user();
    $canCreate = $user->isSuperAdmin() || $structures->isEmpty();
@endphp
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">Facilities</h1>
        <p class="text-muted mb-0">Manage the facilities associated with the administrators</p>
    </div>
    @if($canCreate)
        <a href="{{ route('structures.create') }}" class="btn btn-primary">New facility</a>
    @endif
</div>

@if($structures->isEmpty())
    <div class="alert alert-info">There are no registered facilities.</div>
@else
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Province</th>
                    <th>Municipality</th>
                    <th>Contacts</th>
                    <th>Coordinates</th>
                    @if($user->isSuperAdmin())
                        <th>Assigned Admin</th>
                    @endif
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($structures as $structure)
                    <tr>
                        <td>{{ $structure->name }}</td>
                        <td>{{ $structure->address }}</td>
                        <td>{{ $structure->province ?? 'N/A' }}</td>
                        <td>{{ $structure->comune ?? 'N/A' }}</td>
                        <td>
                            <div>Email: <a href="mailto:{{ $structure->email }}">{{ $structure->email }}</a></div>
                            <div>Tel: <a href="tel:{{ $structure->phone }}">{{ $structure->phone }}</a></div>
                        </td>
                        <td>
                            @if($structure->latitude !== null && $structure->longitude !== null)
                                <div>Lat: {{ number_format($structure->latitude, 6) }}</div>
                                <div>Lon: {{ number_format($structure->longitude, 6) }}</div>
                                <div>Altitude: {{ $structure->altitude !== null ? $structure->altitude . ' m' : 'N/A' }}</div>
                                @if($structure->google_maps_url)
                                    <div><a href="{{ $structure->google_maps_url }}" target="_blank" rel="noopener" class="text-decoration-none">Open in Maps</a></div>
                                @endif
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </td>
                        @if($user->isSuperAdmin())
                            <td>{{ optional($structure->admin)->name ?? 'N/A' }}</td>
                        @endif
                        <td class="text-end">
                            <a href="{{ route('structures.edit', $structure) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                            @if($user->isSuperAdmin())
                                <form action="{{ route('structures.destroy', $structure) }}" method="post" class="d-inline" onsubmit="return confirm('Delete this facility?');">
                                    @csrf
                                    @method('delete')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
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
