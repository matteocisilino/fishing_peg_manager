@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <h1 class="mb-3">Existing tournaments</h1>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <a href="{{ route('tournaments.create') }}" class="btn btn-primary">+ Create team tournament</a>
            <a href="{{ route('tournaments.create_individual') }}" class="btn btn-outline-primary">+ Create individual tournament</a>
            <a href="{{ route('tournaments.create_pool') }}" class="btn btn-outline-warning">+ Create pool tournament</a>
        </div>

        @if(\App\Models\Structure::count() === 0)
            <div class="alert alert-warning border-warning shadow-sm mb-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <h4 class="alert-heading h5 mb-1"> Welcome to Fishing Tournament Manager!</h4>
                        <p class="mb-0">To get started, configure your first Venue. You need at least one Venue to manage lakes, pegs, and tournaments.</p>
                    </div>
                    <a href="{{ route('structures.create') }}" class="btn btn-warning fw-semibold text-dark shrink-0">
                         Create First Venue
                    </a>
                </div>
            </div>
        @endif

        @if($activeTournaments->isEmpty() && $completedTournaments->isEmpty())
            <div class="alert alert-info">No tournaments found. Create the first one.</div>
        @endif

        @if($activeTournaments->isNotEmpty())
            <div class="card mb-4">
                <div class="card-header">Active tournaments</div>
                <div class="list-group list-group-flush">
                    @foreach($activeTournaments as $tournament)
                        @php
                            if ($tournament->isTeam()) {
                                $typeLabel = 'TEAM';
                                $badgeClass = 'bg-primary';
                            } elseif ($tournament->isPool()) {
                                $typeLabel = 'POOL';
                                $badgeClass = 'bg-warning text-dark';
                            } else {
                                $typeLabel = 'IND';
                                $badgeClass = 'bg-success';
                            }

                            $sortedEvents = $tournament->events->sortBy('event_date');
                            $eventToWeigh = $sortedEvents->first(function ($event) {
                                $drawsCount = $event->draws->count();
                                $weightsCount = $event->weightEntries->count();
                                return $drawsCount > 0 && $weightsCount < $drawsCount;
                            });
                            $lastCompletedEvent = $sortedEvents->filter(function ($event) {
                                $drawsCount = $event->draws->count();
                                return $drawsCount > 0 && $event->weightEntries->count() === $drawsCount;
                            })->last();
                        @endphp
                        <div class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                            <div class="d-flex align-items-center">
                                <strong class="badge {{ $badgeClass }} me-3" style="width: 52px;">{{ $typeLabel }}</strong>
                                <div>
                                    <div class="fw-semibold">
                                        {{ $tournament->name }}
                                        @if(auth()->user()?->isSuperAdmin() && $tournament->structure)
                                            <small class="text-muted d-block">{{ $tournament->structure->name }}</small>
                                        @endif
                                    </div>
                                    <small class="text-muted">{{ $tournament->events->count() }} events defined</small>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-3 mt-md-0 align-items-start">
                                @if($eventToWeigh)
                                    <div class="me-2 small">
                                        <div class="fw-semibold">Event: {{ $eventToWeigh->name }}</div>
                                        <div class="text-muted">Created on {{ optional($eventToWeigh->created_at)->format('d/m/Y H:i') }}</div>
                                    </div>
                                @elseif($lastCompletedEvent)
                                    <div class="me-2 small">
                                        <div class="fw-semibold">Event: {{ $lastCompletedEvent->name }}</div>
                                        <div class="text-muted">Created on {{ optional($lastCompletedEvent->created_at)->format('d/m/Y H:i') }}</div>
                                    </div>
                                @endif
                                <a href="{{ route('tournaments.show', $tournament) }}" class="btn btn-sm btn-outline-primary">
                                    Settings
                                </a>
                                <a href="{{ route('tournaments.partial_results', $tournament) }}" class="btn btn-sm btn-outline-success">
                                    Partial results
                                </a>
                                @if($eventToWeigh)
                                    <a href="{{ route('weights.show', $eventToWeigh) }}" class="btn btn-sm btn-outline-secondary">
                                        Insert weights
                                    </a>
                                @elseif($lastCompletedEvent)
                                    <a href="{{ route('weights.results', $lastCompletedEvent) }}" class="btn btn-sm btn-outline-secondary">
                                        View placements
                                    </a>
                                @endif
                                @if(auth()->user()?->hasRole(\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ADMIN))
                                    <form action="{{ route('tournaments.otp', $tournament) }}" method="post">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            Send deletion OTP
                                        </button>
                                    </form>
                                    <form action="{{ route('tournaments.destroy', $tournament) }}"
                                          method="post"
                                          class="d-flex align-items-center gap-2"
                                          onsubmit="return confirm('Are you sure you want to permanently delete this tournament and all associated events?');">
                                        @csrf
                                        @method('delete')
                                        <input type="text" name="otp_code" class="form-control form-control-sm" placeholder="OTP" maxlength="6" required>
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            Delete tournament
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if($completedTournaments->isNotEmpty())
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Completed tournaments</span>
                    <small class="text-muted">Read only</small>
                </div>
                <div class="list-group list-group-flush">
                    @foreach($completedTournaments as $tournament)
                        @php
                            if ($tournament->isTeam()) {
                                $typeLabel = 'TEAM';
                                $badgeClass = 'bg-primary';
                            } elseif ($tournament->isPool()) {
                                $typeLabel = 'POOL';
                                $badgeClass = 'bg-warning text-dark';
                            } else {
                                $typeLabel = 'IND';
                                $badgeClass = 'bg-success';
                            }
                        @endphp
                        <div class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                            <div class="d-flex align-items-center">
                                <strong class="badge {{ $badgeClass }} me-3" style="width: 52px;">{{ $typeLabel }}</strong>
                                <div>
                                    <div class="fw-semibold">
                                        {{ $tournament->name }}
                                        @if(auth()->user()?->isSuperAdmin() && $tournament->structure)
                                            <small class="text-muted d-block">{{ $tournament->structure->name }}</small>
                                        @endif
                                    </div>
                                    <small class="text-muted">{{ $tournament->events->count() }} completed events</small>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-3 mt-md-0">
                                <a href="{{ route('tournaments.show', $tournament) }}" class="btn btn-sm btn-outline-secondary">
                                    Settings
                                </a>
                                <a href="{{ route('tournaments.results_final', $tournament) }}" class="btn btn-sm btn-success">
                                    Final results
                                </a>
                                @if(auth()->user()?->hasRole(\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ADMIN))
                                    <form action="{{ route('tournaments.otp', $tournament) }}" method="post">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            Send deletion OTP
                                        </button>
                                    </form>
                                    <form action="{{ route('tournaments.destroy', $tournament) }}"
                                          method="post"
                                          class="d-flex align-items-center gap-2"
                                          onsubmit="return confirm('Are you sure you want to permanently delete this completed tournament? All associated data will be deleted.');">
                                        @csrf
                                        @method('delete')
                                        <input type="text" name="otp_code" class="form-control form-control-sm" placeholder="OTP" maxlength="6" required>
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            Delete tournament
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

    </div>
</div>
@endsection
