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
@push('styles')
<style>
@media (max-width: 576px) {
    .weights-table th,
    .weights-table td {
        padding: 0.45rem 0.4rem;
        font-size: 0.925rem;
    }
    .weights-table .weight-input {
        min-width: 120px;
        width: 100%;
    }
}
.weights-table .peg-col {
    width: 70px;
}
</style>
@endpush
<div class="row">
    <div class="col-12">
        <h1 class="mb-3">Event weights</h1>
        <p class="text-muted">
            Tournament: <strong>{{ $event->tournament->name }}</strong> &middot;
            Event: <strong>{{ $event->name }}</strong> &middot;
            Date: {{ optional($event->event_date)->format('d/m/Y') ?? 'N/A' }}
        </p>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('weights.index') }}" class="btn btn-link">&laquo; Back to events list</a>
            @if ($weightsComplete)
                <a href="{{ route('weights.results', $event) }}" class="btn btn-outline-success btn-sm">See rankings</a>
            @endif
        </div>

        @if(auth()->user()?->isSuperAdmin() && $event->tournament->isPool())
            <div class="alert alert-info mt-3">
                @if($event->meteosatReport)
                    Meteosat data registered for this event.
                    <a href="{{ route('beta.meteosat.show', $event->meteosatReport) }}" class="alert-link">Open details</a>.
                @elseif(!$event->is_locked)
                    Meteosat retrieval available after event closes.
                @else
                    <form method="post" action="{{ route('events.meteosat', $event) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-primary" onclick="return confirm('Retrieve Meteosat data for this event? The operation may take a few seconds.');">
                            Retrieve Meteosat data
                        </button>
                    </form>
                @endif
            </div>
        @endif

        @if($zonesData->isEmpty())
            <div class="alert alert-warning mt-3">
                The draw for this event has not yet been generated, so it is not possible to enter weights.
            </div>
        @else
            <form method="post" action="{{ route('weights.store', $event) }}" id="weights-bulk-form">
                @csrf
                @foreach($zonesData as $zone)
                    <div class="card mb-4">
                    <div class="card-header d-flex flex-column flex-lg-row justify-content-between align-items-lg-center">
                            <div class="d-flex align-items-center gap-2">
                                @if ($zone['locked'])
                                    <span class="text-success" aria-hidden="true">&#128274;</span>
                                @endif
                                <strong class="mb-0">{{ $zone['name'] }}</strong>
                                @if ($zone['locked'])
                                    <span class="badge bg-success ms-2">Confirmed zone</span>
                                @else
                                    <span class="badge bg-secondary ms-2">Editing</span>
                                @endif
                        </div>
                        <div class="mt-2 mt-lg-0 d-flex flex-wrap gap-2 align-items-center">
                            @if ($zone['locked'])
                                <small class="text-success">
                                    Confirmed {{ optional($zone['locked_at'])->format('d/m/Y H:i') ?? '' }}
                                </small>
                                <button type="submit"
                                        class="btn btn-sm btn-outline-danger"
                                        form="{{ $zone['unlock_form_id'] }}"
                                        title="Unlock zone">
                                    &#10006;
                                </button>
                            @elseif (!$zone['complete'])
                                <small class="text-muted">Complete all weights to confirm the zone.</small>
                            @else
                                <button type="submit"
                                        class="btn btn-sm btn-success"
                                        form="{{ $zone['lock_form_id'] }}">
                                    Confirm zone
                                </button>
                            @endif
                        </div>
                    </div>
                        <input type="hidden" name="zone_name[]" value="{{ $zone['name'] }}">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle weights-table">
                                    <thead>
                                        <tr>
                                            <th class="d-none">#</th>
                                            <th>Peg</th>
                                            <th>Name</th>
                                            @if ($event->tournament->isTeam())
                                                <th>Team</th>
                                            @endif
                                            <th>Weight (kg)</th>
                                            <th class="d-none">Ranking</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($zone['draws'] as $index => $draw)
                                            <?php
                                                $angler = $draw->angler;
                                                $teamName = optional(optional($angler)->team)->name;
                                                $existingWeight = $angler ? $weightMap->get($angler->id) : null;
                                                $currentValue = $angler
                                                    ? old("weights.{$zone['name']}.{$angler->id}", optional($existingWeight)->weight_value)
                                                    : null;
                                                $isReadonly = $zone['locked'];
                                            ?>
                                            <tr>
                                                <td class="d-none">{{ $index + 1 }}</td>
                                                <td class="peg-col text-nowrap" style="width: 70px;">{{ $draw->peg_number }}</td>
                                                <td>{{ $draw->frozen_angler_name ?? optional($angler)->name ?? '—' }}</td>
                                                @if ($event->tournament->isTeam())
                                                    <td>{{ $draw->frozen_team_name ?? $teamName ?? '-' }}</td>
                                                @endif
                                                <td style="width: 140px;">
                                                    @if($angler)
                                                        <input type="number"
                                                               name="weights[{{ $zone['name'] }}][{{ $angler->id }}]"
                                                               value="{{ $currentValue }}"
                                                               class="form-control form-control-sm weight-input{{ $isReadonly ? ' bg-light' : '' }}"
                                                               min="0"
                                                               step="1"
                                                               placeholder="0"
                                                               @if($isReadonly) readonly tabindex="-1" @endif>
                                                    @else
                                                        <em class="text-muted">N/A</em>
                                                    @endif
                                                </td>
                                                <td class="text-center d-none">
                                                    {{-- Piazzamento nascosto: da mostrare quando aggiornato in tempo reale durante inserimento pesi --}}
                                                    <span class="text-muted">-</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div>
                                <small class="text-muted">
                                    Leave blank to remove a saved weight. Confirmed zones can no longer be edited.
                                </small>
                            </div>
                        </div>
                    </div>
                @endforeach
            </form>

            <div class="d-flex flex-column flex-lg-row justify-content-lg-end gap-2 mt-4">
                <button type="submit"
                        class="btn btn-primary btn-lg"
                        form="weights-bulk-form">
                    Save all zones
                </button>
                <button type="submit"
                        class="btn btn-success btn-lg"
                        form="weights-confirm-all"
                        onclick="return confirm('Confirm all weights? Complete zones will be confirmed and no longer editable.');">
                    Confirm all weights
                </button>
            </div>

            <form id="weights-confirm-all" action="{{ route('weights.lock_zone', $event) }}" method="post" class="d-none">
                @csrf
                @foreach($zonesData as $zone)
                    <input type="hidden" name="zone_names[]" value="{{ $zone['name'] }}">
                @endforeach
                <input type="hidden" name="zone_name" value="__bulk__">
            </form>
            @foreach($zonesData as $zone)
                <form id="{{ $zone['lock_form_id'] }}" action="{{ route('weights.lock_zone', $event) }}" method="post" class="d-none">
                    @csrf
                    <input type="hidden" name="zone_name" value="{{ $zone['name'] }}">
                </form>
                <form id="{{ $zone['unlock_form_id'] }}" action="{{ route('weights.unlock_zone', $event) }}" method="post" class="d-none">
                    @csrf
                    <input type="hidden" name="zone_name" value="{{ $zone['name'] }}">
                </form>
            @endforeach
        @endif
    </div>
</div>
@endsection
