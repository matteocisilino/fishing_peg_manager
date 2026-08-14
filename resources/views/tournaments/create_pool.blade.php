@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <h1 class="mb-4">Create pool tournament</h1>

        <?php
            $poolCapacityDefault = (int)old('pool_capacity', 10);
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
              action="{{ route('tournaments.store_pool') }}"
              id="tournamentForm"
              data-mode="pool"
              data-pool-capacity="{{ $poolCapacityDefault }}"
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
            <div class="mb-4">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <label class="form-label mb-0" for="pool_capacity_create">Maximum number of participants</label>
                    <input type="number" id="pool_capacity_create" name="pool_capacity" class="form-control input-counter" min="1" value="{{ $poolCapacityDefault }}" required>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-pool-capacity-max>MAX</button>
                </div>
                <div class="form-text">
                    It will be possible to insert participants up to this limit and in any case not exceeding the number of available pegs.
                </div>
                <div class="alert alert-warning mt-2 d-none" data-pool-capacity-alert>
                    The maximum number of participants exceeds the pegs available from the chosen lakes.<br>
                    A) Add another lake if available.<br>
                    B) Reduce the maximum number because the selected lakes do not allow it.
                </div>
            </div>

            <div class="card mb-4" data-lake-dependent hidden>
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
                        Define the zones and, for each one, activate the available pegs using the ON/OFF buttons.
                        Each peg can belong to only one zone.
                    </p>
                    <div class="alert alert-info small mb-3" data-lake-summary>
                        Loading peg availability...
                    </div>
                    <?php
                        $oldZoneNames = old('pool_zone_name');
                        $oldZonePegs = old('pool_zone_pegs');
                        if (!is_array($oldZoneNames) || !is_array($oldZonePegs) || count($oldZoneNames) === 0) {
                            $oldZoneNames = ['Zone A'];
                            $oldZonePegs = [''];
                        }
                    ?>
                    @foreach ($oldZoneNames as $index => $zoneName)
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
                            <input type="hidden" name="pool_zone_pegs[]" data-peg-output value="{{ $oldZonePegs[$index] ?? '' }}">
                            <div class="form-text">Click the buttons below to enable/disable the pegs.</div>
                            <div class="peg-selector mt-2" data-peg-selector></div>
                        </div>
                    @endforeach
                    <div class="alert alert-warning d-none" id="poolPegsAlert">
                        Insert at least one zone with valid pegs.
                    </div>
                </div>
            </div>

            <div class="card mb-4" data-lake-dependent hidden>
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Participants</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addParticipantBtn">+ Add participant</button>
                </div>
                <div class="card-body" id="participantsContainer" data-name-field="participant_name[]">
                    <p class="text-muted">
                        Insert the anglers who will participate in the pool. It is not possible to exceed the maximum allowed number or the available pegs.
                    </p>
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
                    <div class="alert alert-danger d-none mt-3" id="participantsDuplicateAlert">
                        There are duplicate participant names. Make them unique before saving.
                    </div>
                    <div class="alert alert-warning d-none mt-3" id="participantsLimitAlert">
                        You have reached the maximum allowed number of participants.
                    </div>
                </div>
            </div>

            <div class="card mb-4" data-lake-dependent hidden>
                <div class="card-header">Single event</div>
                <div class="card-body">
                    <?php
                        $defaultEventDate = old('event_date.0');
                        $defaultEventName = old('event_name.0', 'Single event');
                        $selectedEventStructure = (int)old('event_structure_id.0', $defaultEventStructureId);
                    ?>
                    <input type="hidden" name="event_id[]" value="0">
                    @unless($canChooseEventStructure)
                        <input type="hidden" name="event_structure_id[]" value="{{ $selectedEventStructure }}">
                    @endunless
                    <div class="row g-3">
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">Date</label>
                            <input type="date" name="event_date[]" class="form-control form-control-sm" value="{{ $defaultEventDate }}">
                        </div>
                        <div class="col-md-5 col-lg-5">
                            <label class="form-label">Event name</label>
                            <input type="text" name="event_name[]" class="form-control form-control-sm" value="{{ $defaultEventName }}">
                        </div>
                        @if($canChooseEventStructure)
                            <div class="col-md-3 col-lg-4">
                                <label class="form-label">Facility</label>
                                <select name="event_structure_id[]" class="form-select form-select-sm" required>
                                    <option value="">Select facility</option>
                                    @foreach($structures as $structure)
                                        <option value="{{ $structure->id }}" @selected($selectedEventStructure === $structure->id)>
                                            {{ $structure->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mb-3 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-success" data-lake-dependent hidden>Save pool tournament</button>
                <a href="{{ route('tournaments.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@include('tournaments._participant_datalist')
@endsection
