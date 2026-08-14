@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <h1 class="mb-3">Trusted devices</h1>
        <p class="text-muted">Remove unrecognized devices to force OTP verification again.</p>

        @if($devices->isEmpty())
            <div class="alert alert-info">No devices saved.</div>
        @else
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>IP</th>
                            <th>Last used</th>
                            <th>Created</th>
                            <th>User Agent</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($devices as $device)
                            <tr>
                                <td>{{ $device->device_name ?? 'Device' }}</td>
                                <td>{{ $device->ip_address ?? 'N/A' }}</td>
                                <td>{{ optional($device->last_used_at)?->format('d/m/Y H:i') ?? 'N/A' }}</td>
                                <td>{{ $device->created_at?->format('d/m/Y H:i') ?? 'N/A' }}</td>
                                <td class="text-truncate" style="max-width: 250px;" title="{{ $device->user_agent }}">
                                    {{ $device->user_agent ?? 'N/A' }}
                                </td>
                                <td class="text-end">
                                    <form method="post" action="{{ route('trusted-devices.destroy', $device) }}" onsubmit="return confirm('Remove this device? OTP will be required to access it again.');">
                                        @csrf
                                        @method('delete')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            Remove
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
