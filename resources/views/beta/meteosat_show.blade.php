@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1 class="h4 mb-0">Meteosat Details</h1>
                <p class="text-muted mb-0">{{ $report->tournament->name ?? 'N/A' }} — {{ optional($report->event)->name ?? 'N/A' }}</p>
            </div>
            <a href="{{ route('beta.meteosat') }}" class="btn btn-link">&laquo; Back to archive</a>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Venue</dt>
                    <dd class="col-sm-9">{{ $report->structure->name ?? 'N/A' }}</dd>
                    <dt class="col-sm-3">Municipality</dt>
                    <dd class="col-sm-9">{{ $report->comune }}</dd>
                    <dt class="col-sm-3">Analyzed period</dt>
                    <dd class="col-sm-9">
                        {{ optional($report->start_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                        &rarr;
                        {{ optional($report->end_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                    </dd>
                    <dt class="col-sm-3">Retrieved on</dt>
                    <dd class="col-sm-9">{{ optional($report->created_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</dd>
                </dl>
            </div>
        </div>

        @php
            $rawSeries = [];
            if (is_array($report->payload) && isset($report->payload['hourly']) && is_array($report->payload['hourly'])) {
                $rawSeries = collect($report->payload['hourly'])
                    ->filter(fn ($entry) => isset($entry['time']))
                    ->map(fn ($entry) => [
                        'time' => $entry['time'],
                        'temp' => isset($entry['temp']) ? (float) $entry['temp'] : null,
                        'pres' => isset($entry['pres']) ? (float) $entry['pres'] : null,
                        'prcp' => isset($entry['prcp']) ? (float) $entry['prcp'] : 0.0,
                        'wspd' => isset($entry['wspd']) ? (float) $entry['wspd'] : 0.0,
                    ])
                    ->values()
                    ->all();
            }
        @endphp

        @if(!empty($rawSeries))
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Weather Trend (latest imported data)</span>
                    <small class="text-muted">Meteosat hourly series</small>
                </div>
                <div class="card-body">
                    <canvas id="meteosat-chart" height="320"></canvas>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>JSON Source</span>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#meteosat-json" aria-expanded="false">
                    Show / Hide
                </button>
            </div>
            <div class="collapse" id="meteosat-json">
                <pre class="mb-0 p-3" style="max-height: 420px; overflow:auto;">
{{ json_encode($report->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                </pre>
            </div>
        </div>
    </div>
</div>
@endsection

@if(!empty($rawSeries))
    @push('scripts')
        <script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const canvas = document.getElementById('meteosat-chart');
                if (!canvas) {
                    return;
                }

                const rawSeries = @json($rawSeries);
                const toNumber = value => (value === null || value === undefined || value === '') ? null : Number(value);

                const labels = rawSeries.map(point => point.time);
                const datasets = [
                    {
                        type: 'line',
                        label: 'Temperature (°C)',
                        borderColor: '#7c4dff',
                        backgroundColor: 'rgba(124,77,255,0.15)',
                        data: rawSeries.map(point => toNumber(point.temp)),
                        yAxisID: 'yTemp',
                        tension: 0.3,
                        spanGaps: true,
                    },
                    {
                        type: 'line',
                        label: 'Pressure (hPa)',
                        borderColor: 'rgba(255,107,107,0.9)',
                        backgroundColor: 'rgba(255,107,107,0.08)',
                        data: rawSeries.map(point => toNumber(point.pres)),
                        yAxisID: 'yPres',
                        tension: 0.3,
                        spanGaps: true,
                    },
                    {
                        type: 'bar',
                        label: 'Precipitation (mm)',
                        backgroundColor: 'rgba(13,110,253,0.4)',
                        borderColor: '#0d6efd',
                        borderWidth: 1,
                        data: rawSeries.map(point => toNumber(point.prcp) ?? 0),
                        yAxisID: 'yPrcp',
                    },
                    {
                        type: 'line',
                        label: 'Wind speed (m/s)',
                        borderColor: '#1abc9c',
                        backgroundColor: 'rgba(26,188,156,0.1)',
                        borderDash: [5, 4],
                        data: rawSeries.map(point => toNumber(point.wspd)),
                        yAxisID: 'yWind',
                        tension: 0.3,
                    },
                ];

                const unitsMap = {
                    yTemp: '°C',
                    yPres: 'hPa',
                    yPrcp: 'mm',
                    yWind: 'm/s',
                };

                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels,
                        datasets,
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            legend: {
                                display: true,
                            },
                            tooltip: {
                                callbacks: {
                                    label: context => {
                                        const axis = context.dataset.yAxisID;
                                        const value = context.parsed.y !== null ? context.parsed.y : context.parsed;
                                        const unit = unitsMap[axis] ? ` ${unitsMap[axis]}` : '';
                                        return `${context.dataset.label}: ${value ?? '-'}${unit}`;
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                ticks: {
                                    maxRotation: 0,
                                    autoSkip: true,
                                    maxTicksLimit: 12,
                                },
                                title: {
                                    display: true,
                                    text: 'Local time',
                                },
                            },
                            yTemp: {
                                type: 'linear',
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Temperature (°C)',
                                },
                            },
                            yPres: {
                                type: 'linear',
                                position: 'right',
                                grid: {
                                    drawOnChartArea: false,
                                },
                                title: {
                                    display: true,
                                    text: 'Pressure (hPa)',
                                },
                            },
                            yPrcp: {
                                type: 'linear',
                                position: 'right',
                                display: false,
                            },
                            yWind: {
                                type: 'linear',
                                position: 'right',
                                grid: {
                                    drawOnChartArea: false,
                                },
                                title: {
                                    display: true,
                                    text: 'Wind (m/s)',
                                },
                                suggestedMax: 15,
                            },
                        },
                    },
                });
            });
        </script>
    @endpush
@endif
