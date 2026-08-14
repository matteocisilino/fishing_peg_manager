@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-12 col-lg-8">
        <h1 class="mb-3">Aggiungi iscritto</h1>
        <p class="text-muted mb-4">
            Inserisci manualmente un nuovo nominativo nella directory degli iscritti.
            Nome e cognome sono obbligatori; il telefono è facoltativo.
            La provincia viene compilata automaticamente con quella della struttura di pertinenza se lasciata vuota.
        </p>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <form method="post" action="{{ route('participants.store') }}" class="row g-3">
                    @csrf
                    <div class="col-md-6">
                        <label class="form-label">Nome *</label>
                        <input type="text" name="first_name" value="{{ old('first_name') }}" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cognome *</label>
                        <input type="text" name="last_name" value="{{ old('last_name') }}" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Telefono</label>
                        <input type="text" name="phone" value="{{ old('phone') }}" class="form-control" placeholder="Opzionale">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Provincia (sigla)</label>
                        <input type="text"
                               name="province"
                               value="{{ old('province', $defaultProvince) }}"
                               class="form-control"
                               placeholder="{{ $defaultProvince ? 'Lascia vuoto per usare '.$defaultProvince : 'Es. MI' }}"
                               maxlength="2">
                        <small class="text-muted">
                            Se non specificata, verrà usata la provincia della struttura di pertinenza.
                        </small>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-success">Salva iscritto</button>
                        <a href="{{ route('participants.index') }}" class="btn btn-outline-secondary">Annulla</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
