@php
    $user = auth()->user();
    $structures = $structures ?? collect();
    $defaultStructure = $defaultStructure ?? null;
    $selected = old('structure_id', optional($defaultStructure)->id);
@endphp

@if($user?->isSuperAdmin())
    <div class="mb-3">
        <label class="form-label" for="structure_id">Reference venue</label>
        <select name="structure_id" id="structure_id" class="form-select" required>
            <option value="">Select venue</option>
            @foreach($structures as $structure)
                <option value="{{ $structure->id }}" @selected($selected == $structure->id)>
                    {{ $structure->name }}
                </option>
            @endforeach
        </select>
        <div class="form-text">Indicates the venue that owns this tournament.</div>
    </div>
@elseif($defaultStructure)
    <input type="hidden" name="structure_id" value="{{ $defaultStructure->id }}">
    <div class="alert alert-secondary">
        The tournament will be associated with the venue <strong>{{ $defaultStructure->name }}</strong>.
    </div>
@endif
