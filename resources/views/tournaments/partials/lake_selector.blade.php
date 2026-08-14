@php
    /** @var \Illuminate\Support\Collection<int,\App\Models\Structure>|null $structures */
    $structuresList = $structures ?? collect();
    $defaultStructure = $defaultStructure ?? null;
    if ($defaultStructure && !$structuresList->contains('id', $defaultStructure->id)) {
        $structuresList = $structuresList->concat([$defaultStructure]);
    }
    $structureLakeSelections = $structureLakeSelections ?? [];
    $activeStructureId = (string) old('structure_id', optional($defaultStructure)->id);
    $oldLakeSelection = collect(old('structure_lake_ids', []))
        ->map(fn ($value) => (int) $value)
        ->filter(fn ($value) => $value > 0)
        ->all();
    if (!empty($oldLakeSelection) && $activeStructureId !== '') {
        $structureLakeSelections[$activeStructureId] = $oldLakeSelection;
    }
@endphp

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Lakes and pegs to use</span>
        <small class="text-muted">Only the selected lakes will be available for pegs.</small>
    </div>
    <div class="card-body">
        @forelse($structuresList as $structure)
            @php
                $structureKey = (string) $structure->id;
                $selection = $structureLakeSelections[$structureKey] ?? [];
                $isActive = $structureKey === $activeStructureId;
            @endphp
            <div class="structure-lake-group" data-structure-lake-group="{{ $structure->id }}" @if(!$isActive) hidden @endif>
                @if($structure->lakes->isEmpty())
                    <div class="alert alert-warning mb-0">
                        No lake configured for <strong>{{ $structure->name }}</strong>.
                    </div>
                @elseif($structure->lakes->count() === 1)
                    @php $lake = $structure->lakes->first(); @endphp
                    <input type="hidden"
                           name="structure_lake_ids[]"
                           value="{{ $lake->id }}"
                           data-lake-auto
                           data-structure="{{ $structure->id }}"
                           @unless($isActive) disabled @endunless>
                    <p class="text-muted mb-0">
                        This venue has only one lake: <strong>{{ $lake->name }}</strong>
                        ({{ $lake->peg_count }} pegs
                        @if($lake->peg_prefix)
                            · prefix {{ strtoupper($lake->peg_prefix) }}
                        @endif
                        ). It will be used automatically.
                    </p>
                @else
                    <p class="text-muted">
                        Select at least one lake of <strong>{{ $structure->name }}</strong> to enable the related pegs.
                    </p>
                    <div class="row">
                        @foreach($structure->lakes as $lake)
                            @php
                                $checked = in_array($lake->id, $selection, true);
                            @endphp
                            <div class="col-md-6 col-xl-4">
                                <label class="form-check lake-option w-100 mb-2">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="structure_lake_ids[]"
                                           value="{{ $lake->id }}"
                                           data-lake-checkbox
                                           data-structure="{{ $structure->id }}"
                                           @checked($checked)
                                           @unless($isActive) disabled @endunless
                                    >
                                    <span class="form-check-label ms-2">
                                        <strong>{{ $lake->name }}</strong><br>
                                        <small class="text-muted">
                                            {{ $lake->peg_count }} pegs
                                            @if($lake->peg_prefix)
                                                · Prefix {{ strtoupper($lake->peg_prefix) }}
                                            @endif
                                        </small>
                                    </span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="alert alert-warning mb-0">
                There are no venues available to configure.
            </div>
        @endforelse
        <p class="small text-muted mb-0 mt-3">
            When changing venue, the lakes associated with the selected one will be shown; at least one must be active.
        </p>
    </div>
</div>
<div class="alert alert-info" data-lake-required-warning hidden>
    Select at least one lake of the venue to configure zones, pegs, and participants.
</div>
