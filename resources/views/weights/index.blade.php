@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $isRilevatore = $currentUser?->role === \App\Models\User::ROLE_RILEVATORE;
@endphp
<div class="row">
    <div class="col-12">
        <h1 class="mb-4">Weights and scores entry</h1>
        <p class="text-muted">
            @if($isRilevatore)
                You only see today's non-locked events to insert or update the weights.
            @else
                Choose an event to insert or update the participants' weights.
            @endif
        </p>

        @if($tournaments->isEmpty())
            <div class="alert alert-info">
                @if($isRilevatore)
                    No active event today to lock. Try again later!
                @else
                    No tournament available. Create a tournament to start inserting the weights.
                @endif
            </div>
        @endif

        @foreach($tournaments as $tournament)
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>{{ $tournament->name }}</strong>
                        @if(auth()->user()?->isSuperAdmin() && $tournament->structure)
                            <small class="text-muted d-block">{{ $tournament->structure->name }}</small>
                        @endif
                        @php
                            if ($tournament->isTeam()) {
                                $badgeClass = 'bg-primary';
                                $label = 'SQ';
                            } elseif ($tournament->isPool()) {
                                $badgeClass = 'bg-warning text-dark';
                                $label = 'POOL';
                            } else {
                                $badgeClass = 'bg-success';
                                $label = 'IND';
                            }
                        @endphp
                        <span class="badge {{ $badgeClass }} ms-2">{{ $label }}</span>
                    </div>
                    <small class="text-muted">{{ $tournament->events->count() }} events</small>
                </div>
                <div class="card-body">
                    @if($tournament->events->isEmpty())
                        <div class="alert alert-warning mb-0">No event defined for this tournament.</div>
                    @else
                        <div class="list-group">
                            @foreach($tournament->events->sortBy('event_date') as $event)
                                <?php
                                    $hasDraws = $event->draws->isNotEmpty();
                                    $weightsComplete = $hasDraws && $event->weightEntries->count() === $event->draws->count();
                                ?>
                                @if($hasDraws)
                                    <div class="list-group-item">
                                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                                            <div>
                                                <strong>{{ $event->name }}</strong>
                                                <span class="text-muted ms-2">{{ optional($event->event_date)->format('d/m/Y') }}</span>
                                            </div>
                                            <div class="d-flex gap-2 mt-2 mt-md-0 flex-wrap">
                                                <a href="{{ route('weights.show', $event) }}" class="btn btn-sm btn-outline-primary">Insert weights</a>
                                                @if($weightsComplete)
                                                    <a href="{{ route('weights.results', $event) }}" class="btn btn-sm btn-success">See rankings</a>
                                                @endif
                                                @if($event->tournament->isPool())
                                                    @if($event->meteosatReport)
                                                        <a href="{{ route('beta.meteosat.show', $event->meteosatReport) }}" class="btn btn-sm btn-outline-info">Meteosat</a>
                                                    @elseif($event->is_locked && auth()->user()?->isSuperAdmin())
                                                        <form method="post" action="{{ route('events.meteosat', $event) }}" class="d-inline">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-outline-info" onclick="return confirm('Retrieve Meteosat data for this event? The operation may take a few seconds.');">
                                                                Meteosat
                                                            </button>
                                                        </form>
                                                    @endif
                                                @endif
                                                {{-- Eliminazione prova spostata nel dettaglio torneo --}}
                                            </div>
                                        </div>
                                        @if(!$weightsComplete)
                                            <small class="text-muted d-block mt-2">
                                                Fill in all the weights to enable the rankings.
                                            </small>
                                        @endif
                                    </div>
                                @else
                                    <div class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                                        <div>
                                            <strong>{{ $event->name }}</strong>
                                            <span class="text-muted ms-2">{{ optional($event->event_date)->format('d/m/Y') }}</span>
                                        </div>
                                        <span class="badge bg-warning text-dark mt-2 mt-md-0">
                                            Missing draw: generate the pegs before inserting the weights.
                                        </span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
