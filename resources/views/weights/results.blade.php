@extends('layouts.app')

@section('content')
@php
    $formatPosition = static function ($value) {
        if ($value === null) {
            return '-';
        }
        return fmod($value, 1.0) === 0.0
            ? (string) (int) $value
            : number_format($value, 1, ',', '');
    };
@endphp
<div class="row">
    <div class="col-12">
        <h1 class="mb-3">Event rankings</h1>
        <p class="text-muted">
            Tournament: <strong>{{ $event->tournament->name }}</strong> &middot;
            Event: <strong>{{ $event->name }}</strong> &middot;
            Date: {{ optional($event->event_date)->format('d/m/Y') ?? 'N/A' }}
        </p>
        <div class="mb-3">
            <a href="{{ route('weights.show', $event) }}" class="btn btn-outline-secondary btn-sm">&laquo; Back to weights entry</a>
            <a href="{{ route('tournaments.show', $event->tournament) }}" class="btn btn-outline-primary btn-sm">Go to tournament</a>
            <a href="{{ route('weights.results_pdf', $event) }}" class="btn btn-outline-dark btn-sm">Export PDF</a>
        </div>

        @if($event->tournament->isPool())
            <div class="alert alert-info mt-3">
                @if($event->meteosatReport)
                    Meteosat data registered for this event.
                    <a href="{{ route('beta.meteosat.show', $event->meteosatReport) }}" class="alert-link">Open details</a>.
                @elseif(!$event->is_locked)
                    Meteosat retrieval available after event closes.
                @else
                    @if(auth()->user()?->isSuperAdmin())
                        <form method="post" action="{{ route('events.meteosat', $event) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-primary" onclick="return confirm('Retrieve Meteosat data for this event? The operation may take a few seconds.');">
                                Retrieve Meteosat data
                            </button>
                        </form>
                    @else
                        Only a super admin can start Meteosat retrieval after the event closes.
                    @endif
                @endif
            </div>
        @endif

        @foreach ($zones as $zoneName => $entries)
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>{{ $zoneName }}</strong>
                    <small class="text-muted">Participants: {{ count($entries) }}</small>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Participant</th>
                                    @if ($event->tournament->isTeam())
                                        <th>Team</th>
                                    @endif
                                    <th>Peg</th>
                                    <th>Weight</th>
                                    <th>Ranking</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($entries as $row)
                                    <?php $draw = $row['draw']; ?>
                                    <tr class="{{ $row['placeholder'] ?? false ? 'text-muted' : '' }}">
                                        <td>
                                            @if($row['placeholder'] ?? false)
                                                <strong>T</strong> {{ $row['display_name'] ?? 'Ghost peg (empty)' }}
                                            @else
                                                {{ $draw->frozen_angler_name ?? optional($draw->angler)->name ?? '—' }}
                                            @endif
                                        </td>
                                        @if ($event->tournament->isTeam())
                                            <td>
                                                @if($row['placeholder'] ?? false)
                                                    &mdash;
                                                @else
                                                    {{ $draw->frozen_team_name ?? optional(optional($draw->angler)->team)->name ?? '-' }}
                                                @endif
                                            </td>
                                        @endif
                                        <td>{{ $row['peg_number'] ?? ($draw->peg_number ?? '—') }}</td>
                                        <td>{{ $row['weight'] === null ? '—' : number_format($row['weight'], 0, ',', '.') }}</td>
                                        <td>
                                            <?php $positionLabel = $formatPosition($row['position']); ?>
                                            <span class="badge bg-primary">{{ $positionLabel }}</span>
                                            @if (!( $row['placeholder'] ?? false) && ($row['is_absolute'] ?? false))
                                                <span class="badge bg-dark">A</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
