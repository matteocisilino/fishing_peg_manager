@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <h1 class="mb-4">Create new tournament</h1>

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
            $teamSizeDefault = (int)old('team_size', 2);
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
              action="{{ route('tournaments.store') }}"
              id="tournamentForm"
              data-mode="team"
              data-context="create"
              data-team-size="{{ $teamSizeDefault }}"
              data-can-choose-event-structure="{{ $canChooseEventStructure ? '1' : '0' }}"
              data-event-structure-options='@json($eventStructureOptions)'
              data-default-event-structure="{{ $defaultStructureId }}"
              data-structure-lakes='@json($structureLakeMap)'
              data-active-structure="{{ $defaultStructureId }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Tournament name</label>
                <input type="text" name="t_name" class="form-control" value="{{ old('t_name') }}" required>
            </div>
            @include('tournaments.partials.structure_field', ['structures' => $structures, 'defaultStructure' => $defaultStructure])
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>General parameters</span>
                    <small class="text-muted">Valid for all events</small>
                </div>
                <div class="card-body">
                    <div class="row g-4 align-items-start">
                        <div class="col-md-4">
                            <div class="form-label fw-semibold">Sectors</div>
                            <?php $selectedSector = (int)old('sector_size', 5); ?>
                            <div class="sector-size-group d-flex align-items-center flex-wrap gap-2">
                                <span class="small text-muted">Sectors of</span>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Sector size">
                                    <input class="btn-check" type="radio" name="sector_size" id="sector_size_5_team" value="5" {{ $selectedSector === 5 ? 'checked' : '' }}>
                                    <label class="btn btn-outline-primary" for="sector_size_5_team">5</label>
                                    <input class="btn-check" type="radio" name="sector_size" id="sector_size_10_team" value="10" {{ $selectedSector === 10 ? 'checked' : '' }}>
                                    <label class="btn btn-outline-primary" for="sector_size_10_team">10</label>
                                </div>
                                <span class="small text-muted">pegs</span>
                            </div>
                            <small class="text-muted d-block mt-2">The chosen block will be used in every draw.</small>
                        </div>
                        <div class="col-md-4">
                            <div class="form-label fw-semibold">Edge pegs</div>
                            <?php $edgeChecked = (bool)old('allow_edge_repeats', false); ?>
                            <input type="hidden" name="allow_edge_repeats" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="allow_edge_repeats" id="allow_edge_repeats_team" value="1" {{ $edgeChecked ? 'checked' : '' }}>
                                <label class="form-check-label" for="allow_edge_repeats_team">
                                    Allow repetitions at edges
                                </label>
                            </div>
                            <small class="text-muted d-block mt-2">Disable to prevent the same angler from ending up twice on the first or last pegs.</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="team_size_input">Team size:</label>
                            <input type="number" id="team_size_input" name="team_size" class="form-control input-counter" min="1" value="{{ $teamSizeDefault }}" required>
                            <small class="text-muted d-block mt-2">All teams must have exactly this number of members.</small>
                        </div>
                    </div>
                </div>
            </div>
            @include('tournaments.partials.lake_selector', [
                'structures' => $structures,
                'defaultStructure' => $defaultStructure,
                'structureLakeSelections' => $structureLakeSelections ?? [],
            ])

            <div class="card mb-4" data-lake-dependent hidden>
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Zones and pegs</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addZoneBtn">+ Add zone</button>
                </div>
                <div class="card-body">
                    <p class="text-muted">Define the peg intervals for each zone. Zones <strong>must not overlap</strong>.</p>
                    <div class="alert alert-info small" data-lake-summary>
                        Loading peg availability...
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="zonesTable" data-autoseed="1">
                            <thead>
                                <tr>
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
                                        ?>
                                        @if (trim((string)$zn) !== '' || trim((string)$zs) !== '' || trim((string)$ze) !== '')
                                            <tr class="zone-row">
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

            <div class="card mb-4" data-lake-dependent hidden>
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Teams</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addTeamBtn">+ Add team</button>
                </div>
                <div class="card-body" id="teamsContainer">
                    <p class="text-muted">Add as many teams as you want. A team cannot have multiple participants in the same zone and must have exactly {{ $teamSizeDefault }} participants.</p>
                    <?php
                        $oldTeamNames = old('team_name', []);
                        $oldMemberNames = old('member_name', []);
                        $oldMemberZones = old('member_zone', []);
                    ?>
                    @if (!empty($oldTeamNames))
                        @foreach ($oldTeamNames as $tIndex => $teamName)
                            <script>
                                window.__restore_team_{{ $tIndex }} = {!! json_encode([
                                    'team_name' => $teamName,
                                    'members' => $oldMemberNames[$tIndex] ?? [],
                                    'zones' => $oldMemberZones[$tIndex] ?? [],
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};
                            </script>
                        @endforeach
                    @endif
                </div>
            </div>

            <div class="card mb-4" data-lake-dependent hidden>
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Events</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addEventBtn">+ Add event</button>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Insert one or more events. For each event indicate date and name.
                        @if($canChooseEventStructure)
                            You can also associate the facility that hosts that event.
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
                                    <?php
                                        $selectedStructureId = (int)($oldEventStructures[$idx] ?? $defaultEventStructureId);
                                    ?>
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

            <div class="mb-3" data-lake-dependent hidden>
                <button type="submit" class="btn btn-success">Save tournament</button>
                <a href="{{ route('tournaments.index') }}" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>
@include('tournaments._participant_datalist')
@endsection
