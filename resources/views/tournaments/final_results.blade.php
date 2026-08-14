@extends('layouts.app')

@section('content')
@php
    $eventModels = $eventModels ?? collect();
@endphp
<div class="row">
    <div class="col-12">
        <h1 class="mb-3">Final results</h1>
        <p class="text-muted">
            Tournament: <strong>{{ $tournament->name }}</strong> &middot;
            Considered events: {{ $events->count() }}
        </p>
        @if($events->isNotEmpty())
            <ul class="list-inline small text-muted mb-3">
                @foreach($events as $event)
                    <li class="list-inline-item">
                        {{ $event->name }} ({{ optional($event->event_date)->format('d/m/Y') ?? 'N/A' }})
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="mb-3 d-flex flex-wrap gap-2">
            <a href="{{ route('tournaments.show', $tournament) }}" class="btn btn-outline-secondary btn-sm">&laquo; Back to tournament</a>
            <a href="{{ route('tournaments.results_final_pdf', $tournament) }}" class="btn btn-dark btn-sm">Export PDF</a>
        </div>

        @if(auth()->user()?->isSuperAdmin() && $tournament->isPool())
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Meteosat for events</span>
                    <small class="text-muted">Available only for closed pool competitions.</small>
                </div>
                <div class="card-body">
                    @forelse(($eventModels ?? collect()) as $eventModel)
                        <div class="d-flex flex-wrap justify-content-between align-items-center border-bottom py-2">
                            <div>
                                <strong>{{ $eventModel->name }}</strong>
                                <span class="text-muted">({{ optional($eventModel->event_date)->format('d/m/Y') ?? 'N/A' }})</span>
                                @if($eventModel->structure)
                                    <span class="badge bg-light text-dark ms-2">{{ $eventModel->structure->comune ?? 'Municipality N/A' }}</span>
                                @endif
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                @if($eventModel->meteosatReport)
                                    <a href="{{ route('beta.meteosat.show', $eventModel->meteosatReport) }}" class="btn btn-sm btn-outline-success">
                                        View Meteosat data
                                    </a>
                                @elseif(!$eventModel->is_locked)
                                    <span class="text-muted small">Available after the event is closed.</span>
                                @else
                                    <form method="post" action="{{ route('events.meteosat', $eventModel) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary"
                                                onclick="return confirm('Retrieve Meteosat data for {{ $eventModel->name }}?');">
                                            Retrieve Meteosat data
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No event available.</p>
                    @endforelse
                </div>
            </div>
        @endif

        @if($standings->isEmpty())
            <div class="alert alert-info">No result available.</div>
        @else
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Participant</th>
                            @if($tournament->isTeam())
                                <th>Team</th>
                            @endif
                            <th>Penalties</th>
                            <th class="text-center" style="width:40px;"></th>
                            <th>Total penalties</th>
                            <th>Total weight</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($standings as $row)
                            <tr>
                                <td><span class="badge bg-primary">{{ $row['position'] }}</span></td>
                                <td>{{ $row['name'] }}</td>
                                @if($tournament->isTeam())
                                    <td>{{ $row['team'] }}</td>
                                @endif
                                <td>{{ $row['penalties_display'] }}</td>
                                <td class="text-center fw-bold">{{ $row['has_absolute'] ? 'A' : '' }}</td>
                                <td><strong>{{ $row['total'] }}</strong></td>
                                <td>{{ $row['weight_total_display'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
