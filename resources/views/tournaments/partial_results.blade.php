@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <h1 class="mb-3">Partial results</h1>
        <p class="text-muted">
            Tournament: <strong>{{ $tournament->name }}</strong> &middot;
            Considered events: {{ $events->count() }}
        </p>
        <div class="mb-3 d-flex flex-wrap gap-2">
            <a href="{{ route('tournaments.show', $tournament) }}" class="btn btn-outline-secondary btn-sm">&laquo; Back to tournament</a>
            <a href="{{ route('tournaments.results_final', $tournament) }}" class="btn btn-outline-primary btn-sm">Go to final results</a>
        </div>

        @if($standings->isEmpty())
            <div class="alert alert-info">No data available for partial results.</div>
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
