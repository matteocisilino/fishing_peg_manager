@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <h1 class="mb-3">{{ $event->name }} ({{ $event->event_date->format('Y-m-d') }})</h1>
        <p>Tournament: <a href="{{ route('tournaments.show', $event->tournament) }}">{{ $event->tournament->name }}</a></p>
        <p class="text-muted">
            Host venue: <strong>{{ $event->structure->name }}</strong>
        </p>
        <a href="{{ route('tournaments.index') }}" class="btn btn-link mb-3">&laquo; Back to home</a>

        @if($draws->isEmpty())
            <div class="alert alert-info">No draw has been made yet for this event.</div>
            @if(!$event->is_locked)
                <form method="post" action="{{ route('events.draw', $event) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Generate the draw for this event?');">Generate draw</button>
                </form>
            @else
                <div class="alert alert-success mt-3">This event is locked, but it has no registered draws.</div>
            @endif
        @else
            <div class="mb-3 d-flex gap-2 flex-wrap">
                @if(!$event->is_locked)
                    <form method="post" action="{{ route('events.draw', $event) }}" onsubmit="return confirm('Regenerate the draw overwriting the existing one?');">
                        @csrf
                        <button type="submit" class="btn btn-warning">Regenerate draw</button>
                    </form>
                    <form method="post" action="{{ route('events.consolidate', $event) }}" onsubmit="return confirm('Permanently lock this draw?');">
                        @csrf
                        <button type="submit" class="btn btn-success">✔ Lock draw</button>
                    </form>
                @else
                    <div class="alert alert-success d-flex justify-content-between align-items-center mb-0 w-100">
                        <span>This draw has been <strong>locked</strong>.</span>
                        @if(auth()->user()?->isSuperAdmin())
                            <form method="post" action="{{ route('events.unlock', $event) }}" onsubmit="return confirm('Unlock this draw? All previous lock operations will be cancelled.');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger">Unlock draw</button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            @if(!empty($pegOverview))
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Pegs reminder</span>
                        <small class="text-muted">The colored ones are already assigned</small>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            @foreach($pegOverview as $zone)
                                <div class="col-12 col-md-6">
                                    <div class="peg-overview-zone">
                                        <div class="fw-semibold mb-2">{{ $zone['label'] }}</div>
                                        <div class="peg-badge-list d-flex flex-wrap gap-2">
                                            @foreach($zone['pegs'] as $pegNumber)
                                                <span class="badge peg-badge text-secondary border" data-peg-badge="{{ $pegNumber }}">
                                                    {{ $pegNumber }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Peg draw</span>
                    @if(!$event->is_locked)
                        <small class="text-muted">Change the number and save before locking</small>
                    @endif
                </div>
                <div class="card-body">
                    @if(!$event->is_locked)
                        <form method="post" action="{{ route('events.draw.manual', $event) }}">
                            @csrf
                    @endif
                    <div class="table-responsive">
                        <table class="table table-sm align-middle {{ $isPoolEvent ? 'table-pool-layout' : '' }}">
                            <thead>
                                <tr>
                                    <th class="{{ $isPoolEvent ? 'col-zone-tight' : '' }}">Zone</th>
                                    <th class="{{ $isPoolEvent ? 'col-sector-tight' : '' }}">Sector</th>
                                    <th class="{{ $isPoolEvent ? 'col-peg-wide' : '' }}" style="{{ $isPoolEvent ? '' : 'width: 130px;' }}">Peg</th>
                                    <th>Participant</th>
                                    @if($showTeamColumn)
                                        <th>Team</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($draws as $draw)
                                    <tr>
                                        <td class="{{ $isPoolEvent ? 'col-zone-tight' : '' }}">{{ $draw['zone_name'] }}</td>
                                        <td class="{{ $isPoolEvent ? 'col-sector-tight' : '' }}">
                                            @if($draw['sector_label'])
                                                {{ $draw['sector_label'] }} ({{ $draw['sector_start'] }}&ndash;{{ $draw['sector_end'] }})
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="{{ $isPoolEvent ? 'col-peg-wide' : '' }}">
                                            @if($event->is_locked)
                                                {{ $draw['peg_number'] }}
                                            @else
                                                <input type="text"
                                                       name="draws[{{ $draw['id'] }}]"
                                                       class="form-control form-control-sm @error('draws.'.$draw['id']) is-invalid @enderror"
                                                       data-peg-input="1"
                                                       data-peg-row="{{ $draw['id'] }}"
                                                       value="{{ old('draws.'.$draw['id'], $draw['peg_number']) }}"
                                                       maxlength="50"
                                                       pattern="[A-Za-z0-9]+"
                                                       inputmode="text"
                                                        required>
                                                @error('draws.'.$draw['id'])
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            @endif
                                        </td>
                                        <td>{{ $draw['angler_name'] }}</td>
                                        @if($showTeamColumn)
                                            <td>{{ $draw['team_name'] ?? '-' }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if(!$event->is_locked)
                        <div class="text-end mt-3">
                            <button type="submit" class="btn btn-outline-primary">
                                Save manual assignments
                            </button>
                        </div>
                        </form>
                        <p class="text-muted mt-2 mb-0">
                            You can continue to use the "Regenerate draw" button at any time. Manual changes are saved immediately and will be locked by clicking on "✔ Lock draw".
                        </p>
                    @else
                        <p class="text-muted mt-2 mb-0">
                            Sectors are automatically calculated in sectors of {{ $event->tournament->sector_size }} pegs.
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@push('styles')
<style>
    .peg-badge-list {
        row-gap: 0.35rem;
    }
    .peg-badge {
        background-color: #f8f9fa;
        border-color: #ced4da;
        color: #6c757d;
        font-weight: 600;
    }
    .peg-badge.badge-available {
        background-color: #0d6efd;
        border-color: #084298;
        color: #fff !important;
        font-weight: 700;
    }
    .peg-badge.badge-used {
        background-color: #dee2e6;
        border-color: #6c757d;
        color: #495057;
    }
    .peg-badge.badge-duplicate {
        background-color: #8b0000;
        border-color: #5a0000;
        color: #fff !important;
        font-weight: 700;
    }
    .peg-row-duplicate {
        background-color: #fff5f5 !important;
    }
</style>
@if($isPoolEvent)
<style>
    .table-pool-layout .col-zone-tight {
        width: 180px;
        max-width: 180px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .table-pool-layout .col-sector-tight {
        width: 110px;
        max-width: 110px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .table-pool-layout .col-peg-wide {
        width: 200px;
        min-width: 200px;
    }
</style>
@endif
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const badgesByPeg = {};
    document.querySelectorAll('[data-peg-badge]').forEach((badge) => {
        const peg = badge.getAttribute('data-peg-badge');
        if (!badgesByPeg[peg]) {
            badgesByPeg[peg] = [];
        }
        badgesByPeg[peg].push(badge);
    });

    if (Object.keys(badgesByPeg).length === 0) {
        return;
    }

    const pegInputs = document.querySelectorAll('[data-peg-input]');
    const lockedAssigned = pegInputs.length === 0
        ? new Set(
            (@json(array_map('strval', $assignedPegNumbers ?? [])) || []).map(
                (value) => String(value).toUpperCase()
            )
        )
        : new Set();

    const normalize = (value) => {
        if (value === null || value === undefined) {
            return null;
        }
        const trimmed = String(value).trim();
        if (trimmed === '') {
            return null;
        }
        return trimmed.toUpperCase();
    };

    const refreshBadges = () => {
        const used = new Map();
        const rowDuplicates = new Set();
        if (pegInputs.length > 0) {
            pegInputs.forEach((input) => {
                const normalized = normalize(input.value);
                if (normalized !== null) {
                    const count = (used.get(normalized) ?? 0) + 1;
                    used.set(normalized, count);
                    if (count > 1) {
                        rowDuplicates.add(normalized);
                    }
                }
            });
        }

        Object.entries(badgesByPeg).forEach(([peg, elements]) => {
            const key = normalize(peg);
            const count = key !== null ? (used.get(key) ?? 0) : 0;
            const assigned = count > 0 || lockedAssigned.has(key);
            elements.forEach((element) => {
                element.classList.remove('badge-available', 'badge-used', 'badge-duplicate');
                if (!assigned) {
                    element.classList.add('badge-available');
                } else if (count > 1) {
                    element.classList.add('badge-duplicate');
                } else {
                    element.classList.add('badge-used');
                }
            });
        });

        document.querySelectorAll('[data-peg-row]').forEach((input) => {
            const row = input.closest('tr');
            if (!row) {
                return;
            }
            const normalized = normalize(input.value);
            const isDuplicate = normalized !== null && rowDuplicates.has(normalized);
            row.classList.toggle('peg-row-duplicate', isDuplicate);
        });
    };

    if (pegInputs.length > 0) {
        pegInputs.forEach((input) => {
            input.addEventListener('input', () => {
                const selectionStart = input.selectionStart;
                const selectionEnd = input.selectionEnd;
                const normalizedValue = input.value.toUpperCase();
                if (normalizedValue !== input.value) {
                    input.value = normalizedValue;
                    if (selectionStart !== null && selectionEnd !== null) {
                        input.setSelectionRange(selectionStart, selectionEnd);
                    }
                }
                refreshBadges();
            });
            input.addEventListener('change', () => {
                input.value = input.value.toUpperCase();
                refreshBadges();
            });
        });
    }

    refreshBadges();
});
</script>
@endpush
