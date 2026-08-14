@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1 class="mb-1">{{ $structure->name }} · Peg {{ $pegNumber }}</h1>
                <p class="text-muted mb-0">Weights recorded in the events of the venue</p>
            </div>
            <a href="{{ route('statistics.index') }}" class="btn btn-outline-secondary">&laquo; Back to statistics</a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-2">Total points</h5>
                        <p class="display-6 mb-0">{{ number_format($totalWeight, 0, ',', '.') }}</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-2">Average points</h5>
                        <p class="display-6 mb-0">
                            {{ $averageWeight !== null ? number_format($averageWeight, 1, ',', '.') : 'N/A' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Weight details by date</span>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#peg-table-collapse">
                    Show / Hide
                </button>
            </div>
            <div class="card-body">
                @if($entries->isEmpty())
                    <div class="alert alert-info mb-0">There is no data to show.</div>
                @else
                    <div class="collapse show" id="peg-table-collapse">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Event</th>
                                        <th>Weight</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($entries as $entry)
                                        <tr>
                                            <td>{{ optional(optional($entry->event)->event_date)->format('d/m/Y') ?? 'N/A' }}</td>
                                            <td>{{ optional($entry->event)->name ?? 'N/A' }}</td>
                                            <td>{{ number_format($entry->weight_value, 0, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @php
            $chartWidth = max(600, $entries->count() * 100);
            $chartHeight = 320;
        @endphp
        <div class="card mt-4 mb-4">
            <div class="card-header">Annual weights trend</div>
            <div class="card-body">
                @if($entries->isEmpty())
                    <div class="alert alert-info mb-0">There are no weights recorded for this peg.</div>
                @else
                    <div class="overflow-auto">
                        <canvas id="peg-line-chart"
                                height="{{ $chartHeight }}"
                                style="min-width: {{ $chartWidth }}px;"></canvas>
                    </div>
                @endif
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Catch rate compared to event average</div>
            <div class="card-body">
                @if($entries->isEmpty())
                    <div class="alert alert-info mb-0">There are no weights recorded for this peg.</div>
                @else
                    <div class="overflow-auto">
                        <canvas id="peg-scatter-chart"
                                height="320"
                                style="min-width: {{ $chartWidth }}px;"></canvas>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@if($entries->isNotEmpty())
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const MEDIA_REFERENCE_COLOR = '#dc3545';
            const PEG_REFERENCE_COLOR = '#0d6efd';

            const scatterPoints = @json($scatterData);
            const averageReferencePoints = scatterPoints.map(point => Object.assign({}, point, { y: point.x }));

            const lineCtx = document.getElementById('peg-line-chart');
            if (lineCtx) {
                new Chart(lineCtx, {
                    type: 'line',
                    data: {
                        datasets: [
                            {
                                label: 'Single weight',
                                data: @json($lineWeights),
                                borderColor: '#0d6efd',
                                backgroundColor: 'rgba(13, 110, 253, 0.15)',
                                tension: 0.25,
                                fill: true,
                                pointRadius: 4,
                            },
                            {
                                label: 'Cumulative total',
                                data: @json($lineCumulative),
                                borderColor: '#198754',
                                backgroundColor: 'rgba(25, 135, 84, 0.1)',
                                borderDash: [6,3],
                                fill: false,
                                pointRadius: 3,
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                type: 'time',
                                time: {
                                    unit: 'month',
                                    tooltipFormat: 'dd/MM/yyyy',
                                    displayFormats: {
                                        month: 'MMM yyyy'
                                    }
                                },
                                title: {
                                    display: true,
                                    text: 'Date'
                                }
                            },
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Weight'
                                }
                            }
                        }
                    }
                });
            }

            const scatterCtx = document.getElementById('peg-scatter-chart');
            if (scatterCtx) {
                new Chart(scatterCtx, {
                    type: 'scatter',
                    data: {
                        datasets: [
                            {
                                label: 'Peg weight',
                                data: scatterPoints,
                                parsing: false,
                                backgroundColor: PEG_REFERENCE_COLOR,
                                borderColor: PEG_REFERENCE_COLOR,
                                pointRadius: 5,
                            },
                            {
                                label: 'Event weights average',
                                data: averageReferencePoints,
                                parsing: false,
                                backgroundColor: MEDIA_REFERENCE_COLOR,
                                borderColor: MEDIA_REFERENCE_COLOR,
                                pointRadius: 4,
                                showLine: true,
                                borderWidth: 2,
                                borderDash: [4, 4],
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Event weights average',
                                    color: MEDIA_REFERENCE_COLOR,
                                },
                                ticks: {
                                    color: MEDIA_REFERENCE_COLOR,
                                },
                            },
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Peg weight',
                                    color: PEG_REFERENCE_COLOR,
                                },
                                ticks: {
                                    color: PEG_REFERENCE_COLOR,
                                },
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const data = context.raw;
                                        return `${data.date ?? ''} - ${data.event ?? ''}: Weight ${data.y}`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>
@endif
@endpush
