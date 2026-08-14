@extends('layouts.app')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card">
            <div class="card-body">
                <h1 class="h4 mb-3">New venue</h1>
                <form action="{{ route('structures.store') }}" method="post">
                    @csrf
                    @include('structures._form', ['submitLabel' => 'Save venue', 'structure' => null])
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
