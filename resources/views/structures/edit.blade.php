@extends('layouts.app')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card">
            <div class="card-body">
                <h1 class="h4 mb-3">Edit venue</h1>
                <form action="{{ route('structures.update', $structure) }}" method="post">
                    @csrf
                    @method('put')
                    @include('structures._form', ['submitLabel' => 'Update venue', 'structure' => $structure])
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
