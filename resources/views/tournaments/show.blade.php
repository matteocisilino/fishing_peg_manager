@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <h1 class="mb-3">{{ $tournament->name }}</h1>
        <div class="mb-3">
            <a href="{{ route('tournaments.index') }}" class="btn btn-link">&laquo; Back to home</a>
            <a href="{{ route('tournaments.edit', $tournament) }}" class="btn btn-outline-primary">Edit tournament / substitutions</a>
            @if ($tournament->isCompleted())
                <a href="{{ route('tournaments.results_final', $tournament) }}" class="btn btn-success">Final results</a>
            @endif
        </div>
        <p class="text-muted mb-1">
            Tournament type:
            @if ($tournament->isTeam())
                Teams
            @elseif($tournament->isPool())
                Pool
            @else
                Individual
            @endif
        </p>
        @if (!$tournament->isPool())
            <p class="text-muted">Sectors calculated in sectors of {{ $tournament->sector_size }} pegs.</p>
        @endif

        @if ($tournament->isPool())
            <div class="card mb-4">
                <div class="card-header">Pool pegs</div>
                <div class="card-body">
                    @if ($poolPegs->isEmpty())
                        <div class="alert alert-warning mb-0">No peg defined.</div>
                    @else
                        <p class="mb-2 text-muted">
                            Available pegs: {{ $poolPegs->count() }} &middot;
                            Participant limit: {{ $tournament->pool_capacity ?? 'N/A' }}
                        </p>
                        <?php $groupedPoolPegs = $poolPegs->groupBy('zone_name'); ?>
                        @foreach ($groupedPoolPegs as $zoneName => $pegs)
                            <div class="mb-3">
                                <h6 class="mb-1">{{ $zoneName }}</h6>
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach ($pegs as $peg)
                                        <span class="badge bg-secondary">{{ $peg->peg_number }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        @else
            <div class="card mb-4">
                <div class="card-header">Zones</div>
                <div class="card-body">
                    @if ($zones->isEmpty())
                        <div class="alert alert-warning mb-0">No zone defined.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead><tr><th>Name</th><th>From</th><th>To</th></tr></thead>
                                <tbody>
                                    @foreach ($zones as $zone)
                                        <tr>
                                            <td>{{ $zone->name }}</td>
                                            <td>{{ $zone->start_number }}</td>
                                            <td>{{ $zone->end_number }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if ($tournament->isTeam())
            <p class="text-muted">
                Expected participants per team: {{ $tournament->team_size ?? 'N/A' }}.
                Use edit to assign participants to teams without members.
            </p>
            <div class="card mb-4">
                <div class="card-header">Teams and participants</div>
                <div class="card-body">
                    @if ($teams->isEmpty())
                        <div class="alert alert-warning mb-0">No team inserted.</div>
                    @else
                        @foreach ($teams as $team)
                            <div class="mb-3">
                                <h5>{{ $team->name }}</h5>
                                @if ($team->anglers->isEmpty())
                                    <p class="text-muted">No participant.</p>
                                @else
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead><tr><th>Participant</th><th>Zone</th></tr></thead>
                                            <tbody>
                                                @foreach ($team->anglers as $angler)
                                                    <tr>
                                                        <td>{{ $angler->name }}</td>
                                                        <td>{{ optional($angler->zone)->name }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        @elseif ($tournament->isPool())
            <?php
                $pegCount = $poolPegs->count();
                $limit = $tournament->pool_capacity ?? 0;
                $capacity = $limit > 0 ? ($pegCount > 0 ? min($limit, $pegCount) : $limit) : $pegCount;
            ?>
            <div class="card mb-4">
                <div class="card-header">Pool participants</div>
                <div class="card-body">
                    <p class="text-muted">Registered participants: {{ $participants->count() }} / {{ $capacity }}</p>
                    @if ($participants->isEmpty())
                        <div class="alert alert-warning mb-0">No participant inserted.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead><tr><th>#</th><th>Name</th></tr></thead>
                                <tbody>
                                    @foreach ($participants as $index => $participant)
                                        <tr>
                                            <td>{{ $index + 1 }}</td>
                                            <td>{{ $participant->name }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="card mb-4">
                <div class="card-header">Participants</div>
                <div class="card-body">
                    <?php $capacity = $zones->sum(function ($zone) { return $zone->end_number - $zone->start_number + 1; }); ?>
                    <p class="text-muted">Registered participants: {{ $participants->count() }} / {{ $capacity }}</p>
                    @if ($participants->isEmpty())
                        <div class="alert alert-warning mb-0">No participant inserted.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead><tr><th>#</th><th>Name</th></tr></thead>
                                <tbody>
                                    @foreach ($participants as $index => $participant)
                                        <tr>
                                            <td>{{ $index + 1 }}</td>
                                            <td>{{ $participant->name }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <div class="card mb-4">
            <div class="card-header">Events</div>
            <div class="card-body">
                @if ($events->isEmpty())
                    <div class="alert alert-warning mb-0">No event defined.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Name</th>
                                    <th>Facility</th>
                                    <th>Status</th>
                                    <th>Draw</th>
                                    <th>Weights / rankings summary</th>
                                    @if(auth()->user()?->hasRole(\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ADMIN))
                                        <th style="width: 240px;">Actions</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($events as $event)
                                    <?php
                                        $hasDraws = $event->draws->isNotEmpty();
                                        $weightsComplete = $hasDraws && $event->weightEntries->count() === $event->draws->count();
                                    ?>
                                    <tr>
                                        <td>{{ $event->event_date->format('Y-m-d') }}</td>
                                        <td>{{ $event->name }}</td>
                                        <td>
                                            {{ $event->structure->name ?? $tournament->structure->name ?? 'N/A' }}
                                        </td>
                                        <td>
                                            @if ($event->is_locked)
                                                <span class="badge bg-success">Locked</span>
                                            @else
                                                <span class="badge bg-secondary">Editable</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('events.show', $event) }}" class="btn btn-sm btn-outline-primary">
                                                Detail / Draw
                                            </a>
                                        </td>
                                        <td>
                                            @if(!$hasDraws)
                                                <small class="text-muted">Generate the draw first.</small>
                                            @else
                                                <div class="d-flex flex-wrap gap-2">
                                                    <a href="{{ route('weights.show', $event) }}" class="btn btn-sm btn-outline-secondary">
                                                        Weights / rankings summary
                                                    </a>
                                                    @if($weightsComplete)
                                                        <a href="{{ route('weights.results', $event) }}" class="btn btn-sm btn-success">
                                                            Only rankings
                                                        </a>
                                                    @endif
                                                </div>
                                            @endif
                                        </td>
                                        @if(auth()->user()?->hasRole(\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ADMIN))
                                            <td>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <form action="{{ route('events.otp', $event) }}" method="post">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                                            Send OTP
                                                        </button>
                                                    </form>
                                                    <form action="{{ route('events.destroy', $event) }}"
                                                          method="post"
                                                          class="d-flex align-items-center gap-2"
                                                          onsubmit="return confirm('Confirm the permanent deletion of the event?');">
                                                        @csrf
                                                        @method('delete')
                                                        <input type="text"
                                                               name="otp_code"
                                                               class="form-control form-control-sm"
                                                               placeholder="OTP"
                                                               maxlength="6"
                                                               required>
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            Delete event
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
