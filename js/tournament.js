// public/js/app.js

document.addEventListener('DOMContentLoaded', () => {
    const form          = document.getElementById('tournamentForm');
    const formMode      = form?.dataset.mode || 'team';
    const formContext   = form?.dataset.context || 'create';
    const zonesTableEl  = document.getElementById('zonesTable');
    const zonesTable    = zonesTableEl?.querySelector('tbody');
    const autoSeedZones = zonesTableEl?.dataset.autoseed === '1';
    const zonesErrorBox = document.getElementById('zonesError');
    const addZoneBtn    = document.getElementById('addZoneBtn');
    const addTeamBtn    = document.getElementById('addTeamBtn');
    const teamsContainer= document.getElementById('teamsContainer');
    const eventsTable   = document.getElementById('eventsTable')?.querySelector('tbody');
    const addEventBtn   = document.getElementById('addEventBtn');
    const participantsContainer = document.getElementById('participantsContainer');
    const participantsTable = document.getElementById('participantsTable')?.querySelector('tbody');
    const addParticipantBtn = document.getElementById('addParticipantBtn');
    const participantsLimitAlert = document.getElementById('participantsLimitAlert');
    const participantFieldName = participantsContainer?.dataset.nameField || 'participant_name[]';
    const teamSizeInput = document.getElementById('team_size_input');
    const poolZonesContainer = document.getElementById('poolZonesContainer');
    const addPoolZoneBtn = document.getElementById('addPoolZoneBtn');
    const poolPegsAlert = document.getElementById('poolPegsAlert');
    const structureField = document.getElementById('structure_id');
    const lakeCheckboxSelector = '[data-lake-checkbox]';
    const lakeGroupSelector = '[data-structure-lake-group]';
    const lakeAutoSelector = '[data-lake-auto]';
    const lakeDependentBlocks = document.querySelectorAll('[data-lake-dependent]');
    const lakeWarningBlocks = document.querySelectorAll('[data-lake-required-warning]');
    const poolCapacityAlert = document.querySelector('[data-pool-capacity-alert]');
    const poolCapacityMaxBtn = document.querySelector('[data-pool-capacity-max]');
    const participantDatalist = document.getElementById('participantSuggestions');
    const participantSuggestionValues = (() => {
        if (!participantDatalist) return [];
        return Array.from(participantDatalist.options || [])
            .map(opt => String(opt.value || '').trim())
            .filter(Boolean);
    })();
    const selectedParticipantNames = new Set();
    const participantSuggestUrl = participantDatalist?.dataset?.suggestUrl || '';
    const supportsNativeDatalist = (() => {
        const input = document.createElement('input');
        return 'list' in input && typeof HTMLDataListElement !== 'undefined';
    })();
    const prefersTouchAutocomplete = participantSuggestionValues.length > 0 && (
        (typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches)
        || 'ontouchstart' in window
        || (navigator?.maxTouchPoints || 0) > 0
        || !supportsNativeDatalist
    );
    const shouldForceParticipantDropdown = prefersTouchAutocomplete
        || participantSuggestUrl !== ''
        || !supportsNativeDatalist;
    let teamSizeRequired = (formMode === 'team' && form?.dataset.teamSize)
        ? (parseInt(form.dataset.teamSize, 10) || 0)
        : (teamSizeInput ? parseInt(teamSizeInput.value, 10) || 0 : 0);
    const declaredPoolCapacityDefault = (formMode === 'pool' && form?.dataset.poolCapacity)
        ? (parseInt(form.dataset.poolCapacity, 10) || 0)
        : 0;

    const canChooseEventStructure = form?.dataset.canChooseEventStructure === '1';
    const defaultEventStructure = form?.dataset.defaultEventStructure || '';
    let eventStructureOptions = [];
    if (form?.dataset.eventStructureOptions) {
        try {
            const parsedOptions = JSON.parse(form.dataset.eventStructureOptions);
            if (Array.isArray(parsedOptions)) {
                eventStructureOptions = parsedOptions;
            }
        } catch (error) {
            console.warn('Impossibile analizzare le strutture per le prove:', error);
        }
    }

    function zoneLabelForIndex(index) {
        const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if (index < alphabet.length) {
            return alphabet[index];
        }
        return `Zona ${index + 1}`;
    }

    function ensureZoneRowsToTeamSize() {
        if (!zonesTable || teamSizeRequired <= 0) return;
        const rows = zonesTable.querySelectorAll('tr.zone-row');
        const current = rows.length;
        const target = teamSizeRequired;
        if (current < target) {
            const missing = target - current;
            for (let i = 0; i < missing; i++) {
                const label = zoneLabelForIndex(current + i);
                addZoneRow(label, '', '');
            }
        } else if (current > target) {
            const excess = current - target;
            for (let i = 0; i < excess; i++) {
                const last = zonesTable.querySelector('tr.zone-row:last-child');
                if (last) last.remove();
            }
            validateZones();
            refreshMemberZoneSelects();
            updateParticipantsLimitState();
        }
        updateZoneControls();
        renderZonePegSelectors();
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function normalizeTeamSize(value) {
        const parsed = parseInt(value, 10);
        if (Number.isNaN(parsed)) return 0;
        return parsed >= 1 ? parsed : 0;
    }

    function updateTeamSizeState(nextValue) {
        teamSizeRequired = normalizeTeamSize(nextValue);
        if (form) {
            form.dataset.teamSize = teamSizeRequired ? String(teamSizeRequired) : '';
        }
        if (formMode === 'team' && formContext === 'create') {
            ensureZoneRowsToTeamSize();
            updateZoneControls();
            enforceTeamRows();
            updateTeamMemberControls();
            validateTeamRules();
            updateTeamErrorMessages();
            updateTeamLimitState();
        }
    }

    function attachParticipantAutocomplete(input) {
        if (!input || input.dataset.participantAutocomplete === '1') return;
        input.dataset.participantAutocomplete = '1';
        if (!shouldForceParticipantDropdown && supportsNativeDatalist) return;

        let dropdown = null;
        let lastFilterValue = '';
        let fetchController = null;

        function closeDropdown() {
            if (dropdown) {
                dropdown.remove();
                dropdown = null;
            }
        }

        function renderDropdown(sourceList = [], filterValue = '') {
            const normalized = filterValue.trim().toLowerCase();
            const matches = sourceList
                .filter(name => !selectedParticipantNames.has(name))
                .filter(name => name.toLowerCase().includes(normalized))
                .slice(0, 12);

            if (matches.length === 0) {
                closeDropdown();
                return;
            }

            const rect = input.getBoundingClientRect();
            if (!dropdown) {
                dropdown = document.createElement('div');
                dropdown.className = 'participant-autocomplete-dropdown shadow-sm';
                dropdown.style.position = 'absolute';
                dropdown.style.background = '#fff';
                dropdown.style.border = '1px solid rgba(0,0,0,0.1)';
                dropdown.style.borderRadius = '0.375rem';
                dropdown.style.zIndex = '1051';
                dropdown.style.maxHeight = '220px';
                dropdown.style.overflowY = 'auto';
                dropdown.style.boxSizing = 'border-box';
                document.body.appendChild(dropdown);
            }

            dropdown.style.width = `${rect.width}px`;
            dropdown.style.left = `${rect.left + window.scrollX}px`;
            dropdown.style.top = `${rect.bottom + window.scrollY}px`;

            dropdown.innerHTML = '';
            matches.forEach(name => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'dropdown-item text-start w-100';
                item.textContent = name;
                const applyValue = (evt) => {
                    evt.preventDefault();
                    input.value = name;
                    closeDropdown();
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                };
                item.addEventListener('pointerdown', applyValue);
                item.addEventListener('mousedown', applyValue);
                item.addEventListener('click', applyValue);
                dropdown.appendChild(item);
            });
        }

        function requestRemoteSuggestions(filterValue = '') {
            if (!participantSuggestUrl) return;
            if (filterValue.trim().length === 0) return;
            if (fetchController) {
                fetchController.abort();
            }
            fetchController = new AbortController();
            fetch(`${participantSuggestUrl}?q=${encodeURIComponent(filterValue)}`, {
                headers: { 'Accept': 'application/json' },
                signal: fetchController.signal,
            }).then(resp => {
                if (!resp.ok) return null;
                return resp.json();
            }).then(data => {
                if (!data || !Array.isArray(data.data)) return;
                if (filterValue !== lastFilterValue) return;
                renderDropdown(data.data, filterValue);
            }).catch(() => {});
        }

        function handleInputEvent() {
            const value = input.value || '';
            if (!value || value.length === 0) {
                closeDropdown();
                lastFilterValue = '';
                return;
            }
            lastFilterValue = value;
            if (participantSuggestionValues.length > 0) {
                renderDropdown(participantSuggestionValues, value);
            } else {
                closeDropdown();
            }
            requestRemoteSuggestions(value);
        }

        ['focus', 'input'].forEach(evtName => {
            input.addEventListener(evtName, handleInputEvent);
        });
        input.addEventListener('blur', () => {
            setTimeout(closeDropdown, 120);
        });
        window.addEventListener('scroll', closeDropdown, true);
        window.addEventListener('resize', closeDropdown);
    }

    function attachAutocompleteToAllParticipantInputs() {
        document.querySelectorAll('input[list="participantSuggestions"]').forEach(attachParticipantAutocomplete);
        refreshSelectedParticipants();
    }

    function refreshSelectedParticipants() {
        selectedParticipantNames.clear();
        document.querySelectorAll('input[list="participantSuggestions"]').forEach(input => {
            const val = (input.value || '').trim();
            if (val) {
                selectedParticipantNames.add(val);
            }
        });
    }

    function renderEventStructureField(currentValue = '') {
        const normalizedValue = currentValue || defaultEventStructure || '';

        if (!canChooseEventStructure) {
            return {
                cell: '',
                hidden: `<input type="hidden" name="event_structure_id[]" value="${normalizedValue}">`
            };
        }

        const options = eventStructureOptions.map(option => {
            const value = String(option.id ?? '');
            const label = escapeHtml(option.name ?? '');
            const selected = value === String(normalizedValue) ? 'selected' : '';
            return `<option value="${value}" ${selected}>${label}</option>`;
        }).join('');

        return {
            cell: `
                <select name="event_structure_id[]"
                        class="form-select form-select-sm"
                        required>
                    <option value="">Seleziona struttura</option>
                    ${options}
                </select>
            `,
            hidden: ''
        };
    }

    let structureLakeMap = {};
    try {
        structureLakeMap = form?.dataset.structureLakes
            ? JSON.parse(form.dataset.structureLakes)
            : {};
    } catch (error) {
        console.warn('Impossibile analizzare la mappa dei laghi:', error);
        structureLakeMap = {};
    }
    let activeStructureId = form?.dataset.activeStructure || null;
    if (!activeStructureId && Object.keys(structureLakeMap).length > 0) {
        activeStructureId = Object.keys(structureLakeMap)[0];
    }

    let teamIndex = 0;
    let existingActiveParticipants = 0;
    if ((formMode === 'individual' || formMode === 'pool') && form?.dataset.activeParticipants) {
        const parsed = parseInt(form.dataset.activeParticipants, 10);
        existingActiveParticipants = Number.isNaN(parsed) ? 0 : parsed;
    }

    function getActiveLakeIds() {
        if (!activeStructureId) {
            return [];
        }
        const ids = [];
        document.querySelectorAll(`${lakeCheckboxSelector}[data-structure="${activeStructureId}"]:checked`).forEach(cb => {
            const val = parseInt(cb.value, 10);
            if (!Number.isNaN(val)) {
                ids.push(val);
            }
        });
        document.querySelectorAll(`${lakeAutoSelector}[data-structure="${activeStructureId}"]:not([disabled])`).forEach(input => {
            const val = parseInt(input.value, 10);
            if (!Number.isNaN(val)) {
                ids.push(val);
            }
        });
        return Array.from(new Set(ids));
    }

    function hasActiveLakeSelection() {
        return getActiveLakeIds().length > 0;
    }

    function getActiveStructureData() {
        if (!activeStructureId || !structureLakeMap[activeStructureId]) {
            return { lakes: [], all_pegs: [] };
        }
        const activeLakeIds = getActiveLakeIds();
        if (!activeLakeIds.length) {
            return { lakes: [], all_pegs: [] };
        }
        const base = structureLakeMap[activeStructureId];
        const allowed = new Set(activeLakeIds);
        const filteredLakes = (base.lakes ?? []).filter(lake => allowed.has(lake.id));
        const pegs = [];
        filteredLakes.forEach(lake => {
            if (Array.isArray(lake.pegs)) {
                pegs.push(...lake.pegs);
            }
        });

        return {
            lakes: filteredLakes,
            all_pegs: pegs,
        };
    }

    function renderLakeSummaries() {
        const info = getActiveStructureData();
        const targets = document.querySelectorAll('[data-lake-summary]');
        targets.forEach(target => {
            if (!info.lakes.length) {
                target.innerHTML = '<div class="text-muted">Nessun lago configurato per questa struttura.</div>';
                return;
            }
            target.innerHTML = info.lakes.map(lake => {
                const prefixLabel = lake.prefix ? ` · Prefisso <strong>${lake.prefix}</strong>` : '';
                return `<div><strong>${lake.name}</strong> · ${lake.peg_count} picchetti${prefixLabel}</div>`;
            }).join('');
        });
    }

    function getActivePegContext() {
        const info = getActiveStructureData();
        const lakes = Array.isArray(info.lakes) ? info.lakes : [];
        const groups = lakes.map(lake => ({
            id: lake.id,
            name: lake.name,
            pegs: Array.isArray(lake.pegs) ? lake.pegs : [],
        }));
        return {
            groups,
            flatSet: new Set(info.all_pegs ?? []),
        };
    }

    function getAvailableLakePegCount() {
        if (formMode !== 'pool') return 0;
        const context = getActivePegContext();
        return context.groups.reduce((total, group) => total + group.pegs.length, 0);
    }

    function parsePeg(value) {
        if (value === null || value === undefined) return { prefix: '', num: null, code: '' };
        const code = String(value).trim().toUpperCase();
        if (code === '') return { prefix: '', num: null, code: '' };
        const match = code.match(/^([A-Za-z]*)(\d+)$/);
        if (!match) return { prefix: '', num: null, code };
        const num = parseInt(match[2], 10);
        const prefix = (match[1] || '').toUpperCase();
        return {
            prefix,
            num: Number.isNaN(num) ? null : num,
            code,
        };
    }

    function getAllAvailablePegCodes() {
        const context = getActivePegContext();
        if (!Array.isArray(context.groups)) return [];
        const flat = [];
        context.groups.forEach(group => {
            (group.pegs || []).forEach(code => flat.push(code));
        });
        return Array.from(new Set(flat)).sort((a, b) => {
            const pa = parsePeg(a);
            const pb = parsePeg(b);
            if (pa.prefix !== pb.prefix) {
                return pa.prefix.localeCompare(pb.prefix);
            }
            if (pa.num !== null && pb.num !== null && pa.num !== pb.num) {
                return pa.num - pb.num;
            }
            return pa.code.localeCompare(pb.code);
        });
    }

    function getAvailablePegData() {
        return getAllAvailablePegCodes()
            .map(code => ({ code, ...parsePeg(code) }))
            .filter(item => item.num !== null);
    }

    function updatePoolCapacityAlert() {
        if (formMode !== 'pool' || !poolCapacityAlert) {
            return;
        }
        const available = getAvailableLakePegCount();
        const declared = getDeclaredPoolCapacity();
        const shouldShow = available > 0 && declared > available;
        poolCapacityAlert.classList.toggle('d-none', !shouldShow);
    }

    function refreshPegSelectors(clearSelections = false) {
        const selectors = document.querySelectorAll('[data-peg-selector]');
        selectors.forEach(selector => renderPegSelector(selector, clearSelections));
    }

    function ensureLakeGroupState(structureId) {
        const groups = document.querySelectorAll(lakeGroupSelector);
        if (!groups.length) {
            return;
        }
        const normalized = structureId ? String(structureId) : null;
        groups.forEach(group => {
            const groupId = group.dataset.structureLakeGroup || null;
            const isActive = normalized !== null && groupId === normalized;
            group.hidden = !isActive;
            group.querySelectorAll(`${lakeCheckboxSelector}, ${lakeAutoSelector}`).forEach(input => {
                input.disabled = !isActive;
            });
        });
    }

    function setActiveStructure(structureId) {
        const normalized = structureId ? String(structureId) : null;
        activeStructureId = normalized;
        ensureLakeGroupState(activeStructureId);
        renderLakeSummaries();
        refreshPegSelectors(false);
        updateLakeDependentState();
        updatePoolCapacityAlert();
        renderZonePegSelectors();
        updateTeamLimitState();
        if (formMode === 'pool') {
            validatePoolPegs();
            updateParticipantsLimitState();
        }
    }

    function getSectorSize() {
        const selected = document.querySelector('input[name="sector_size"]:checked');
        if (!selected) return 0;
        const value = parseInt(selected.value, 10);
        return Number.isNaN(value) ? 0 : value;
    }

    function collectAllSelectedPegs(excludeBlock = null) {
        const set = new Set();
        document.querySelectorAll('.pool-zone-block').forEach(block => {
            if (excludeBlock && block === excludeBlock) {
                return;
            }
            const textarea = block.querySelector('[data-peg-output]');
            if (!textarea) return;
            extractPegTokens(textarea.value.toUpperCase()).forEach(token => {
                if (token !== '') {
                    set.add(token);
                }
            });
        });
        return set;
    }

    function applyPegButtonVisualState(button, { active = false, unavailable = false } = {}) {
        if (!button) return;
        button.classList.toggle('active', !!active);
        button.disabled = !!unavailable;

        button.classList.remove(
            'btn-primary',
            'btn-outline-secondary',
            'btn-outline-success',
            'btn-outline-danger',
            'btn-success'
        );

        if (active) {
            button.classList.add('btn-success');
        } else if (unavailable) {
            button.classList.add('btn-outline-danger');
        } else {
            button.classList.add('btn-outline-success');
        }
    }

    function updatePegOutput(selector) {
        if (!selector) return;
        const block = selector.closest('.pool-zone-block');
        const textarea = block?.querySelector('[data-peg-output]');
        if (!textarea) return;
        const activeButtons = selector.querySelectorAll('.peg-toggle-btn.active');
        const values = Array.from(activeButtons)
            .map(btn => btn.dataset.pegCode || '')
            .filter(code => code !== '');
        textarea.value = values.join(' ');
    }

    function renderPegSelector(selector, clearSelection = false) {
        if (!selector) return;

        let { groups, flatSet } = getActivePegContext();
        let allowedList = Array.from(flatSet);
        const block = selector.closest('.pool-zone-block');
        const textarea = block?.querySelector('[data-peg-output]');
        let selected = [];
        const reservedGlobal = clearSelection
            ? new Set()
            : collectAllSelectedPegs(block);

        if (!clearSelection && textarea) {
            selected = extractPegTokens(textarea.value.toUpperCase());
        }
        const capacityLimit = getDeclaredPoolCapacity();
        const totalSelectedGlobal = collectAllSelectedPegs().size;

        if (allowedList.length === 0 && selected.length > 0) {
            allowedList = [...selected];
            flatSet = new Set(allowedList);
        }
        selected = selected.filter(code => flatSet.has(code));
        if (clearSelection) {
            selected = [];
        }

        if (textarea) {
            textarea.value = selected.join(' ');
        }

        selector.innerHTML = '';

        if (allowedList.length === 0) {
            const message = document.createElement('div');
            message.classList.add('text-muted', 'small');
            message.textContent = activeStructureId
                ? 'Nessun picchetto configurato per questa struttura.'
                : 'Seleziona una struttura per vedere i picchetti disponibili.';
            selector.appendChild(message);
            return;
        }

        const fragment = document.createDocumentFragment();
        const selectionSet = new Set(selected);

        const renderButtons = (pegList) => {
            const groupFragment = document.createDocumentFragment();
            pegList.forEach(code => {
                const button = document.createElement('button');
                button.type = 'button';
                button.classList.add('btn', 'btn-sm', 'me-1', 'mb-1', 'peg-toggle-btn');
                button.dataset.pegCode = code;
                button.textContent = code;
                const isActive = selectionSet.has(code);
                const isUnavailable = (reservedGlobal.has(code) && !isActive)
                    || (capacityLimit > 0 && totalSelectedGlobal >= capacityLimit && !isActive);
                applyPegButtonVisualState(button, { active: isActive, unavailable: isUnavailable });
                groupFragment.appendChild(button);
            });
            return groupFragment;
        };

        if (groups.length > 0) {
            groups.forEach(group => {
                const wrapper = document.createElement('div');
                wrapper.classList.add('mb-3');
                const title = document.createElement('div');
                title.classList.add('fw-semibold', 'small', 'text-uppercase', 'text-muted');
                title.textContent = group.name || 'Lago';
                wrapper.appendChild(title);
                const buttonsWrap = document.createElement('div');
                buttonsWrap.appendChild(renderButtons(group.pegs));
                wrapper.appendChild(buttonsWrap);
                fragment.appendChild(wrapper);
            });
        } else {
            fragment.appendChild(renderButtons(allowedList));
        }

        selector.appendChild(fragment);
        updatePegOutput(selector);
    }

    function togglePegButton(button) {
        if (!button) return;
        if (button.disabled) return;
        const selector = button.closest('[data-peg-selector]');
        const nextState = !button.classList.contains('active');
        if (nextState && formMode === 'pool') {
            const capacityLimit = getDeclaredPoolCapacity();
            if (capacityLimit > 0) {
                const currentTotal = collectAllSelectedPegs().size;
                if (currentTotal >= capacityLimit) {
                    poolPegsAlert?.classList.remove('d-none');
                    if (poolPegsAlert) {
                        poolPegsAlert.textContent = `Hai indicato ${capacityLimit} partecipanti: puoi selezionare al massimo ${capacityLimit} picchetti.`;
                    }
                    return;
                }
            }
        }
        applyPegButtonVisualState(button, { active: nextState, unavailable: false });
        updatePegOutput(selector);
        validatePoolPegs();
        updateParticipantsLimitState();
        refreshPegSelectors();
        refreshPegCounters();
    }

    function canProceedAfterLakeSelection() {
        if (!hasActiveLakeSelection()) {
            return false;
        }
        const totalPegs = getAvailableLakePegCount();
        const declared = getDeclaredPoolCapacity();
        if (formMode === 'pool' && totalPegs > 0 && declared > totalPegs) {
            return false;
        }
        return true;
    }

    function updateLakeDependentState() {
        const hasDependents = lakeDependentBlocks.length > 0;
        const canProceed = canProceedAfterLakeSelection();
        if (hasDependents) {
            lakeDependentBlocks.forEach(block => {
                if (!block) return;
                if (canProceed) {
                    block.removeAttribute('hidden');
                } else {
                    block.setAttribute('hidden', 'hidden');
                }
            });
        }
        lakeWarningBlocks.forEach(block => {
            if (!block) return;
            const shouldShow = hasDependents && !canProceed;
            if (shouldShow) {
                block.removeAttribute('hidden');
            } else {
                block.setAttribute('hidden', 'hidden');
            }
        });
    }

    structureField?.addEventListener('change', (event) => {
        const target = event.target;
        const value = (target && typeof target.value === 'string')
            ? target.value
            : structureField.value;
        setActiveStructure(value || null);
    });

    setActiveStructure(activeStructureId || structureField?.value || null);

    teamSizeInput?.addEventListener('input', () => updateTeamSizeState(teamSizeInput.value));
    if (teamSizeInput) {
        updateTeamSizeState(teamSizeInput.value);
    }

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (target instanceof HTMLInputElement && target.matches(lakeCheckboxSelector)) {
            const structureId = target.dataset.structure || null;
            if (structureId && structureId !== activeStructureId) {
                setActiveStructure(structureId);
            }
            const group = target.closest(lakeGroupSelector);
            if (!group) {
                return;
            }
           renderLakeSummaries();
           refreshPegSelectors(true);
           updateLakeDependentState();
           updatePoolCapacityAlert();
           renderZonePegSelectors();
            updateTeamLimitState();
            if (formMode === 'pool') {
                validatePoolPegs();
                updateParticipantsLimitState();
            }
            return;
        }

        if (target instanceof HTMLSelectElement && target.matches('.zone-peg-start, .zone-peg-end')) {
            currentZonePegTarget = target;
        }
    });

    /* ---------- ZONE DINAMICHE ---------- */
    function addZoneRow(defaultName = '', defaultStart = '', defaultEnd = '') {
        if (!zonesTable) return;
        const currentCount = zonesTable.querySelectorAll('tr.zone-row').length;
        if (teamSizeRequired > 0 && currentCount >= teamSizeRequired) {
            updateZoneControls();
            return;
        }
        const tr = document.createElement('tr');
        tr.classList.add('zone-row');
        tr.innerHTML = `
            <td>
                <div class="form-check text-center">
                    <input class="form-check-input zone-technical-radio" type="radio" name="zone_is_technical" value="0">
                </div>
            </td>
            <td>
                <input type="text" name="zone_name[]" class="form-control form-control-sm"
                       value="${defaultName}">
            </td>
            <td>
                <input type="text" name="zone_start[]" class="form-control form-control-sm input-peg zone-peg-start"
                       value="${defaultStart}">
            </td>
            <td>
                <input type="text" name="zone_end[]" class="form-control form-control-sm input-peg zone-peg-end"
                       value="${defaultEnd}">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger remove-zone-btn">✕</button>
            </td>
        `;
        zonesTable.appendChild(tr);
        refreshMemberZoneSelects(); // aggiorna i menu zona dei partecipanti
        updateParticipantsLimitState();
        updateZoneControls();
        updateTeamLimitState();
        renderZonePegSelectors();
        reindexTechnicalRadios();
    }

    function getZonesFromForm() {
        const rows = zonesTable?.querySelectorAll('tr.zone-row') || [];
        const zones = [];
        rows.forEach((row, idx) => {
            const nameInput  = row.querySelector('input[name="zone_name[]"]');
            const startInput = row.querySelector('input[name="zone_start[]"]');
            const endInput   = row.querySelector('input[name="zone_end[]"]');

            const name = nameInput.value.trim();
            const startVal = startInput.value.trim();
            const endVal   = endInput.value.trim();

            if (!name && !startVal && !endVal) {
                return; // riga vuota
            }

            const startPeg = parsePeg(startVal);
            const endPeg = parsePeg(endVal);

            zones.push({
                index: idx,
                row,
                name,
                startCode: startPeg.code,
                endCode: endPeg.code,
                startNum: startPeg.num,
                endNum: endPeg.num,
                startPrefix: startPeg.prefix,
                endPrefix: endPeg.prefix,
                startRaw: startVal,
                endRaw: endVal
            });
        });
        return zones;
    }

    function getUsedPegCodes(excludeRow = null) {
        const zones = getZonesFromForm();
        const set = new Set();
        const available = getAllAvailablePegCodes().map(code => code.toUpperCase());
        const lookup = new Map(available.map((code, idx) => [code, idx]));
        zones.forEach(zone => {
            if (excludeRow && zone.row === excludeRow) return;
            const startIdx = lookup.get(zone.startCode);
            const endIdx = lookup.get(zone.endCode);
            if (startIdx !== undefined && endIdx !== undefined && startIdx <= endIdx) {
                for (let i = startIdx; i <= endIdx; i++) {
                    set.add(available[i]);
                }
                return;
            }

            if (zone.startNum !== null && zone.endNum !== null && zone.startNum <= zone.endNum) {
                for (let num = zone.startNum; num <= zone.endNum; num++) {
                    const normalized = zone.startPrefix ? `${zone.startPrefix}${num}` : String(num);
                    if (!lookup.has(normalized)) {
                        set.add(normalized);
                    }
                }
            }
        });
        return set;
    }

    let currentZonePegTarget = null;
    function reindexTechnicalRadios() {
        if (!zonesTable) return;
        const rows = zonesTable.querySelectorAll('tr.zone-row');
        const checked = zonesTable.querySelector('input.zone-technical-radio:checked');
        let checkedRow = checked ? checked.closest('tr.zone-row') : null;

        rows.forEach((row, idx) => {
            const radio = row.querySelector('input.zone-technical-radio');
            if (radio) {
                radio.value = String(idx);
            }
        });

        if (!checkedRow && rows.length > 0) {
            const firstRadio = rows[0].querySelector('input.zone-technical-radio');
            if (firstRadio) {
                firstRadio.checked = true;
            }
        }
    }

    function renderZonePegSelectors() {
        if (!zonesTable) return;
        const allPegs = getAllAvailablePegCodes();
        if (!allPegs.length) return;
        const usedGlobal = getUsedPegCodes();
        renderZonePegChooser(allPegs, usedGlobal);
    }

    function renderZonePegChooser(allPegs, usedGlobal) {
        const chooser = document.querySelector('[data-peg-chooser]');
        if (!chooser) return;

        const fragment = document.createDocumentFragment();
        const info = getActiveStructureData();
        const groups = info.lakes || [];

        const makeButton = (code) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.classList.add('btn', 'btn-sm', 'me-1', 'mb-1', 'peg-choose-btn');
            button.dataset.pegCode = code;
            const isUsed = usedGlobal.has(code);
            button.disabled = isUsed;
            button.textContent = code;
            return button;
        };

        if (groups.length) {
            groups.forEach(group => {
                const wrapper = document.createElement('div');
                wrapper.classList.add('mb-2');
                const title = document.createElement('div');
                title.classList.add('fw-semibold', 'small', 'text-muted');
                title.textContent = group.name || 'Lago';
                wrapper.appendChild(title);

                const buttonsWrap = document.createElement('div');
                (group.pegs || []).forEach(code => {
                    buttonsWrap.appendChild(makeButton(code));
                });
                wrapper.appendChild(buttonsWrap);
                fragment.appendChild(wrapper);
            });
        } else {
            const buttonsWrap = document.createElement('div');
            allPegs.forEach(code => buttonsWrap.appendChild(makeButton(code)));
            fragment.appendChild(buttonsWrap);
        }

        chooser.innerHTML = '';
        chooser.appendChild(fragment);
    }

    function autoFillZoneEnd(row, { force = true } = {}) {
        const startInput = row?.querySelector('.zone-peg-start');
        const endInput = row?.querySelector('.zone-peg-end');
        if (!startInput || !endInput) return;
        const startCode = startInput.value.trim();
        const startPeg = parsePeg(startCode);
        if (startPeg.num === null) return;
        const sector = getSectorSize();
        if (sector <= 0) return;

        const allPegs = getAvailablePegData()
            .filter(item => item.prefix === startPeg.prefix);
        if (!allPegs.length) return;

        const usedExcludingRow = getUsedPegCodes(row);
        const available = allPegs.filter(item => !usedExcludingRow.has(item.code));

        const startIdx = available.findIndex(item =>
            item.code === startPeg.code || item.num === startPeg.num
        );
        if (startIdx === -1) return;

        // Cerca un blocco di lunghezza "sector" con numeri consecutivi
        let desiredEnd = null;
        for (let i = startIdx; i < available.length; i++) {
            const first = available[i];
            if (first.num !== startPeg.num) continue;
            const block = [];
            for (let offset = 0; offset < sector; offset++) {
                const expected = startPeg.num + offset;
                const found = available.find(item => item.num === expected && item.prefix === startPeg.prefix);
                if (!found) {
                    break;
                }
                block.push(found);
            }
            if (block.length === sector) {
                desiredEnd = block[block.length - 1];
                break;
            }
        }

        if (!desiredEnd) return;

        const currentEnd = parsePeg(endInput.value).num;
        if (!force && currentEnd !== null && currentEnd >= startPeg.num) {
            return;
        }
        endInput.value = desiredEnd.code;
        endInput.dispatchEvent(new Event('input', { bubbles: true }));
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('.peg-choose-btn');
        if (!button) return;
        const code = button.dataset.pegCode;
        if (!code) return;
        const target = currentZonePegTarget ||
            zonesTable?.querySelector('.zone-peg-start') ||
            zonesTable?.querySelector('.zone-peg-end');
        if (!target) return;
        target.value = code;
        target.dispatchEvent(new Event('input', { bubbles: true }));

        // se compilato il "Da", prova a impostare automaticamente "A"
        if (target.classList.contains('zone-peg-start')) {
            const row = target.closest('tr.zone-row');
            const endInput = row?.querySelector('.zone-peg-end');
            if (endInput) {
                autoFillZoneEnd(row, { force: true });
            }
        }
        renderZonePegSelectors();
    });

    zonesTable?.addEventListener('focusin', (event) => {
        const target = event.target;
        if (target instanceof HTMLInputElement && target.classList.contains('zone-peg-start')) {
            currentZonePegTarget = target;
        }
        if (target instanceof HTMLInputElement && target.classList.contains('zone-peg-end')) {
            currentZonePegTarget = target;
        }
    });

    zonesTable?.addEventListener('input', (event) => {
        const target = event.target;
        if (target instanceof HTMLInputElement && target.classList.contains('zone-peg-start')) {
            const row = target.closest('tr.zone-row');
            autoFillZoneEnd(row, { force: true });
        }
    });

    function calculateTotalPegs() {
        const zones = getZonesFromForm();
        if (zones.length > 0) {
            const available = getAllAvailablePegCodes().map(code => code.toUpperCase());
            const lookup = new Map(available.map((code, idx) => [code, idx]));
            return zones.reduce((total, zone) => {
                const startIdx = lookup.get(zone.startCode);
                const endIdx = lookup.get(zone.endCode);
                if (startIdx !== undefined && endIdx !== undefined && startIdx <= endIdx) {
                    return total + (endIdx - startIdx + 1);
                }
                if (zone.startNum !== null && zone.endNum !== null && zone.startNum <= zone.endNum) {
                    return total + Math.max(0, zone.endNum - zone.startNum + 1);
                }
                return total;
            }, 0);
        }
        if (form?.dataset.totalPegs) {
            const value = parseInt(form.dataset.totalPegs, 10);
            return Number.isNaN(value) ? 0 : value;
        }
        return 0;
    }

    function extractPegTokens(text) {
        return text
            .split(/[\s,;]+/)
            .map(token => token.trim())
            .filter(token => token !== '');
    }

    function getPoolPegCount() {
        if (formMode !== 'pool') return 0;
        const outputs = document.querySelectorAll('[data-peg-output]');

        if (outputs.length === 0) {
            if (form?.dataset.poolPegCount) {
                const parsed = parseInt(form.dataset.poolPegCount, 10);
                return Number.isNaN(parsed) ? 0 : parsed;
            }
            return 0;
        }

        let total = 0;
        outputs.forEach(area => {
            total += extractPegTokens(area.value).length;
        });
        return total;
    }

    function getPoolPegCountByBlock(block) {
        const textarea = block?.querySelector('[data-peg-output]');
        if (!textarea) return 0;
        return extractPegTokens(textarea.value).length;
    }

    function getRemainingPoolSlots() {
        const capacity = getDeclaredPoolCapacity();
        if (capacity <= 0) return null;
        const current = getPoolPegCount();
        return Math.max(0, capacity - current);
    }

    function updatePegCounter(block) {
        if (!block) return;
        const current = getPoolPegCountByBlock(block);
        const remaining = getRemainingPoolSlots();
        const counter = block.querySelector('[data-peg-counter]');
        if (!counter) return;
        if (remaining === null) {
            counter.textContent = `${current} selezionati`;
            counter.classList.remove('text-danger');
        } else {
            const remainingAfter = Math.max(0, remaining - current);
            counter.textContent = `${current} selezionati · ${remainingAfter} rimanenti`;
            counter.classList.toggle('text-danger', remainingAfter === 0 && getPoolPegCount() > getDeclaredPoolCapacity());
        }
    }

    function refreshPegCounters() {
        document.querySelectorAll('.pool-zone-block').forEach(block => updatePegCounter(block));
    }

    function getDeclaredPoolCapacity() {
        if (formMode !== 'pool') return 0;
        const field = document.querySelector('input[name="pool_capacity"]');
        if (!field) return declaredPoolCapacityDefault;
        const value = parseInt(field.value, 10);
        return Number.isNaN(value) ? declaredPoolCapacityDefault : value;
    }

    function getPoolEffectiveCapacity() {
        if (formMode !== 'pool') return 0;
        const declared = getDeclaredPoolCapacity();
        const pegCount = getPoolPegCount();
        if (declared > 0 && pegCount > 0) {
            return Math.min(declared, pegCount);
        }
        return declared > 0 ? declared : pegCount;
    }

    function validatePoolPegs() {
        if (formMode !== 'pool') return true;
        const count = getPoolPegCount();
        const declared = getDeclaredPoolCapacity();
        const ok = count > 0 && (declared <= 0 || count <= declared);
        if (!ok) {
            poolPegsAlert?.classList.remove('d-none');
            if (poolPegsAlert) {
                if (declared > 0 && count > declared) {
                    poolPegsAlert.textContent = `Hai indicato ${declared} partecipanti: puoi selezionare al massimo ${declared} picchetti (ora sono ${count}).`;
                } else {
                    poolPegsAlert.textContent = 'Inserisci almeno una zona con picchetti validi.';
                }
            }
        } else {
            poolPegsAlert?.classList.add('d-none');
        }
        return ok;
    }

    function validateZones() {
        if (!zonesTable || !zonesErrorBox) return true;

        zonesErrorBox.classList.add('d-none');
        zonesErrorBox.textContent = '';

        zonesTable.querySelectorAll('tr.zone-row').forEach(r => {
            r.classList.remove('zone-invalid');
        });

        const zones = getZonesFromForm();
        if (zones.length === 0) {
            zonesErrorBox.textContent = 'Devi inserire almeno una zona.';
            zonesErrorBox.classList.remove('d-none');
            return false;
        }

        const available = getAllAvailablePegCodes().map(code => code.toUpperCase());
        const lookup = new Map(available.map((code, idx) => [code, idx]));
        const enriched = [];

        // controlli base
        for (const z of zones) {
            if (!z.name || !z.startCode || !z.endCode) {
                z.row.classList.add('zone-invalid');
                zonesErrorBox.textContent = 'Ogni zona deve avere nome e picchetti validi.';
                zonesErrorBox.classList.remove('d-none');
                return false;
            }
            if (z.startNum === null || z.endNum === null) {
                z.row.classList.add('zone-invalid');
                zonesErrorBox.textContent = 'Usa picchetti alfanumerici con numeri finali (es. A1, 10).';
                zonesErrorBox.classList.remove('d-none');
                return false;
            }

            const startIdx = lookup.get(z.startCode);
            const endIdx = lookup.get(z.endCode);
            if (available.length > 0 && (startIdx === undefined || endIdx === undefined)) {
                z.row.classList.add('zone-invalid');
                zonesErrorBox.textContent = 'I picchetti inseriti devono appartenere ai laghi selezionati.';
                zonesErrorBox.classList.remove('d-none');
                return false;
            }

            const positionStart = startIdx ?? z.startNum;
            const positionEnd = endIdx ?? z.endNum;

            if (positionStart > positionEnd) {
                z.row.classList.add('zone-invalid');
                zonesErrorBox.textContent =
                    `La zona "${z.name}" ha un inizio maggiore della fine.`;
                zonesErrorBox.classList.remove('d-none');
                return false;
            }

            enriched.push({ ...z, startIdx: positionStart, endIdx: positionEnd });
        }

        // controllo sovrapposizioni
        const sorted = [...enriched].sort((a, b) => a.startIdx - b.startIdx);
        for (let i = 0; i < sorted.length - 1; i++) {
            const curr = sorted[i];
            const next = sorted[i + 1];

            if (curr.endIdx >= next.startIdx) {
                curr.row.classList.add('zone-invalid');
                next.row.classList.add('zone-invalid');
                zonesErrorBox.textContent =
                    `Le zone "${curr.name}" (${curr.startCode}-${curr.endCode}) ` +
                    `e "${next.name}" (${next.startCode}-${next.endCode}) si sovrappongono.`;
                zonesErrorBox.classList.remove('d-none');
                return false;
            }
        }

        return true;
    }

    function getZoneNamesList() {
        const zones = getZonesFromForm();
        const names = [];
        zones.forEach(z => {
            if (z.name && !names.includes(z.name)) {
                names.push(z.name);
            }
        });
        return names;
    }

    function refreshMemberZoneSelects() {
        if (!teamsContainer) return;
        const zoneNames = getZoneNamesList();

        const selects = teamsContainer.querySelectorAll('select.zone-select');
        selects.forEach(sel => {
            const current = sel.value;
            // svuota
            while (sel.firstChild) sel.removeChild(sel.firstChild);

            // placeholder
            const optPlaceholder = document.createElement('option');
            optPlaceholder.value = '';
            optPlaceholder.textContent = 'Seleziona zona';
            sel.appendChild(optPlaceholder);

            // opzioni zone
            zoneNames.forEach(z => {
                const opt = document.createElement('option');
                opt.value = z;
                opt.textContent = z;
                sel.appendChild(opt);
            });


            // apply autozone if present
            if (sel.dataset.autozone) {
                sel.value = sel.dataset.autozone;
                delete sel.dataset.autozone;
            } else 
            // ripristina selezione se ancora valida
            if (current && zoneNames.includes(current)) {
                sel.value = current;
            } else {
                sel.value = '';
            }
        });
    }

    if (addZoneBtn && zonesTable) {
        addZoneBtn.addEventListener('click', () => addZoneRow());

        // Se il server non ha già popolato righe, aggiungo zone di esempio
        if (autoSeedZones && zonesTable.querySelectorAll('tr.zone-row').length === 0) {
            addZoneRow('A', '1', '4');
            addZoneRow('B', '11', '14');
            addZoneRow('C', '21', '24');
        }

        zonesTable.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-zone-btn')) {
                const row = e.target.closest('tr');
                row?.remove();
                validateZones();
                refreshMemberZoneSelects();
                updateParticipantsLimitState();
                updateZoneControls();
                updateTeamLimitState();
                renderZonePegSelectors();
                reindexTechnicalRadios();
            }
        });

        zonesTable.addEventListener('input', () => {
            validateZones();
            refreshMemberZoneSelects();
            updateParticipantsLimitState();
            updateZoneControls();
            updateTeamLimitState();
            renderZonePegSelectors();
            reindexTechnicalRadios();
        });

        // inizializza menu zona partecipanti
        refreshMemberZoneSelects();
        updateParticipantsLimitState();
        updateZoneControls();
        updateTeamLimitState();
        renderZonePegSelectors();
        reindexTechnicalRadios();
    }

    /* ---------- SQUADRE + MEMBRI (solo nel form di creazione) ---------- */

    function updateTeamMemberControls() {
        if (formMode !== 'team' || formContext !== 'create') return;
        const teamCards = document.querySelectorAll('#teamsContainer .card');
        teamCards.forEach(card => {
            const addBtn = card.querySelector('.add-member-btn');
            if (!addBtn) return;
            const rows = card.querySelectorAll('table tbody tr').length;
            if (teamSizeRequired > 0) {
                addBtn.disabled = rows >= teamSizeRequired;
            } else {
                addBtn.disabled = false;
            }
        });
    }

    function getTeamCardsCount() {
        return teamsContainer ? teamsContainer.querySelectorAll('.card').length : 0;
    }

    function getMaxTeamsAllowed() {
        if (formMode !== 'team') return Infinity;
        const totalPegs = calculateTotalPegs();
        if (teamSizeRequired > 0 && totalPegs > 0) {
            return Math.floor(totalPegs / teamSizeRequired);
        }
        return Infinity;
    }

    function updateTeamLimitState() {
        if (formMode !== 'team' || !addTeamBtn) return;
        const maxTeams = getMaxTeamsAllowed();
        const count = getTeamCardsCount();
        if (Number.isFinite(maxTeams)) {
            addTeamBtn.disabled = count >= maxTeams;
        } else {
            addTeamBtn.disabled = false;
        }
    }

    function enforceTeamRows() {
        if (formMode !== 'team' || formContext !== 'create') return;
        if (teamSizeRequired <= 0) return;
        const teamCards = document.querySelectorAll('#teamsContainer .card');
        teamCards.forEach(card => {
            const addBtn = card.querySelector('.add-member-btn');
            const tbody = card.querySelector('tbody');
            if (!addBtn || !tbody) return;
            const rows = tbody.querySelectorAll('tr').length;
            const delta = teamSizeRequired - rows;
            const teamIdx = addBtn.dataset.teamIndex;
            if (delta > 0 && teamIdx) {
                for (let i = 0; i < delta; i++) {
                    addMemberRow(teamIdx);
                }
            } else if (delta < 0) {
                const toRemove = Math.min(rows, Math.abs(delta));
                for (let i = 0; i < toRemove; i++) {
                    const lastRow = tbody.querySelector('tr:last-child');
                    if (lastRow) lastRow.remove();
                }
            }
        });
        updateTeamMemberControls();
        validateTeamRules();
        updateTeamErrorMessages();
    }

    function updateZoneControls() {
        if (!zonesTable || !addZoneBtn) return;
        const zonesCount = zonesTable.querySelectorAll('tr.zone-row').length;
        if (teamSizeRequired > 0) {
            addZoneBtn.disabled = zonesCount >= teamSizeRequired;
        } else {
            addZoneBtn.disabled = false;
        }
    }

    function validateTeamRules() {
        if (formMode !== 'team' || formContext !== 'create') return true;
        const teamCards = document.querySelectorAll('#teamsContainer .card');

        let ok = true;

        teamCards.forEach(card => {
            const rows = card.querySelectorAll('table tbody tr');
            const usedZones = new Set();
            let membersCount = 0;

            // pulizia stati precedenti
            rows.forEach(r => r.classList.remove('zone-invalid'));
            const zoneAlert = card.querySelector('.team-zone-error');
            const countAlert = card.querySelector('.team-count-error');
            zoneAlert?.classList.add('d-none');
            countAlert?.classList.add('d-none');
            const teamNameInput = card.querySelector('input[name="team_name[]"]');
            const teamLabel = teamNameInput?.value?.trim() || 'Senza nome';

            rows.forEach(r => {
                // ora usiamo <select> per la zona
                const zoneSelect = r.querySelector('select[name^="member_zone"]');
                const nameInput = r.querySelector('input[name^="member_name"]');
                const participantName = nameInput?.value?.trim() || '';
                const zone = zoneSelect?.value?.trim().toUpperCase() || '';

                if (participantName !== '') {
                    membersCount++;
                }

                if (!zoneSelect) return;

                if (!zone) return;

                if (usedZones.has(zone)) {
                    // zona duplicata → non valido
                    r.classList.add('zone-invalid');
                    if (zoneAlert) {
                        zoneAlert.textContent = `La squadra "${teamLabel}" ha la zona '${zone}' duplicata.`;
                        zoneAlert.classList.remove('d-none');
                    }
                    ok = false;
                } else {
                    usedZones.add(zone);
                }
            });

            if (teamSizeRequired > 0 && membersCount !== teamSizeRequired) {
                if (countAlert) {
                    countAlert.textContent = `La squadra "${teamLabel}" deve avere ${teamSizeRequired} partecipanti (attuali: ${membersCount}).`;
                    countAlert.classList.remove('d-none');
                }
                ok = false;
            }
        });

        return ok;
    }

    function updateTeamErrorMessages() {
        const teamCards = document.querySelectorAll('#teamsContainer .card');
        teamCards.forEach(card => {
            const hasZoneError = !!card.querySelector('tr.zone-invalid');
            const hasCountError = !!card.querySelector('.team-count-error:not(.d-none)');
            const alertBox = card.querySelector('.team-zone-error');

            if (alertBox) {
                if (hasZoneError) {
                    alertBox.classList.remove('d-none');
                } else if (!hasCountError) {
                    alertBox.classList.add('d-none');
                }
            }
        });
    }

    function addTeamCard(options = {}) {
        const { autoFillMembers = true } = options;
        if (!teamsContainer) return;
        const maxTeams = getMaxTeamsAllowed();
        if (Number.isFinite(maxTeams) && getTeamCardsCount() >= maxTeams) {
            updateTeamLimitState();
            return;
        }

        const idx = teamIndex++;
        const div = document.createElement('div');
        div.classList.add('card', 'mb-3');
        div.innerHTML = `
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="me-2">Squadra</span>
                    <input type="text" name="team_name[]" class="form-control form-control-sm d-inline-block"
                           style="width: 240px;" required>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger remove-team-btn">
                    Elimina squadra
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted mb-2">
                    Aggiungi i partecipanti di questa squadra. Ogni zona può essere scelta solo una volta.
                    Le zone disponibili provengono dalla tabella delle zone sopra.
                </p>
                <div class="table-responsive mb-2">
                    <table class="table table-sm align-middle" id="team_${idx}_members">
                        <thead>
                            <tr>
                                <th>Nome partecipante</th>
                                <th>Zona</th>
                                <th style="width: 80px;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="alert alert-danger d-none team-zone-error">
                    Errore: una squadra non può avere più partecipanti nella stessa zona.
                </div>
                <div class="alert alert-danger d-none team-count-error">
                    Numero partecipanti non congruo.
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary add-member-btn"
                        data-team-index="${idx}">
                    + Aggiungi partecipante
                </button>
            </div>
        `;
        teamsContainer.appendChild(div);

        const requiredMembers = teamSizeRequired > 0 ? teamSizeRequired : 1;
        for (let i = 0; i < requiredMembers; i++) {
            addMemberRow(idx);
        }
        if (autoFillMembers) {
            autoPopulateMembers(idx);
        }
        updateTeamMemberControls();
        updateTeamLimitState();
    }

    function addMemberRow(teamIdx) {
        const tableBody = document.querySelector(`#team_${teamIdx}_members tbody`);
        if (!tableBody) return;
        if (teamSizeRequired > 0) {
            const rowsCount = tableBody.querySelectorAll('tr').length;
            if (rowsCount >= teamSizeRequired) {
                updateTeamMemberControls();
                return;
            }
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <input type="text" name="member_name[${teamIdx}][]" class="form-control form-control-sm"
                       list="participantSuggestions" autocomplete="off" required>
            </td>
            <td style="width: 140px;">
                <select name="member_zone[${teamIdx}][]" class="form-select form-select-sm zone-select" required>
                    <option value="">Seleziona zona</option>
                </select>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger remove-member-btn">✕</button>
            </td>
        `;
        tableBody.appendChild(tr);
        attachParticipantAutocomplete(tr.querySelector('input[list="participantSuggestions"]'));
        refreshSelectedParticipants();

        const zoneSelect = tr.querySelector('select.zone-select');
        if (zoneSelect) {
            zoneSelect.addEventListener('change', () => {
                validateTeamRules();
                updateTeamErrorMessages();
            });
        }

        refreshMemberZoneSelects();
    }

    function autoPopulateMembers(teamIdx) {
        const zoneNames = getZoneNamesList();
        if (zoneNames.length === 0) return;

        const tableBody = document.querySelector(`#team_${teamIdx}_members tbody`);
        if (!tableBody) return;

        const rows = Array.from(tableBody.querySelectorAll('tr'));
        const missing = zoneNames.length - rows.length;
        for (let i = 0; i < missing; i++) {
            addMemberRow(teamIdx);
        }

        const allRows = tableBody.querySelectorAll('tr');
        allRows.forEach((row, idx) => {
            const select = row.querySelector('select.zone-select');
            if (select && zoneNames[idx]) {
                select.value = zoneNames[idx];
            }
        });
        updateTeamErrorMessages();
    }

    if (addTeamBtn && teamsContainer) {
        addTeamBtn.addEventListener('click', () => {
            addTeamCard();
            updateTeamMemberControls();
            updateTeamLimitState();
        });
    }

    // Ricostruzione squadre da PHP (window.__restore_team_X) nel form di creazione
    const restoreKeys = Object.keys(window).filter(k => k.startsWith('__restore_team_'));
    if (teamsContainer) {
        if (restoreKeys.length > 0) {
            restoreKeys.sort().forEach(k => {
                const data = window[k];
                addTeamCard({ autoFillMembers: false });
                const teamCards = document.querySelectorAll('#teamsContainer .card');
                const card = teamCards[teamCards.length - 1];

                // imposta nome
                const nameInput = card.querySelector('input[name="team_name[]"]');
                if (nameInput) {
                    nameInput.value = data.team_name ?? '';
                }

                // svuota membri default
                const tbody = card.querySelector('tbody');
                if (tbody) tbody.innerHTML = '';

                const teamIdx = teamCards.length - 1;

                if (Array.isArray(data.members)) {
                    data.members.forEach((m, idx) => {
                        addMemberRow(teamIdx);
                        const rows = card.querySelectorAll('tbody tr');
                        const row = rows[rows.length - 1];

                        const nameField = row.querySelector('input[name^="member_name"]');
                        const zoneField = row.querySelector('select[name^="member_zone"]');
                        if (nameField) nameField.value = m;
                        if (zoneField && data.zones && data.zones[idx]) {
                            zoneField.value = data.zones[idx];
                        }
                    });
                }
            });

            validateTeamRules();
            updateTeamErrorMessages();
            updateTeamMemberControls();
            updateTeamLimitState();
        } else {
            // se nessun dato dal server, aggiungo una squadra di default
            addTeamCard();
        }

        teamsContainer.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-team-btn')) {
                e.target.closest('.card')?.remove();
                validateTeamRules();
                updateTeamErrorMessages();
                updateTeamMemberControls();
                updateTeamLimitState();
            }
            if (e.target.classList.contains('add-member-btn')) {
                const idx = e.target.getAttribute('data-team-index');
                addMemberRow(idx);
                validateTeamRules();
                updateTeamErrorMessages();
                updateTeamMemberControls();
            }
            if (e.target.classList.contains('remove-member-btn')) {
                e.target.closest('tr')?.remove();
                validateTeamRules();
                updateTeamErrorMessages();
                updateTeamMemberControls();
                updateTeamLimitState();
            }
        });
    }

    initTeamEditBlocks();

    function initTeamEditBlocks() {
        if (formMode !== 'team' || formContext !== 'edit') return;
        const blocks = document.querySelectorAll('.team-edit-block');
        if (!blocks.length) return;

        blocks.forEach(block => {
            const teamId = block.dataset.teamId;
            const template = document.getElementById(`team_new_row_template_${teamId}`);
            const addBtn = block.querySelector(`.add-team-member-btn[data-team-id="${teamId}"]`);
            const tableBody = block.querySelector('tbody');
            const limitAlert = block.querySelector('.team-limit-alert');
            const countDisplay = document.querySelector(`.team-count-display[data-team-id="${teamId}"]`);
            const existingCount = parseInt(block.dataset.existingCount || '0', 10) || 0;

            const getFilledNewRows = () => {
                if (!tableBody) return 0;
                let count = 0;
                tableBody.querySelectorAll('.team-new-row').forEach(row => {
                    const nameInput = row.querySelector('input[name^="team_new_member"]');
                    const zoneSelect = row.querySelector('select[name^="team_new_zone"]');
                    const name = nameInput?.value?.trim() || '';
                    const zone = zoneSelect?.value?.trim() || '';
                    if (name !== '' || zone !== '') {
                        count += 1;
                    }
                });
                return count;
            };

            const updateCountDisplay = () => {
                const total = existingCount + getFilledNewRows();
                if (countDisplay) {
                    countDisplay.textContent = `${total}`;
                }
                if (teamSizeRequired > 0) {
                    const limitReached = total >= teamSizeRequired;
                    if (addBtn) addBtn.disabled = limitReached;
                    if (limitAlert) {
                        if (limitReached) {
                            limitAlert.classList.remove('d-none');
                        } else {
                            limitAlert.classList.add('d-none');
                        }
                    }
                }
            };

            const addRow = (defaultName = '', defaultZone = '') => {
                if (!template || !tableBody) return;
                if (teamSizeRequired > 0) {
                    const planned = existingCount + getFilledNewRows();
                    if (planned >= teamSizeRequired) {
                        if (limitAlert) limitAlert.classList.remove('d-none');
                        return;
                    }
                }
                const clone = template.content.cloneNode(true);
                const row = clone.querySelector('tr');
                if (!row) return;
                const nameInput = row.querySelector('input[name^="team_new_member"]');
                const zoneSelect = row.querySelector('select[name^="team_new_zone"]');
                if (nameInput) nameInput.value = defaultName;
                if (zoneSelect) zoneSelect.value = defaultZone;
                tableBody.appendChild(row);
                updateCountDisplay();
            };

            addBtn?.addEventListener('click', () => addRow());

            block.addEventListener('click', (e) => {
                if (e.target.classList.contains('remove-team-new-row')) {
                    e.target.closest('tr')?.remove();
                    updateCountDisplay();
                }
            });

            const handleDynamicChange = (event) => {
                if (
                    event.target.matches('input[name^="team_new_member"]') ||
                    event.target.matches('select[name^="team_new_zone"]')
                ) {
                    updateCountDisplay();
                }
            };

            block.addEventListener('input', handleDynamicChange);
            block.addEventListener('change', handleDynamicChange);

            if (
                tableBody &&
                tableBody.querySelectorAll('.team-new-row').length === 0 &&
                teamSizeRequired > 0 &&
                existingCount < teamSizeRequired
            ) {
                addRow();
            } else {
                updateCountDisplay();
            }
        });
    }

    function validateTeamEditBlocks() {
        if (formMode !== 'team' || formContext !== 'edit') return true;
        const blocks = document.querySelectorAll('.team-edit-block');
        if (!blocks.length) return true;

        let ok = true;

        blocks.forEach(block => {
            const teamId = block.dataset.teamId;
            const teamName = block.dataset.teamName || 'Senza nome';
            const existingCount = parseInt(block.dataset.existingCount || '0', 10) || 0;
            const countErrorBox = block.querySelector('.team-count-error');
            const zoneErrorBox = block.querySelector('.team-zone-error');
            countErrorBox?.classList.add('d-none');
            zoneErrorBox?.classList.add('d-none');

            const usedZones = [];
            let zoneErrorMessage = '';

            const existingZoneSelects = document.querySelectorAll(`.team-existing-table[data-team-id="${teamId}"] .team-zone-select`);
            existingZoneSelects.forEach(sel => {
                const value = sel.value.trim();
                if (!value) return;
                const normalized = value.toUpperCase();
                if (usedZones.includes(normalized)) {
                    zoneErrorMessage = `La squadra "${teamName}" ha la zona '${value}' duplicata.`;
                    ok = false;
                } else {
                    usedZones.push(normalized);
                }
            });

            let filledNewRows = 0;
            const newRows = block.querySelectorAll('.team-new-row');
            newRows.forEach(row => {
                const nameInput = row.querySelector('input[name^="team_new_member"]');
                const zoneSelect = row.querySelector('select[name^="team_new_zone"]');
                const name = nameInput?.value?.trim() || '';
                const zoneValue = zoneSelect?.value?.trim() || '';

                if (name === '' && zoneValue === '') {
                    return;
                }

                if (name === '' || zoneValue === '') {
                    zoneErrorMessage = `La squadra "${teamName}" ha un nuovo partecipante senza tutti i dati.`;
                    ok = false;
                    return;
                }

                filledNewRows += 1;

                const normalized = zoneValue.toUpperCase();
                if (usedZones.includes(normalized)) {
                    zoneErrorMessage = `La squadra "${teamName}" ha la zona '${zoneValue}' duplicata.`;
                    ok = false;
                } else {
                    usedZones.push(normalized);
                }
            });

            if (zoneErrorMessage && zoneErrorBox) {
                zoneErrorBox.textContent = zoneErrorMessage;
                zoneErrorBox.classList.remove('d-none');
                ok = false;
            }

            if (teamSizeRequired > 0) {
                const total = existingCount + filledNewRows;
                if (total !== teamSizeRequired) {
                    if (countErrorBox) {
                        countErrorBox.textContent =
                            `La squadra "${teamName}" deve avere ${teamSizeRequired} partecipanti (attuali: ${total}).`;
                        countErrorBox.classList.remove('d-none');
                    }
                    ok = false;
                }
            }
        });

        return ok;
    }

    /* ---------- PROVE / EVENTI (creazione + modifica) ---------- */
    function addEventRow() {
        if (!eventsTable) return;
        const tr = document.createElement('tr');
        const structureField = renderEventStructureField();

        tr.innerHTML = `
            <input type="hidden" name="event_id[]" value="0">
            ${structureField.hidden}
            <td>
                <input type="date" name="event_date[]" class="form-control form-control-sm" required>
            </td>
            <td>
                <input type="text" name="event_name[]" class="form-control form-control-sm" value="Prova">
            </td>
            ${canChooseEventStructure ? `<td>${structureField.cell}</td>` : ''}
            <td><span class="badge bg-secondary">Nuova</span></td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger remove-event-btn">✕</button>
            </td>
        `;
        eventsTable.appendChild(tr);
    }

    if (addEventBtn && eventsTable) {
        addEventBtn.addEventListener('click', () => addEventRow());

        // se il server non ha fornito prove (es. creazione iniziale), aggiungo una riga
        if (eventsTable.querySelectorAll('tr').length === 0) {
            addEventRow();
        }

        eventsTable.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-event-btn')) {
                e.target.closest('tr')?.remove();
            }
        });
    }

    /* ---------- SUBMIT FORM: validazione ---------- */
    /* ---------- PARTECIPANTI (solo torneo individuale) ---------- */
    function getParticipantRowsCount() {
        if (!participantsTable) return 0;
        return participantsTable.querySelectorAll('tr.participant-row').length;
    }

    function updateParticipantsLimitState() {
        if (!participantsContainer) return;
        const totalPegs = formMode === 'pool'
            ? getPoolEffectiveCapacity()
            : calculateTotalPegs();
        const rowsCount = getParticipantRowsCount();
        const totalPlanned = existingActiveParticipants + rowsCount;
        const limitReached = totalPegs > 0 && totalPlanned >= totalPegs;
        const overLimit = totalPegs > 0 && totalPlanned > totalPegs;

        if (participantsLimitAlert) {
            if (limitReached) {
                participantsLimitAlert.classList.remove('d-none');
            } else {
                participantsLimitAlert.classList.add('d-none');
            }
        }
        const overAlert = document.getElementById('participantsOverPegsAlert');
        if (overAlert) {
            overAlert.classList.toggle('d-none', !overLimit);
        }
        if (addParticipantBtn) {
            addParticipantBtn.disabled = limitReached && totalPegs > 0;
        }
    }

    function addParticipantRow(defaultName = '') {
        if (!participantsTable) return;
        const rowsCount = getParticipantRowsCount();
        const totalPegs = calculateTotalPegs();
        const totalPlanned = existingActiveParticipants + rowsCount;
        if (formMode === 'pool') {
            const poolLimit = getPoolEffectiveCapacity();
            if (poolLimit > 0 && totalPlanned >= poolLimit) {
                if (participantsLimitAlert) {
                    participantsLimitAlert.classList.remove('d-none');
                }
                return;
            }
        }
        if (totalPegs > 0 && totalPlanned >= totalPegs) {
            if (participantsLimitAlert) {
                participantsLimitAlert.classList.remove('d-none');
            }
            return;
        }

        const tr = document.createElement('tr');
        tr.classList.add('participant-row');
        tr.innerHTML = `
            <td>
                <input type="text" name="${participantFieldName}" class="form-control form-control-sm"
                       list="participantSuggestions" autocomplete="off"
                       value="${defaultName}" required>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger remove-participant-btn">✕</button>
            </td>
        `;
        participantsTable.appendChild(tr);
        attachParticipantAutocomplete(tr.querySelector('input[list="participantSuggestions"]'));
        updateParticipantsLimitState();
        refreshSelectedParticipants();
        validateParticipantDuplicates();
    }

    if (participantsContainer) {
        if (participantsTable && getParticipantRowsCount() === 0) {
            addParticipantRow();
        } else {
            updateParticipantsLimitState();
        }

        if (addParticipantBtn) {
            addParticipantBtn.addEventListener('click', () => addParticipantRow());
        }

        participantsContainer.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-participant-btn')) {
                e.target.closest('tr')?.remove();
                updateParticipantsLimitState();
                refreshSelectedParticipants();
                validateParticipantDuplicates();
            }
        });
    }

    function validateParticipantDuplicates() {
        const inputs = participantsContainer?.querySelectorAll(`input[name="${participantFieldName}"]`) || [];
        const secondaryInputs = participantsContainer?.querySelectorAll('input[name="participant_new_name[]"]') || [];
        const allInputs = [...inputs, ...secondaryInputs];
        const seen = new Map();
        let hasDuplicate = false;

        allInputs.forEach(input => {
            const value = (input.value || '').trim().toUpperCase();
            input.classList.remove('is-invalid');
            if (!value) return;
            if (seen.has(value)) {
                hasDuplicate = true;
                input.classList.add('is-invalid');
                const other = seen.get(value);
                if (other) other.classList.add('is-invalid');
            } else {
                seen.set(value, input);
            }
        });

        const alert = document.getElementById('participantsDuplicateAlert');
        if (alert) {
            alert.classList.toggle('d-none', !hasDuplicate);
        }
        return !hasDuplicate;
    }

    function createPoolZoneBlock(defaultName = '', defaultPegs = '') {
        if (!poolZonesContainer) return null;
        const block = document.createElement('div');
        block.classList.add('pool-zone-block', 'border', 'rounded', 'p-3', 'mb-3');
        const zoneIndex = poolZonesContainer.querySelectorAll('.pool-zone-block').length + 1;
        const zoneNameValue = defaultName || `Zona ${zoneIndex}`;
        block.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="flex-grow-1 me-3">
                    <label class="form-label">Nome zona</label>
                    <input type="text" name="pool_zone_name[]" class="form-control form-control-sm" required>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger remove-pool-zone-btn mt-4">✕</button>
            </div>
            <label class="form-label d-flex justify-content-between align-items-center">
                <span>Picchetti selezionati</span>
                <span class="text-muted small" data-peg-counter>0</span>
            </label>
            <textarea name="pool_zone_pegs[]" class="form-control" rows="2" placeholder="Clicca sui pulsanti per selezionare" data-peg-output readonly></textarea>
            <div class="form-text">Utilizza i pulsanti ON/OFF per abilitare i picchetti di questa zona. Contatore basato sul limite massimo partecipanti.</div>
            <div class="peg-selector mt-2" data-peg-selector></div>
        `;
        const nameInput = block.querySelector('input[name="pool_zone_name[]"]');
        const pegTextarea = block.querySelector('[data-peg-output]');
        if (nameInput) nameInput.value = zoneNameValue;
        if (pegTextarea) pegTextarea.value = defaultPegs;
        poolZonesContainer.appendChild(block);
        const selector = block.querySelector('[data-peg-selector]');
        renderPegSelector(selector);
        updatePegCounter(block);
        return block;
    }

    if (poolZonesContainer) {
        addPoolZoneBtn?.addEventListener('click', () => {
            createPoolZoneBlock();
            validatePoolPegs();
        });

        poolZonesContainer.addEventListener('click', (e) => {
            const removeBtn = e.target.closest('.remove-pool-zone-btn');
            if (removeBtn) {
                removeBtn.closest('.pool-zone-block')?.remove();
                validatePoolPegs();
                updateParticipantsLimitState();
                refreshPegSelectors();
                return;
            }
            const pegButton = e.target.closest('.peg-toggle-btn');
            if (pegButton) {
                e.preventDefault();
                togglePegButton(pegButton);
            }
        });

        poolZonesContainer.addEventListener('input', (e) => {
            if (e.target.matches('textarea[name="pool_zone_pegs[]"]')) {
                validatePoolPegs();
                updateParticipantsLimitState();
                const block = e.target.closest('.pool-zone-block');
                if (block) updatePegCounter(block);
            }
        });

        const poolCapacityField = document.querySelector('input[name="pool_capacity"]');
        poolCapacityField?.addEventListener('input', () => {
            updateParticipantsLimitState();
            updatePoolCapacityAlert();
            updateLakeDependentState();
            validatePoolPegs();
            refreshPegCounters();
            refreshPegSelectors();
        });

        poolCapacityMaxBtn?.addEventListener('click', (e) => {
            e.preventDefault();
            if (!poolCapacityField) return;
            const available = getAvailableLakePegCount();
            if (available <= 0) return;
            poolCapacityField.value = String(available);
            poolCapacityField.dispatchEvent(new Event('input', { bubbles: true }));
        });

        validatePoolPegs();
        updatePoolCapacityAlert();
        updateLakeDependentState();
        refreshPegCounters();
    }

    attachAutocompleteToAllParticipantInputs();

    function setupAbsenceToggles() {
        if (formMode !== 'individual' && formMode !== 'pool') return;
        const checkboxes = document.querySelectorAll('.participant-absent-toggle');
        checkboxes.forEach(cb => {
            cb.dataset.absentState = cb.checked ? '1' : '0';
            cb.addEventListener('change', () => {
                const prevState = cb.dataset.absentState === '1';
                const nextState = cb.checked;
                if (prevState === nextState) {
                    return;
                }
                if (nextState) {
                    existingActiveParticipants = Math.max(0, existingActiveParticipants - 1);
                } else {
                    existingActiveParticipants += 1;
                }
                cb.dataset.absentState = nextState ? '1' : '0';
                updateParticipantsLimitState();
                validateParticipantDuplicates();
            });
        });
    }

    setupAbsenceToggles();

    if (form) {
        form.addEventListener('submit', (e) => {
            const okZones = validateZones();
            let okTeamZones = true;
            if (formMode === 'team') {
                if (formContext === 'create') {
                    okTeamZones = validateTeamRules();
                    updateTeamErrorMessages();
                } else {
                    okTeamZones = validateTeamEditBlocks();
                }
            }
            const okPoolPegs = formMode === 'pool' ? validatePoolPegs() : true;

            let participantsOk = true;
            if (formMode === 'individual') {
                const rowsCount = getParticipantRowsCount();
                const totalPegs = calculateTotalPegs();
                const totalPlanned = existingActiveParticipants + rowsCount;
                participantsOk = totalPlanned > 0 && (totalPegs === 0 || totalPlanned <= totalPegs);
                if (!participantsOk && participantsLimitAlert) {
                    participantsLimitAlert.classList.remove('d-none');
                }
            }

            if (!okZones || !okTeamZones || !participantsOk || !okPoolPegs) {
                e.preventDefault();
                e.stopPropagation();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    }
});
