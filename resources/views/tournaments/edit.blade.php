@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <h1 class="mb-3">Edit tournament - {{ $tournament->name }}</h1>
        <a href="{{ route('tournaments.show', $tournament) }}" class="btn btn-link mb-3">&laquo; Back to tournament details</a>

        <?php
            $formMode = $tournament->isTeam()
                ? 'team'
                : ($tournament->isPool() ? 'pool' : 'individual');
            $totalPegs = $zones->sum(function ($zone) { return $zone->end_number - $zone->start_number + 1; });
            $participantAbsenceState = [];
            $activeParticipantsCount = 0;
            if ($tournament->isIndividual() || $tournament->isPool()) {
                foreach ($participants as $participant) {
                    $isAbsentOld = (bool) old("participant_absent.{$participant->id}", $participant->is_absent);
                    $participantAbsenceState[$participant->id] = $isAbsentOld;
                    if (!$isAbsentOld) {
                        $activeParticipantsCount++;
                    }
                }
            }
            $poolPegCount = $tournament->isPool() ? $poolPegs->count() : 0;
            $user = auth()->user();
            $canChooseEventStructure = $user?->isSuperAdmin();
            $eventStructureOptions = ($structures ?? collect())->map(fn ($structure) => [
                'id' => $structure->id,
                'name' => $structure->name,
            ])->values();
            $defaultEventStructureId = $tournament->structure_id ?? optional($defaultStructure)->id;
        ?>
        <form method="post" action="{{ route('tournaments.update', $tournament) }}" id="tournamentForm"
              data-mode="{{ $formMode }}"
              data-context="edit"
              data-total-pegs="{{ $totalPegs }}"
              @if($tournament->isTeam())
                  data-team-size="{{ old('team_size', $tournament->team_size) }}"
              @endif
              @if($tournament->isIndividual() || $tournament->isPool())
                  data-active-participants="{{ $activeParticipantsCount }}"
              @endif
              @if($tournament->isPool())
                  data-pool-capacity="{{ old('pool_capacity', $tournament->pool_capacity) }}"
                  data-pool-peg-count="{{ $poolPegCount }}"
              @endif
              data-can-choose-event-structure="{{ $canChooseEventStructure ? '1' : '0' }}"
              data-event-structure-options='@json($eventStructureOptions)'
              data-default-event-structure="{{ $defaultEventStructureId }}"
              data-structure-lakes='@json($structureLakeMap ?? [])'
              data-active-structure="{{ old('structure_id', $tournament->structure_id) }}"
        >
            @csrf
            @method('PUT')
            <div class="mb-3">
                <label class="form-label">Tournament name</label>
                <input type="text" name="t_name" class="form-control" value="{{ old('t_name', $tournament->name) }}" required>
            </div>
            @include('tournaments.partials.structure_field', ['structures' => $structures, 'defaultStructure' => $defaultStructure ?? $tournament->structure])
            @include('tournaments.partials.lake_selector', [
                'structures' => $structures,
                'defaultStructure' => $defaultStructure ?? $tournament->structure,
                'structureLakeSelections' => $structureLakeSelections ?? [],
            ])
            @if (!$tournament->isPool())
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Draw parameters</span>
                        <small class="text-muted">Valid for all events</small>
                    </div>
                    <div class="card-body">
                        <div class="row g-4 align-items-start">
                            <div class="col-md-4">
                                <div class="form-label fw-semibold">Sectors</div>
                                <?php $selectedSector = (int)old('sector_size', $tournament->sector_size); ?>
                                <div class="sector-size-group d-flex align-items-center flex-wrap gap-2">
                                    <span class="small text-muted">Sectors of</span>
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Sector size">
                                        <input class="btn-check" type="radio" name="sector_size" id="sector_size_5_edit" value="5" {{ $selectedSector === 5 ? 'checked' : '' }}>
                                        <label class="btn btn-outline-primary" for="sector_size_5_edit">5</label>
                                        <input class="btn-check" type="radio" name="sector_size" id="sector_size_10_edit" value="10" {{ $selectedSector === 10 ? 'checked' : '' }}>
                                        <label class="btn btn-outline-primary" for="sector_size_10_edit">10</label>
                                    </div>
                                    <span class="small text-muted">pegs</span>
                                </div>
                                <small class="text-muted d-block mt-2">The choice affects all future draws.</small>
                            </div>
                            <div class="col-md-4">
                                <div class="form-label fw-semibold">Edge pegs</div>
                                <?php $edgeChecked = (bool)old('allow_edge_repeats', $tournament->allow_edge_repeats); ?>
                                <input type="hidden" name="allow_edge_repeats" value="0">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="allow_edge_repeats" id="allow_edge_repeats_edit" value="1" {{ $edgeChecked ? 'checked' : '' }}>
                                    <label class="form-check-label" for="allow_edge_repeats_edit">
                                        Allow repetitions at edges
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-2">Disable to keep the block on the same pegs.</small>
                            </div>
                            @if ($tournament->isTeam())
                                <div class="col-md-4">
                                    <?php $teamSizeValue = (int)old('team_size', $tournament->team_size); ?>
                                    <label class="form-label fw-semibold" for="team_size_edit">Team size:</label>
                                    <input type="number" id="team_size_edit" name="team_size" class="form-control input-counter" min="1" value="{{ $teamSizeValue }}" required>
                                    <small class="text-muted d-block mt-2">All teams must respect this number.</small>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if ($tournament->isPool())
                <div class="mb-4">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <label class="form-label mb-0" for="pool_capacity_edit">Maximum number of participants</label>
                        <input type="number" id="pool_capacity_edit" name="pool_capacity" class="form-control input-counter" min="1" value="{{ old('pool_capacity', $tournament->pool_capacity) }}" required>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-pool-capacity-max>MAX</button>
                    </div>
                    <div class="form-text">Participants cannot exceed this value and the available pegs.</div>
                    <div class="alert alert-warning mt-2 d-none" data-pool-capacity-alert>
                        The maximum number of participants exceeds the pegs available from the chosen lakes.<br>
                        A) Add another lake if available.<br>
                        B) Reduce the maximum number because the selected lakes do not allow it.
                    </div>
                </div>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Zones and pegs</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addPoolZoneBtn">+ Add zone</button>
                    </div>
                    <div class="card-body" id="poolZonesContainer">
                        @if($errors->has('pool_pegs'))
                            <div class="alert alert-danger">
                                {{ $errors->first('pool_pegs') }}
                            </div>
                        @endif
                        <p class="text-muted">
                            Update the list of zones and pegs. Use the ON/OFF buttons to select the pegs, the fields are updated automatically.
                        </p>
                        <?php
                            $groupedPoolPegs = $poolPegs->groupBy('zone_name')->map(function ($items) {
                                return $items->pluck('peg_number')->implode(' ');
                            });
                            $oldZoneNames = old('pool_zone_name');
                            $oldZonePegs = old('pool_zone_pegs');
                            if (is_array($oldZoneNames) && is_array($oldZonePegs) && count($oldZoneNames) > 0) {
                                $zoneNamesToUse = $oldZoneNames;
                                $zonePegsToUse = $oldZonePegs;
                            } else {
                                $zoneNamesToUse = $groupedPoolPegs->keys()->all();
                                $zonePegsToUse = $groupedPoolPegs->values()->all();
                                if (empty($zoneNamesToUse)) {
                                    $zoneNamesToUse = ['Zone A'];
                                    $zonePegsToUse = [''];
                                }
                            }
                        ?>
                        @foreach ($zoneNamesToUse as $index => $zoneName)
                            <div class="pool-zone-block border rounded p-3 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="flex-grow-1 me-3">
                                        <label class="form-label">Zone name</label>
                                        <input type="text" name="pool_zone_name[]" value="{{ $zoneName }}" class="form-control form-control-sm" required>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-pool-zone-btn mt-4">✕</button>
                                </div>
                                <label class="form-label">
                                    Selected pegs <span class="text-muted small ms-2" data-peg-counter>0</span>
                                </label>
                                <input type="hidden" name="pool_zone_pegs[]" data-peg-output value="{{ $zonePegsToUse[$index] ?? '' }}">
                                <div class="form-text">Click the buttons below to enable/disable the pegs.</div>
                                <div class="peg-selector mt-2" data-peg-selector></div>
                            </div>
                        @endforeach
                        <div class="alert alert-warning d-none" id="poolPegsAlert">
                            Insert at least one zone with valid pegs.
                        </div>
                    </div>
                </div>
            @else
                <div class="card mb-4">
                    <div class="card-header">Zones (read-only)</div>
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
                            <p class="text-muted mb-0">In order not to alter the draws already made, the zones cannot be modified from here.</p>
                        @endif
                    </div>
                </div>
            @endif

            @if ($tournament->isTeam())
                <div class="card mb-4">
                    <div class="card-header">Teams and participants (substitutions)</div>
                    <div class="card-body">
                        @if ($teams->isEmpty())
                            <div class="alert alert-warning mb-0">No team present.</div>
                        @else
                            @foreach ($teams as $team)
                                <?php
                                    $teamCurrentCount = $team->anglers->count();
                                    $teamSizeValue = (int)old('team_size', $tournament->team_size);
                                    $oldNewMembers = old("team_new_member.{$team->id}", []);
                                    $oldNewZones = old("team_new_zone.{$team->id}", []);
                                    if (!is_array($oldNewMembers)) {
                                        $oldNewMembers = [];
                                    }
                                    if (!is_array($oldNewZones)) {
                                        $oldNewZones = [];
                                    }
                                ?>
                                <div class="mb-4 border rounded p-3 team-card" data-team-id="{{ $team->id }}">
                                    <div class="mb-2">
                                        <label class="form-label mb-0">Team name</label>
                                        <input type="text" name="team_name_edit[{{ $team->id }}]" class="form-control form-control-sm" value="{{ $team->name }}">
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <h6 class="mb-0">Registered participants</h6>
                                        <span class="badge bg-secondary">
                                            <span class="team-count-display" data-team-id="{{ $team->id }}">{{ $teamCurrentCount }}</span>
                                            / {{ $teamSizeValue }}
                                        </span>
                                    </div>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm align-middle team-existing-table" data-team-id="{{ $team->id }}">
                                            <thead><tr><th>Participant name</th><th>Zone</th></tr></thead>
                                            <tbody>
                                                @forelse ($team->anglers as $angler)
                                                    <tr>
                                                        <td>
                                                            <input type="text"
                                                                   name="angler_name[{{ $angler->id }}]"
                                                                   class="form-control form-control-sm"
                                                                   value="{{ $angler->name }}"
                                                                   list="participantSuggestions" autocomplete="off">
                                                        </td>
                                                        <td style="width: 160px;">
                                                            <select name="angler_zone[{{ $angler->id }}]"
                                                                    class="form-select form-select-sm team-zone-select"
                                                                    data-team-id="{{ $team->id }}">
                                                                @foreach ($zones as $zoneOption)
                                                                    <option value="{{ $zoneOption->name }}" {{ $zoneOption->name === optional($angler->zone)->name ? 'selected' : '' }}>{{ $zoneOption->name }}</option>
                                                                @endforeach
                                                            </select>
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="2"><em>No registered participant.</em></td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="text-muted small mb-3">Update the existing names or add new anglers using the following section.</p>

                                    <div class="team-edit-block"
                                         data-team-id="{{ $team->id }}"
                                         data-team-name="{{ $team->name }}"
                                         data-existing-count="{{ $teamCurrentCount }}">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span>New participants</span>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-primary add-team-member-btn"
                                                    data-team-id="{{ $team->id }}">
                                                + Add participant
                                            </button>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle">
                                                <thead>
                                                    <tr>
                                                        <th>Participant name</th>
                                                        <th>Zone</th>
                                                        <th style="width: 80px;">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="team_new_members_{{ $team->id }}">
                                                    @foreach ($oldNewMembers as $index => $newName)
                                                        <?php
                                                            $zoneValue = $oldNewZones[$index] ?? '';
                                                            $newName = is_string($newName) ? $newName : '';
                                                            $zoneValue = is_string($zoneValue) ? $zoneValue : '';
                                                        ?>
                                                        @if (trim($newName) !== '' || trim($zoneValue) !== '')
                                                            <tr class="team-new-row">
                                                                <td>
                                                                    <input type="text"
                                                                           name="team_new_member[{{ $team->id }}][]"
                                                                           value="{{ $newName }}"
                                                                           class="form-control form-control-sm"
                                                                           list="participantSuggestions" autocomplete="off">
                                                                </td>
                                                                <td style="width: 160px;">
                                                                    <select name="team_new_zone[{{ $team->id }}][]"
                                                                            class="form-select form-select-sm team-zone-select"
                                                                            data-team-id="{{ $team->id }}">
                                                                        <option value="">Select zone</option>
                                                                        @foreach ($zones as $zoneOption)
                                                                            <option value="{{ $zoneOption->name }}" {{ $zoneOption->name === $zoneValue ? 'selected' : '' }}>{{ $zoneOption->name }}</option>
                                                                        @endforeach
                                                                    </select>
                                                                </td>
                                                                <td class="text-center">
                                                                    <button type="button" class="btn btn-sm btn-outline-danger remove-team-new-row">✕</button>
                                                                </td>
                                                            </tr>
                                                        @endif
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="alert alert-warning small d-none team-limit-alert" data-team-id="{{ $team->id }}">
                                            You have reached the maximum number of participants for this team.
                                        </div>
                                        <div class="alert alert-danger d-none mt-2 team-zone-error" data-team-id="{{ $team->id }}"></div>
                                        <div class="alert alert-danger d-none mt-2 team-count-error" data-team-id="{{ $team->id }}"></div>
                                    </div>

                                    <template id="team_new_row_template_{{ $team->id }}">
                                        <tr class="team-new-row">
                                            <td>
                                                <input type="text"
                                                       name="team_new_member[{{ $team->id }}][]"
                                                       class="form-control form-control-sm"
                                                       list="participantSuggestions" autocomplete="off">
                                            </td>
                                            <td style="width: 160px;">
                                                <select name="team_new_zone[{{ $team->id }}][]"
                                                        class="form-select form-select-sm team-zone-select"
                                                        data-team-id="{{ $team->id }}">
                                                    <option value="">Select zone</option>
                                                    @foreach ($zones as $zoneOption)
                                                        <option value="{{ $zoneOption->name }}">{{ $zoneOption->name }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline-danger remove-team-new-row">✕</button>
                                            </td>
                                        </tr>
                                    </template>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            @elseif ($tournament->isPool())
                <?php
                    $poolCapacity = $tournament->pool_capacity ?? 0;
                    $effectiveCapacity = $poolPegCount > 0
                        ? ($poolCapacity > 0 ? min($poolCapacity, $poolPegCount) : $poolPegCount)
                        : $poolCapacity;
                ?>
                <div class="card mb-4">
                    <div class="card-header">Participants</div>
                    <div class="card-body">
                        <p class="text-muted">
                            Active participants: {{ $activeParticipantsCount }} / {{ $effectiveCapacity }}.
                            Edit the names or mark <strong>Absent</strong> to exclude the angler.
                        </p>
                        @if ($participants->isEmpty())
                            <div class="alert alert-warning mb-0">No participant inserted.</div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead><tr><th>#</th><th>Name</th><th>Absent?</th></tr></thead>
                                    <tbody>
                                        @foreach ($participants as $index => $participant)
                                            <tr>
                                                <td>{{ $index + 1 }}</td>
                                                <td>
                                                    <input type="text"
                                                           name="participant_existing[{{ $participant->id }}]"
                                                           class="form-control form-control-sm"
                                                           value="{{ old("participant_existing.{$participant->id}", $participant->name) }}"
                                                           list="participantSuggestions" autocomplete="off">
                                                </td>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input participant-absent-toggle"
                                                               type="checkbox"
                                                               name="participant_absent[{{ $participant->id }}]"
                                                               value="1"
                                                               {{ ($participantAbsenceState[$participant->id] ?? false) ? 'checked' : '' }}>
                                                        <label class="form-check-label">Absent</label>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Nuovi partecipanti</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addParticipantBtn">+ Aggiungi partecipante</button>
                    </div>
                    <div class="card-body" id="participantsContainer" data-name-field="participant_new_name[]">
                        <p class="text-muted">Aggiungi i nuovi pescatori che potranno prendere parte alle prossime estrazioni.</p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle" id="participantsTable">
                                <thead>
                                    <tr>
                                        <th>Participant name</th>
                                        <th style="width: 80px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $oldNewParticipants = old('participant_new_name', []); ?>
                                    @foreach ($oldNewParticipants as $participant)
                                        @if (trim((string)$participant) !== '')
                                            <tr class="participant-row">
                                                <td><input type="text" name="participant_new_name[]" value="{{ $participant }}" class="form-control form-control-sm" list="participantSuggestions" autocomplete="off" required></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-danger remove-participant-btn">✕</button>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="alert alert-warning d-none mt-3" id="participantsLimitAlert">
                            You have reached the maximum number of active participants allowed.
                        </div>
                    </div>
                </div>
            @else
                <div class="card mb-4">
                    <div class="card-header">Participants</div>
                    <div class="card-body">
                        <?php
                            $capacity = $totalPegs;
                            $activeCount = $activeParticipantsCount;
                        ?>
                        <p class="text-muted">
                            Active participants: {{ $activeCount }} / {{ $capacity }}.
                            Edit the existing names or mark <strong>Absent</strong> to exclude an angler from the draws.
                        </p>
                        @if ($participants->isEmpty())
                            <div class="alert alert-warning mb-0">No participant inserted.</div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead><tr><th>#</th><th>Name</th><th>Zone</th><th>Absent?</th></tr></thead>
                                    <tbody>
                                        @foreach ($participants as $index => $participant)
                                            <tr>
                                                <td>{{ $index + 1 }}</td>
                                                <td>
                                                    <input type="text"
                                                           name="participant_existing[{{ $participant->id }}]"
                                                           class="form-control form-control-sm"
                                                           value="{{ old("participant_existing.{$participant->id}", $participant->name) }}"
                                                           list="participantSuggestions" autocomplete="off">
                                                </td>
                                                <td>{{ optional($participant->zone)->name ?? '-' }}</td>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input participant-absent-toggle"
                                                               type="checkbox"
                                                               name="participant_absent[{{ $participant->id }}]"
                                                               value="1"
                                                               {{ ($participantAbsenceState[$participant->id] ?? false) ? 'checked' : '' }}>
                                                        <label class="form-check-label">Absent</label>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>New participants</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addParticipantBtn">+ Add participant</button>
                    </div>
                    <div class="card-body" id="participantsContainer" data-name-field="participant_new_name[]">
                        <p class="text-muted">Add the new anglers who will be able to take part in the next draws.</p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="participantsTable">
                            <thead>
                                <tr>
                                    <th>Participant name</th>
                                    <th style="width: 80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $oldNewParticipants = old('participant_new_name', []); ?>
                                @foreach ($oldNewParticipants as $participant)
                                    @if (trim((string)$participant) !== '')
                                        <tr class="participant-row">
                                            <td><input type="text" name="participant_new_name[]" value="{{ $participant }}" class="form-control form-control-sm" list="participantSuggestions" autocomplete="off" required></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline-danger remove-participant-btn">✕</button>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-danger d-none mt-3" id="participantsDuplicateAlert">
                        There are duplicate participant names. Make them unique before saving.
                    </div>
                    <div class="alert alert-warning d-none mt-3" id="participantsLimitAlert">
                        You have reached the maximum number of active participants allowed by the pegs.
                    </div>
                </div>
            </div>
            @endif

            <div class="card mb-4" @if($tournament->isPool()) data-lake-dependent hidden @endif>
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Events (dates)</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addEventBtn">+ Add event</button>
                </div>
                <div class="card-body">
                    <p class="text-muted">You can only edit/delete an event if it is not locked.</p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="eventsTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Event name</th>
                                    @if($canChooseEventStructure)
                                        <th>Venue</th>
                                    @endif
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($events as $event)
                                    <tr>
                                        <input type="hidden" name="event_id[]" value="{{ $event->id }}">
                                        @unless($canChooseEventStructure)
                                            <input type="hidden" name="event_structure_id[]" value="{{ $event->structure_id ?? $tournament->structure_id }}">
                                        @endunless
                                        <td><input type="date" name="event_date[]" class="form-control form-control-sm" value="{{ $event->event_date->format('Y-m-d') }}" {{ $event->is_locked ? 'readonly' : '' }}></td>
                                        <td><input type="text" name="event_name[]" class="form-control form-control-sm" value="{{ $event->name }}" {{ $event->is_locked ? 'readonly' : '' }}></td>
                                        @if($canChooseEventStructure)
                                            <td>
                                                <?php $selectedStructureId = $event->structure_id ?? $tournament->structure_id; ?>
                                                <select name="event_structure_id[]" class="form-select form-select-sm" {{ $event->is_locked ? 'disabled' : '' }} required>
                                                    <option value="">Select venue</option>
                                                    @foreach($structures as $structure)
                                                        <option value="{{ $structure->id }}" @selected((int)$selectedStructureId === $structure->id)>
                                                            {{ $structure->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                @if($event->is_locked)
                                                    <input type="hidden" name="event_structure_id[]" value="{{ $event->structure_id ?? $tournament->structure_id }}">
                                                @endif
                                            </td>
                                        @endif
                                        <td>
                                            @if ($event->is_locked)
                                                <span class="badge bg-success">Locked</span>
                                            @else
                                                <span class="badge bg-secondary">Editable</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if (!$event->is_locked)
                                                <button type="button" class="btn btn-sm btn-outline-danger remove-event-btn">✕</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mb-3 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-success" @if($tournament->isPool()) data-lake-dependent hidden @endif>Save changes</button>
                <a href="{{ route('tournaments.show', $tournament) }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@include('tournaments._participant_datalist')
@endsection
