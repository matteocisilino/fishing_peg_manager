@php
    $user = auth()->user();
    $structure = $structure ?? null;

    $existingLakes = collect();
    if (is_array(old('lake_name')) && is_array(old('lake_peg_count'))) {
        $namesOld = old('lake_name', []);
        $countsOld = old('lake_peg_count', []);
        $prefixOld = old('lake_prefix', []);
        foreach ($namesOld as $idx => $lakeName) {
            $existingLakes->push([
                'name' => $lakeName,
                'peg_count' => $countsOld[$idx] ?? '',
                'prefix' => $prefixOld[$idx] ?? '',
            ]);
        }
    } elseif($structure?->lakes && $structure->lakes->isNotEmpty()) {
        $existingLakes = $structure->lakes->map(fn ($lake) => [
            'name' => $lake->name,
            'peg_count' => $lake->peg_count,
            'prefix' => $lake->peg_prefix,
        ]);
    }
    if ($existingLakes->isEmpty()) {
        $existingLakes->push(['name' => '', 'peg_count' => '', 'prefix' => '']);
    }
@endphp

<div class="mb-3">
    <label for="name" class="form-label">Venue name</label>
    <input type="text"
           name="name"
           id="name"
           class="form-control @error('name') is-invalid @enderror"
           value="{{ old('name', $structure->name ?? '') }}"
           required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="address" class="form-label">Address</label>
    <input type="text"
           name="address"
           id="address"
           class="form-control @error('address') is-invalid @enderror"
           value="{{ old('address', $structure->address ?? '') }}"
           required>
    @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="comune" class="form-label">Municipality</label>
    <input type="text"
           name="comune"
           id="comune"
           class="form-control @error('comune') is-invalid @enderror"
           value="{{ old('comune', $structure->comune ?? '') }}">
    <small class="text-muted">Optional, used for Meteosat integrations.</small>
    @error('comune')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="google_maps_url" class="form-label">Google Maps Link</label>
    <div class="input-group">
        <input type="url"
               name="google_maps_url"
               id="google_maps_url"
               class="form-control @error('google_maps_url') is-invalid @enderror"
               value="{{ old('google_maps_url', $structure->google_maps_url ?? '') }}"
               placeholder="https://maps.app.goo.gl/...">
        <button type="button" class="btn btn-outline-secondary" data-gmaps-extract>Extract coordinates</button>
    </div>
    <small class="text-muted">
        Paste the link shared from Google Maps to automatically fill in latitude and longitude.
    </small>
    @error('google_maps_url')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <label for="latitude" class="form-label">Latitude</label>
        <input type="number"
               name="latitude"
               id="latitude"
               step="0.000001"
               min="-90"
               max="90"
               class="form-control @error('latitude') is-invalid @enderror"
               value="{{ old('latitude', $structure->latitude ?? '') }}"
               required>
        <small class="text-muted">Used to retrieve Meteosat data. Enter a decimal value.</small>
        @error('latitude')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label for="longitude" class="form-label">Longitude</label>
        <input type="number"
               name="longitude"
               id="longitude"
               step="0.000001"
               min="-180"
               max="180"
               class="form-control @error('longitude') is-invalid @enderror"
               value="{{ old('longitude', $structure->longitude ?? '') }}"
               required>
        <small class="text-muted">Decimal value in international format (e.g. 11.1234567).</small>
        @error('longitude')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label for="altitude" class="form-label">Altitude (m a.s.l.)</label>
        <input type="number"
               name="altitude"
               id="altitude"
               class="form-control @error('altitude') is-invalid @enderror"
               value="{{ old('altitude', $structure->altitude ?? '') }}"
               min="-500"
               max="9000"
               step="1">
        <small class="text-muted">Optional, useful to improve Meteosat accuracy.</small>
        @error('altitude')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="mb-3">
    <label for="province" class="form-label">Province (abbreviation)</label>
    <input type="text"
           name="province"
           id="province"
           maxlength="2"
           inputmode="text"
           autocomplete="off"
           class="form-control @error('province') is-invalid @enderror"
           value="{{ strtoupper((string) old('province', $structure->province ?? '')) }}"
           oninput="this.value = this.value.toUpperCase();">
    <small class="text-muted">Enter two letters (e.g. MI).</small>
    @error('province')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="email" class="form-label">Email</label>
    <input type="email"
           name="email"
           id="email"
           class="form-control @error('email') is-invalid @enderror"
           value="{{ old('email', $structure->email ?? '') }}"
           required>
    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="phone" class="form-label">Phone</label>
    <input type="text"
           name="phone"
           id="phone"
           class="form-control @error('phone') is-invalid @enderror"
           value="{{ old('phone', $structure->phone ?? '') }}"
           required>
    @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

