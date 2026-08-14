@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3">
            <div>
                <h1 class="mb-1">Gestione pescatori</h1>
                <p class="text-muted mb-0">
                    Pagina dedicata ai super amministratori per gestire l'anagrafica dei partecipanti, unire duplicati e svolgere cancellazioni con i controlli richiesti.
                </p>
            </div>
            <div class="mt-3 mt-md-0">
                <a href="{{ route('participants.create') }}" class="btn btn-success">
                    + Aggiungi iscritto
                </a>
            </div>
        </div>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label">Filtra per nome o telefono</label>
                        <input type="text" name="q" class="form-control" value="{{ $search }}" placeholder="Cerca..." autocomplete="off">
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <button class="btn btn-primary w-100" type="submit">Filtra</button>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <a href="{{ route('participants.index') }}" class="btn btn-outline-secondary w-100">Pulisci</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                <span>Anagrafica pescatori</span>
                <div class="text-muted small mt-2 mt-md-0 text-md-end">
                    <div>Puoi aggiornare qui nome, telefono e provincia.</div>
                    <div>I campi non sono obbligatori ma aiutano la gestione.</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            @if(auth()->user()?->isSuperAdmin())
                                <th style="width: 40px;" class="text-center">
                                    <input type="checkbox" id="selectAllParticipants">
                                </th>
                            @endif
                            <th style="width: 30%;">Nome attuale</th>
                            <th style="width: 18%;">Telefono</th>
                            @if(auth()->user()?->isSuperAdmin())
                                <th style="width: 18%;">Struttura</th>
                            @endif
                            <th style="width: 12%;">Provincia</th>
                            <th style="width: 10%;">Utilizzi</th>
                            <th style="width: 25%;">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($participants as $participant)
                            <tr id="participant-{{ $participant->id }}">
                                @if(auth()->user()?->isSuperAdmin())
                                    <td class="text-center">
                                        <input type="checkbox"
                                               value="{{ $participant->id }}"
                                               class="form-check-input participant-bulk-checkbox"
                                               form="participants-bulk-assign-form"
                                               name="participant_ids[]">
                                    </td>
                                @endif
                                <td>
                                    <form method="post"
                                          action="{{ route('participants.update', $participant) }}"
                                          id="participant-update-{{ $participant->id }}"
                                          data-participant-update>
                                        @csrf
                                        @method('put')
                                    </form>
                                    <input type="text"
                                           name="name"
                                           class="form-control form-control-sm"
                                           value="{{ $participant->name }}"
                                           form="participant-update-{{ $participant->id }}"
                                           required
                                           autocomplete="off"
                                           data-participant-autosave>
                                    @if($participant->last_known_name)
                                        <small class="text-muted">Nome originale: {{ $participant->last_known_name }}</small>
                                    @endif
                                </td>
                                <td>
                                    <input type="text"
                                           name="phone"
                                           class="form-control form-control-sm"
                                           value="{{ $participant->phone }}"
                                           form="participant-update-{{ $participant->id }}"
                                           autocomplete="off"
                                           data-participant-autosave>
                                </td>
                                @if(auth()->user()?->isSuperAdmin())
                                    <td>
                                        <select name="structure_id"
                                                class="form-select form-select-sm"
                                                form="participant-update-{{ $participant->id }}"
                                                data-participant-autosave>
                                            <option value="">N/D</option>
                                            @foreach($structures as $structure)
                                                <option value="{{ $structure->id }}" @selected($participant->structure_id === $structure->id)>
                                                    {{ $structure->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endif
                                <td>
                                    <input type="text"
                                           name="province"
                                           class="form-control form-control-sm"
                                           value="{{ $participant->province }}"
                                           form="participant-update-{{ $participant->id }}"
                                           autocomplete="off"
                                           data-participant-autosave>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">{{ $usageCounts[$participant->name] ?? 0 }}</span>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <button class="btn btn-sm btn-outline-success" type="submit" form="participant-update-{{ $participant->id }}">Salva</button>
                                        @if(auth()->user()?->isSuperAdmin())
                                            <form method="post" action="{{ route('participants.anonymize', $participant) }}" onsubmit="return confirm('Anonimizzare questo partecipante?');">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-warning" type="submit">Anonimizza</button>
                                            </form>
                                            <form method="post" action="{{ route('participants.otp', $participant) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-primary" type="submit">Invia OTP</button>
                                            </form>
                                        @endif
                                    </div>
                                    @if(auth()->user()?->isSuperAdmin())
                                        <form method="post" action="{{ route('participants.destroy', $participant) }}" class="d-flex gap-2 mt-2">
                                            @csrf
                                            @method('delete')
                                            <input type="text" name="otp_code" class="form-control form-control-sm" placeholder="Codice OTP" maxlength="6">
                                            <button class="btn btn-sm btn-danger" type="submit" onclick="return confirm('Confermi l\'eliminazione definitiva?');">Elimina definitiva</button>
                                        </form>
                                    @endif
                                    <small class="text-muted d-block mt-2" data-autosave-status></small>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">Nessun partecipante trovato.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $participants->links('vendor.pagination.participants') }}
            </div>
        </div>

        @if(auth()->user()?->isSuperAdmin())
            <div class="card">
                <div class="card-header">Unisci nominativi duplicati</div>
                <div class="card-body">
                    <p class="text-muted">Scegli il nominativo errato (da eliminare) e quello corretto (da mantenere). Tutti i dati verranno riallineati sul nome corretto.</p>
                    <form method="post" action="{{ route('participants.merge') }}" class="row g-3 align-items-end">
                        @csrf
                        <div class="col-md-5">
                            <label class="form-label">Partecipante da unire</label>
                            <select name="source_id" class="form-select" required>
                                <option value="">Seleziona</option>
                                @foreach($mergeOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Mantieni come</label>
                            <select name="target_id" class="form-select" required>
                                <option value="">Seleziona</option>
                                @foreach($mergeOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-outline-primary w-100" type="submit">Unisci</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Assegna struttura in blocco</span>
                    <small class="text-muted">Seleziona i nominativi e applica la struttura.</small>
                </div>
                <div class="card-body">
                    <form method="post" action="{{ route('participants.bulk_assign') }}" id="participants-bulk-assign-form" class="row g-3 align-items-end">
                        @csrf
                        <div class="col-md-6">
                            <label class="form-label">Struttura</label>
                            <select name="structure_id" class="form-select" required>
                                <option value="">Seleziona</option>
                                @foreach($structures as $structure)
                                    <option value="{{ $structure->id }}">{{ $structure->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button class="btn btn-outline-primary w-100" type="submit">Applica ai selezionati</button>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="text-muted small" id="bulkAssignSelectionHint">0 selezionati</div>
                        </div>
                    </form>
                    <small class="text-muted d-block mt-2">Usa le spunte nella tabella in alto per selezionare rapidamente più iscritti.</small>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var anchorId = @json(session('scroll_to'));
    if (anchorId) {
        var target = document.getElementById(anchorId);
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const currentPage = new URLSearchParams(window.location.search).get('page') || '';

    function submitParticipantForm(form) {
        if (!form || form.dataset.saving === 'true') {
            return;
        }

        form.dataset.saving = 'true';
        const status = form.closest('tr')?.querySelector('[data-autosave-status]');
        if (status) {
            status.textContent = 'Salvataggio...';
            status.classList.remove('text-success', 'text-danger');
        }

        const formData = new FormData(form);
        if (currentPage) {
            formData.append('page', currentPage);
        }
        let method = (form.getAttribute('method') || 'post').toUpperCase();
        const methodOverride = form.querySelector('input[name="_method"]');
        if (methodOverride) {
            method = methodOverride.value.toUpperCase();
            formData.delete('_method');
        }

        fetch(form.action, {
            method: method,
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            body: formData,
        })
            .then(async (response) => {
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    const message = data.message || (data.errors ? Object.values(data.errors).flat().join(' ') : 'Errore nel salvataggio.');
                    throw new Error(message);
                }
                return response.json();
            })
            .then((data) => {
                if (status) {
                    status.textContent = data.message || 'Dati salvati correttamente.';
                    status.classList.add('text-success');
                }
            })
            .catch((error) => {
                if (status) {
                    status.textContent = error.message || 'Errore nel salvataggio.';
                    status.classList.add('text-danger');
                }
            })
            .finally(() => {
                form.dataset.saving = 'false';
            });
    }

    document.querySelectorAll('[data-participant-update]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitParticipantForm(form);
        });
    });

    document.querySelectorAll('[data-participant-autosave]').forEach(function (input) {
        const triggerSave = function () {
            if (!input.form) {
                return;
            }
            submitParticipantForm(input.form);
        };

        input.addEventListener('blur', triggerSave);
        input.addEventListener('change', triggerSave);
    });

    const bulkCheckboxes = Array.from(document.querySelectorAll('.participant-bulk-checkbox'));
    const bulkHint = document.getElementById('bulkAssignSelectionHint');
    const bulkSelectAll = document.getElementById('selectAllParticipants');
    function refreshBulkHint() {
        const count = bulkCheckboxes.filter(cb => cb.checked).length;
        if (bulkHint) {
            bulkHint.textContent = count + ' selezionati';
        }
    }
    bulkCheckboxes.forEach(cb => cb.addEventListener('change', refreshBulkHint));
    if (bulkSelectAll) {
        bulkSelectAll.addEventListener('change', function () {
            bulkCheckboxes.forEach(cb => {
                cb.checked = bulkSelectAll.checked;
            });
            refreshBulkHint();
        });
    }
    refreshBulkHint();
});
</script>
@endpush
