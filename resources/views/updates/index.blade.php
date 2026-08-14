@extends('layouts.app')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1">Application Updates</h1>
                <p class="text-muted mb-0">Check GitHub repository for software updates and releases.</p>
            </div>
            <a href="{{ route('updates.index', ['force' => 1]) }}" class="btn btn-primary">
                🔄 Check GitHub Now
            </a>
        </div>

        @if(!empty($status['error']))
            <div class="alert alert-warning shadow-sm mb-4">
                <strong>Unable to verify updates:</strong> {{ $status['error'] }}
            </div>
        @elseif($status['has_update'])
            <div class="alert alert-warning border-warning shadow-sm mb-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <h4 class="alert-heading h5 mb-1">⚡ Update Available!</h4>
                        <p class="mb-0">
                            Version <strong>{{ $status['latest_version'] }}</strong> is available on GitHub.
                            (You are currently running version <strong>v{{ $status['current_version'] }}</strong>).
                        </p>
                    </div>
                    <a href="{{ $status['release_url'] }}" target="_blank" rel="noopener" class="btn btn-warning text-dark fw-bold shrink-0">
                        View Release on GitHub ↗
                    </a>
                </div>
            </div>
        @else
            <div class="alert alert-success shadow-sm mb-4 d-flex align-items-center justify-content-between">
                <div>
                    <h4 class="alert-heading h5 mb-1">✓ Application is Up to Date</h4>
                    <p class="mb-0">You are running the latest version (<strong>v{{ $status['current_version'] }}</strong>).</p>
                </div>
                <small class="text-muted">Checked: {{ \Carbon\Carbon::parse($status['checked_at'])->diffForHumans() }}</small>
            </div>
        @endif

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-body-tertiary fw-bold">
                System Version Information
            </div>
            <div class="card-body">
                <div class="row text-center g-3">
                    <div class="col-md-6">
                        <div class="p-3 border rounded bg-light">
                            <span class="text-muted small d-block uppercase fw-semibold">Installed Version</span>
                            <strong class="fs-4 text-primary">v{{ $status['current_version'] }}</strong>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded bg-light">
                            <span class="text-muted small d-block uppercase fw-semibold">Latest GitHub Release</span>
                            <strong class="fs-4 {{ $status['has_update'] ? 'text-warning' : 'text-success' }}">
                                v{{ $status['latest_version'] }}
                            </strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if(!empty($status['release_name']))
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-bold">{{ $status['release_name'] }}</span>
                    @if(!empty($status['published_at']))
                        <small class="text-muted">Published {{ \Carbon\Carbon::parse($status['published_at'])->format('d M Y, H:i') }}</small>
                    @endif
                </div>
                <div class="card-body">
                    @if(!empty($status['body']))
                        <div class="p-3 bg-light border rounded font-monospace small" style="white-space: pre-wrap;">{{ $status['body'] }}</div>
                    @else
                        <p class="text-muted mb-0">No release notes provided for this release.</p>
                    @endif

                    <div class="mt-4 d-flex flex-wrap gap-2">
                        <a href="{{ $status['release_url'] }}" target="_blank" rel="noopener" class="btn btn-outline-primary">
                            View Release Notes on GitHub ↗
                        </a>
                        @if(!empty($status['zipball_url']))
                            <a href="{{ $status['zipball_url'] }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">
                                Download Source ZIP 📦
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