@if($user->isSuperAdmin())
    <div class="mb-3">
        <label for="admin_id" class="form-label">Assign Admin</label>
        <select name="admin_id"
                id="admin_id"
                class="form-select @error('admin_id') is-invalid @enderror"
                required>
            <option value="">Select admin</option>
            @foreach($admins as $admin)
                <option value="{{ $admin->id }}"
                        @selected(old('admin_id', optional($structure->admin ?? null)->id) == $admin->id)>
                    {{ $admin->name }} ({{ $admin->email }})
                </option>
            @endforeach
        </select>
        @error('admin_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
@else
    <div class="alert alert-secondary">
        Venue linked to your administrator profile: <strong>{{ $user->name }}</strong>
    </div>
@endif

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Manageable lakes and pegs</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addLakeBtn">+ Add lake</button>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            Define the lakes managed by the venue, indicating available pegs and the prefix (letters/numbers, without spaces) that will be prepended to the pegs
            <small class="text-muted">(e.g. <strong>B</strong> → B1, B2, …)</small>.
        </p>
        @error('lakes')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Lake / area name</th>
                        <th style="width: 200px;">Number of pegs</th>
                        <th style="width: 220px;">Prefix (optional)</th>
                        <th style="width: 80px;"></th>
                    </tr>
                </thead>
                <tbody id="lakesTableBody">
                    @foreach($existingLakes as $index => $lake)
                        <tr class="lake-row">
                            <td>
                                <input type="text"
                                       name="lake_name[]"
                                       class="form-control"
                                       placeholder="e.g. Carpodromo"
                                       value="{{ $lake['name'] }}">
                            </td>
                            <td>
                                <input type="number"
                                       min="1"
                                       name="lake_peg_count[]"
                                       class="form-control"
                                       value="{{ $lake['peg_count'] }}">
                            </td>
                            <td>
                                <input type="text"
                                       name="lake_prefix[]"
                                       class="form-control"
                                       placeholder="e.g. B"
                                       value="{{ $lake['prefix'] }}">
                                <small class="text-muted">Only letters/numbers, without spaces.</small>
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger remove-lake-btn" @if($existingLakes->count() === 1) disabled @endif>✕</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <small class="text-muted">
            Use a different prefix to distinguish pegs between lakes. You can change it at any time even for the first lake.
        </small>
    </div>
</div>

<div class="text-end">
    <a href="{{ route('structures.index') }}" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const container = document.getElementById('lakesTableBody');
        const addBtn = document.getElementById('addLakeBtn');
        const mapInput = document.getElementById('google_maps_url');
        const latInput = document.getElementById('latitude');
        const lonInput = document.getElementById('longitude');
        const altInput = document.getElementById('altitude');
        const extractBtn = document.querySelector('[data-gmaps-extract]');

        function updateRemoveButtons() {
            if (!container) return;
            const rows = Array.from(container.querySelectorAll('tr.lake-row'));
            rows.forEach(row => {
                const removeBtn = row.querySelector('.remove-lake-btn');
                if (removeBtn) {
                    removeBtn.disabled = rows.length === 1;
                }
            });
        }

        function suggestPrefix(index) {
            if (index === 0) {
                return '';
            }
            let value = index;
            let label = '';
            while (value > 0) {
                const remainder = (value - 1) % 26;
                label = String.fromCharCode(65 + remainder) + label;
                value = Math.floor((value - 1) / 26);
            }
            return label;
        }

        function addLakeRow() {
            if (!container) return;
            const rowIndex = container.querySelectorAll('tr.lake-row').length;
            const suggestedPrefix = suggestPrefix(rowIndex);
            const row = document.createElement('tr');
            row.classList.add('lake-row');
            row.innerHTML = `
                <td>
                    <input type="text" name="lake_name[]" class="form-control" placeholder="e.g. Lake B">
                </td>
                <td>
                    <input type="number" min="1" name="lake_peg_count[]" class="form-control" value="10">
                </td>
                <td>
                    <input type="text"
                           name="lake_prefix[]"
                           class="form-control"
                           placeholder="e.g. ${suggestedPrefix || 'B'}"
                           value="${suggestedPrefix}">
                    <small class="text-muted">Only letters/numbers, without spaces.</small>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-lake-btn">✕</button>
                </td>
            `;
            container.appendChild(row);
            updateRemoveButtons();
        }

        container?.addEventListener('click', function (event) {
            if (event.target.classList.contains('remove-lake-btn')) {
                const row = event.target.closest('tr');
                if (row && container.querySelectorAll('tr').length > 1) {
                    row.remove();
                    updateRemoveButtons();
                }
            }
        });

        addBtn?.addEventListener('click', function (event) {
            event.preventDefault();
            addLakeRow();
        });

        function parseGoogleMapsLink(link) {
            if (!link) return null;
            let decoded = link;
            try {
                decoded = decodeURIComponent(link);
            } catch (_) {
                decoded = link;
            }
            const patterns = [
                /@(-?\d+\.\d+),(-?\d+\.\d+)/,
                /!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/,
                /q=(-?\d+\.\d+),(-?\d+\.\d+)/,
            ];
            for (const pattern of patterns) {
                const match = decoded.match(pattern);
                if (match) {
                    return {
                        latitude: parseFloat(match[1]),
                        longitude: parseFloat(match[2]),
                    };
                }
            }
            return null;
        }

        function applyMapCoordinates() {
            if (!mapInput || !latInput || !lonInput) {
                return;
            }
            const value = mapInput.value.trim();
            if (!value) {
                return;
            }
            const result = parseGoogleMapsLink(value);
            if (!result) {
                alert('Unable to extract coordinates from this Google Maps link.');
                return;
            }
            latInput.value = result.latitude.toFixed(6);
            lonInput.value = result.longitude.toFixed(6);
        }

        extractBtn?.addEventListener('click', function (event) {
            event.preventDefault();
            applyMapCoordinates();
        });

        mapInput?.addEventListener('blur', function () {
            applyMapCoordinates();
        });

        updateRemoveButtons();
    });
</script>
@endpush
