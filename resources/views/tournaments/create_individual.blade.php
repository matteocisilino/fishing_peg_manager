@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <h1 class="mb-4">Create individual tournament</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <?php
            $user = auth()->user();
            $canChooseEventStructure = $user?->isSuperAdmin();
            $eventStructureOptions = ($structures ?? collect())->map(fn ($structure) => [
                'id' => $structure->id,
                'name' => $structure->name,
            ])->values();
            $defaultStructureId = optional($defaultStructure)->id;
            $defaultEventStructureId = $defaultStructureId;
        ?>
        <form method="post"
              action="{{ route('tournaments.store_individual') }}"
              id="tournamentForm"
              data-mode="individual"
              data-active-participants="0"
              data-can-choose-event-structure="{{ $canChooseEventStructure ? '1' : '0' }}"
              data-event-structure-options='@json($eventStructureOptions)'
              data-default-event-structure="{{ $defaultEventStructureId }}"
              data-structure-lakes='@json($structureLakeMap)'
              data-active-structure="{{ $defaultStructureId }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Tournament name</label>
                <input type="text" name="t_name" class="form-control" value="{{ old('t_name') }}" required>
            </div>
            @include('tournaments.partials.structure_field', ['structures' => $structures, 'defaultStructure' => $defaultStructure])
            @include('tournaments.partials.lake_selector', [
                'structures' => $structures,
                'defaultStructure' => $defaultStructure,
                'structureLakeSelections' => $structureLakeSelections ?? [],
            ])
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Draw parameters</span>
                    <small class="text-muted">Applied to all events</small>
                </div>
                <div class="card-body">
                    <div class="row g-4 align-items-start">
                        <div class="col-md-6">
                            <div class="form-label fw-semibold">Sectors</div>
                            <?php $selectedSector = (int)old('sector_size', 5); ?>
                            <div class="sector-size-group d-flex align-items-center flex-wrap gap-2">
                                <span class="small text-muted">Sectors of</span>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Dimensione settori individuale">
                                    <input class="btn-check" type="radio" name="sector_size" id="sector_size_5_individual" value="5" {{ $selectedSector === 5 ? 'checked' : '' }}>
                                    <label class="btn btn-outline-primary" for="sector_size_5_individual">5</label>
                                    <input class="btn-check" type="radio" name="sector_size" id="sector_size_10_individual" value="10" {{ $selectedSector === 10 ? 'checked' : '' }}>
                                    <label class="btn btn-outline-primary" for="sector_size_10_individual">10</label>
                                </div>
                                <span class="small text-muted">pegs</span>
                            </div>
                            <small class="text-muted d-block mt-2">The chosen block will apply to every draw.</small>
                        </div>
                        <div class="col-md-6">
                            <div class="form-label fw-semibold">Edge pegs</div>
                            <?php $edgeChecked = (bool)old('allow_edge_repeats', false); ?>
                            <input type="hidden" name="allow_edge_repeats" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="allow_edge_repeats" id="allow_edge_repeats_individual" value="1" {{ $edgeChecked ? 'checked' : '' }}>
                                <label class="form-check-label" for="allow_edge_repeats_individual">
                                    Allow repetitions at edges
                                </label>
                            </div>
                            <small class="text-muted d-block mt-2">Disable to keep the automatic block on repetitions.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Zones and pegs</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addZoneBtn">+ Add zone</button>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Insert the peg intervals for each zone, without overlapping.
                        Any technical sectors (less than {{ $selectedSector }} pegs) must be positioned before the next complete zone.
                    </p>
                    <div class="alert alert-info small" data-lake-summary>
                        Loading peg availability...
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="zonesTable" data-autoseed="0">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 60px;">Tech.</th>
                                    <th>Zone name</th>
                                    <th>From peg</th>
                                    <th>To peg</th>
                                    <th style="width: 80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                    $oldZoneNames = old('zone_name', []) ?? [];
                                    $oldZoneStarts = old('zone_start', []) ?? [];
                                    $oldZoneEnds = old('zone_end', []) ?? [];
                                ?>
                                @if (!empty(array_filter($oldZoneNames ?? [])))
                                    @foreach (($oldZoneNames ?? []) as $idx => $zn)
                                        <?php
                                            $zs = $oldZoneStarts[$idx] ?? '';
                                            $ze = $oldZoneEnds[$idx] ?? '';
                                            $techSelected = old('zone_is_technical') !== null
                                                ? (int)old('zone_is_technical') === $idx
                                                : (bool)old('zone_is_technical.' . $idx);
                                        ?>
                                        @if (trim((string)$zn) !== '' || trim((string)$zs) !== '' || trim((string)$ze) !== '')
                                            <tr class="zone-row">
                                                <td class="text-center">
                                                    <input class="form-check-input zone-technical-radio" type="radio" name="zone_is_technical" value="{{ $idx }}" @checked($techSelected)>
                                                </td>
                                            <td><input type="text" name="zone_name[]" value="{{ $zn }}" class="form-control form-control-sm"></td>
                                            <td><input type="text" name="zone_start[]" value="{{ $zs }}" class="form-control form-control-sm input-peg zone-peg-start"></td>
                                            <td><input type="text" name="zone_end[]" value="{{ $ze }}" class="form-control form-control-sm input-peg zone-peg-end"></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline-danger remove-zone-btn">✕</button>
                                            </td>
                                        </tr>
                                        @endif
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-danger d-none" id="zonesError"></div>
                </div>
            </div>
            <div class="border rounded p-3 mb-4" id="zonePegChooser" data-peg-chooser>
                <div class="text-muted small">Select a peg from the chosen lakes to fill the "From" / "To" fields.</div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Participants</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addParticipantBtn">+ Add participant</button>
                </div>
                <div class="card-body" id="participantsContainer" data-name-field="participant_name[]">
                    <p class="text-muted">Insert all the anglers who will participate. The maximum number equals the available pegs.</p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="participantsTable">
                            <thead>
                                <tr>
                                    <th>Participant name</th>
                                    <th style="width: 80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $oldParticipants = old('participant_name', []); ?>
                                @foreach ($oldParticipants as $participant)
                                    @if (trim((string)$participant) !== '')
                                        <tr class="participant-row">
                                            <td><input type="text" name="participant_name[]" value="{{ $participant }}" class="form-control form-control-sm" list="participantSuggestions" autocomplete="off" required></td>
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
                        You have reached the maximum number of active participants allowed by the pegs.
                    </div>
                    <div class="alert alert-danger d-none mt-3" id="participantsOverPegsAlert">
                        Participants exceed the available pegs. Reduce the number or increase the pegs.
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Events</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addEventBtn">+ Add event</button>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Define one or more events indicating date and name.
                        @if($canChooseEventStructure)
                            You can assign the facility where each event will take place.
                        @endif
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="eventsTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Event name</th>
                                    @if($canChooseEventStructure)
                                        <th>Facility</th>
                                    @endif
                                    <th>Status</th>
                                    <th style="width: 80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                    $oldEventDates = old('event_date', []);
                                    $oldEventNames = old('event_name', []);
                                    $oldEventStructures = old('event_structure_id', []);
                                ?>
                                @foreach ($oldEventDates as $idx => $date)
                                    <?php $selectedStructureId = (int)($oldEventStructures[$idx] ?? $defaultEventStructureId); ?>
                                    <tr>
                                        <input type="hidden" name="event_id[]" value="0">
                                        @unless($canChooseEventStructure)
                                            <input type="hidden" name="event_structure_id[]" value="{{ $selectedStructureId }}">
                                        @endunless
                                        <td><input type="date" name="event_date[]" class="form-control form-control-sm" value="{{ $date }}"></td>
                                        <td><input type="text" name="event_name[]" class="form-control form-control-sm" value="{{ $oldEventNames[$idx] ?? '' }}"></td>
                                        @if($canChooseEventStructure)
                                            <td>
                                                <select name="event_structure_id[]" class="form-select form-select-sm" required>
                                                    <option value="">Select facility</option>
                                                    @foreach($structures as $structure)
                                                        <option value="{{ $structure->id }}" @selected($selectedStructureId === $structure->id)>
                                                            {{ $structure->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </td>
                                        @endif
                                        <td><span class="badge bg-secondary">New</span></td>
                                        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-event-btn">✕</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <button type="submit" class="btn btn-success">Save tournament</button>
                <a href="{{ route('tournaments.index') }}" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>
@include('tournaments._participant_datalist')
@endsection
