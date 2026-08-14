@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Meteosat Archive</h1>
            <a href="{{ route('beta.index') }}" class="btn btn-link">&laquo; Beta Area</a>
        </div>

        <div class="card mb-4">
            <div class="card-header">Events with Meteosat data</div>
            <div class="card-body p-0">
                @if ($reports->isEmpty())
                    <div class="alert alert-info mb-0">There are no registered Meteosat data yet.</div>
                @else
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Tournament</th>
                            <th>Event</th>
                            <th>Municipality</th>
                            <th>Period</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($reports as $report)
                            <tr>
                                <td>{{ $report->tournament->name ?? 'N/A' }}</td>
                                <td>{{ optional($report->event)->name ?? 'N/A' }}</td>
                                <td>{{ $report->comune }}</td>
                                <td>
                                    {{ optional($report->start_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                                    &rarr;
                                    {{ optional($report->end_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('beta.meteosat.show', $report) }}" class="btn btn-sm btn-outline-primary">
                                        Open Meteosat data
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-3 px-3 pb-3">
                    {{ $reports->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
