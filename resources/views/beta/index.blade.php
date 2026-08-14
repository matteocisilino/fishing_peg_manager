@extends('layouts.app')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">Beta Area</div>
            <div class="card-body">
                <p class="mb-3">
                    Welcome to the Beta area: 
                    here we collect prototypes and experimental features.
                    You can already consult some products under implementation : 
                    <ul>
                        <li><a href="{{ route('beta.meteosat') }}">weather data of the events using : Meteosat</a></li>
                    </ul>
                    retrieved for pool tournaments.
                </p>
                @if(auth()->user()?->isSuperAdmin())
                    <hr>
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="fw-semibold">Joker OTP (Super Admin)</div>
                            <div class="text-muted small">Request a reusable code for all operations protected by OTP.</div>
                        </div>
                        <form method="post" action="{{ route('beta.otp_jolly') }}">
                            @csrf
                            <button class="btn btn-sm btn-outline-primary" type="submit">Send joker OTP</button>
                        </form>
                    </div>
                @endif
                <p class="text-muted mb-0">This page is visible to all authenticated users.</p>
            </div>
        </div>
    </div>
</div>
@endsection
