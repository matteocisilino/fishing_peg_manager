<!doctype html>
<html lang="en">
<head>
    <!-- script type="text/javascript" src="https://embeds.iubenda.com/widgets/45b2a52b-f13b-428d-9048-a687e2c9cb0e.js" async></script -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Torneo Pesca') }}</title>
    
    <!-- PWA Settings -->
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#0284c7">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192x192.png') }}">
    
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >
    <style>
        body { padding-top: 20px; }
        .zone-invalid { background-color: #f8d7da !important; }
        .nav-square .dropdown-toggle {
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 0.5rem;
            padding: 0.3rem 0.9rem;
            color: #fff;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .nav-square .dropdown-toggle::after {
            margin-left: 0.35rem;
        }
        .nav-dropdown-panel {
            min-width: 240px;
            background-color: #111827;
            border-radius: 0.75rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .nav-dropdown-panel .btn {
            text-align: right;
        }
        @media (max-width: 991px) {
            #navbarNav .navbar-nav {
                align-items: stretch !important;
                gap: 0.75rem;
            }
            #navbarNav .nav-item {
                width: 100%;
            }
            #navbarNav .nav-link {
                display: block;
                width: 100%;
                text-align: center;
            }
            #navbarNav .nav-square .dropdown-toggle {
                width: 100%;
                justify-content: center;
            }
            #navbarNav .nav-dropdown-panel {
                width: 100%;
                border: none;
                background: transparent;
                padding: 0;
            }
            #navbarNav .nav-dropdown-panel .btn {
                width: 100%;
            }
        }
        .alert-lightbox {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            cursor: pointer;
            padding: 1rem;
        }
        .alert-lightbox .alert {
            min-width: 320px;
            max-width: 600px;
            cursor: pointer;
        }
        .input-peg {
            max-width: 80px;
            min-width: 70px;
            text-align: center;
            display: inline-block;
        }
        .input-counter {
            max-width: 110px;
            min-width: 90px;
            text-align: center;
            display: inline-block;
            font-weight: 600;
        }
        .sector-size-group .btn {
            min-width: 48px;
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }
        .lake-option {
            border: 1px solid rgba(0, 0, 0, 0.12);
            border-radius: 0.65rem;
            padding: 0.65rem 0.8rem;
            cursor: pointer;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .lake-option .form-check-input {
            margin-top: 0.2rem;
        }
        .lake-option:hover {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.1rem rgba(13, 110, 253, 0.15);
        }
        .nav-link-beta {
            color: #ff5fa2 !important;
            font-weight: 700;
            font-size: 0.9rem;
        }
        /* Zebra striping per tutte le tabelle per leggibilità */
        .table tbody tr:nth-of-type(odd) {
            background-color: #f8f9fa;
        }
        .table tbody tr:nth-of-type(even) {
            background-color: #ffffff;
        }
        @media (max-width: 576px) {
            .input-peg,
            .input-counter {
                max-width: 100%;
                min-width: 0;
            }
        }
    </style>
    @stack('styles')
</head>
<body>
@php
    $isAuthPage = request()->routeIs('login', 'login.*', 'password.*', 'otp.*');
@endphp

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="{{ route('dashboard') }}">Fishing Tournament</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto align-items-center gap-2 flex-column flex-lg-row">
                @auth
                    @php
                        /** @var \App\Models\User $navUser */
                        $navUser = auth()->user();
                        $structureLabel = 'Structures';
                        if($navUser->role === \App\Models\User::ROLE_ADMIN) {
                            $structureCount = $navUser->structures()->count();
                            $structureLabel = $structureCount === 1 ? 'Structure' : 'Structures';
                        }
                    @endphp
                    <li class="nav-item"><a class="nav-link" href="{{ route('dashboard') }}">Home</a></li>

                    @if($navUser->isSuperAdmin())
                        <li class="nav-item dropdown nav-square" data-dropdown-manual-container>
                            <a class="dropdown-toggle nav-link" href="#" role="button" data-dropdown-manual aria-expanded="false">
                                Activities
                            </a>
                            <div class="dropdown-menu dropdown-menu-end nav-dropdown-panel p-3 shadow">
                                <div class="d-flex flex-column gap-2 text-end">
                                    <a class="btn btn-outline-light btn-sm" href="{{ route('tournaments.create_pool') }}">Create pool tournament</a>
                                    <a class="btn btn-outline-light btn-sm" href="{{ route('tournaments.create_individual') }}">Create individual tournament</a>
                                    <a class="btn btn-outline-light btn-sm" href="{{ route('tournaments.create') }}">Create team tournament</a>
                                </div>
                            </div>
                        </li>
                        <li class="nav-item dropdown nav-square" data-dropdown-manual-container>
                            <a class="dropdown-toggle nav-link" href="#" role="button" data-dropdown-manual aria-expanded="false">
                                Settings
                            </a>
                            <div class="dropdown-menu dropdown-menu-end nav-dropdown-panel p-3 shadow">
                                <div class="d-flex flex-column gap-2 text-end">
                                    <a class="btn btn-outline-light btn-sm" href="{{ route('security-settings.edit') }}">OTP Security</a>
                                    <a class="btn btn-outline-light btn-sm" href="{{ route('trusted-devices.index') }}">Devices</a>
                                    <a class="btn btn-outline-light btn-sm" href="{{ route('structures.index') }}">Structures</a>
                                    <a class="btn btn-outline-light btn-sm" href="{{ route('users.index') }}">Users</a>
                                    <a class="btn btn-outline-light btn-sm" href="{{ route('participants.index') }}">Anglers</a>
                                </div>
                            </div>
                        </li>
                    @elseif($navUser->role === \App\Models\User::ROLE_ADMIN)
                        <span class="text-light d-none d-lg-inline">|</span>
                        <li class="nav-item"><a class="nav-link" href="{{ route('tournaments.create') }}">New tournament</a></li>
                        <span class="text-light d-none d-lg-inline">|</span>
                        <li class="nav-item"><a class="nav-link" href="{{ route('tournaments.create_individual') }}">New individual tournament</a></li>
                        <span class="text-light">|</span>
                        <li class="nav-item"><a class="nav-link" href="{{ route('tournaments.create_pool') }}">New pool tournament</a></li>
                        <span class="text-light">|</span>
                        <li class="nav-item"><a class="nav-link" href="{{ route('structures.index') }}">{{ $structureLabel }}</a></li>
                        <span class="text-light">|</span>
                    @endif

                    <span class="text-light d-none d-lg-inline">|</span>
                    <li class="nav-item"><a class="nav-link" href="{{ route('weights.index') }}">Insert weights</a></li>
                    @if($navUser->hasRole(\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ADMIN))
                        <span class="text-light d-none d-lg-inline">|</span>
                        <li class="nav-item"><a class="nav-link" href="{{ route('statistics.index') }}">Statistics</a></li>
                    @endif
                    @if($navUser->hasRole(\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ADMIN))
                        <span class="text-light d-none d-lg-inline">|</span>
                        <li class="nav-item"><a class="nav-link" href="{{ route('participants.index') }}">Manage Participants</a></li>
                    @endif
                    <span class="text-light d-none d-lg-inline">|</span>
                    <li class="nav-item"><a class="nav-link nav-link-beta" href="{{ route('beta.index') }}">Beta</a></li>
                @endauth
            </ul>
        </div>
        <div class="d-flex align-items-center gap-2 text-white">
            @auth
                <span class="text-uppercase small badge bg-secondary">
                    {{ str_replace('_', ' ', auth()->user()->role) }}
                </span>
                <span>{{ auth()->user()->name }}</span>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-light btn-sm">Logout</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="btn btn-outline-light btn-sm">Login</a>
            @endauth
        </div>
    </div>
</nav>
<div class="container">

    @if(!$isAuthPage)
        @if(session('status'))
            <div class="alert-lightbox" data-alert-lightbox>
                <div class="alert alert-success shadow">
                    <strong>{{ session('status') }}</strong>
                    <div class="small text-muted mt-1">Click anywhere to close</div>
                </div>
            </div>
        @endif

        @if ($errors->has('general'))
            <div class="alert-lightbox" data-alert-lightbox>
                <div class="alert alert-danger shadow">
                    <strong>{{ $errors->first('general') }}</strong>
                    <div class="small text-muted mt-1">Click anywhere to close</div>
                </div>
            </div>
        @endif
    @else
        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->has('general'))
            <div class="alert alert-danger">{{ $errors->first('general') }}</div>
        @endif
    @endif

    @yield('content')
</div>

<footer class="text-center mt-4 mb-4 text-muted">
    @php
        $releaseInfo = $appReleaseInfo ?? [
            'version_label' => 'Version 1',
            'release' => 'dev',
            'timestamp' => now()->format('d/m/Y H:i'),
        ];
    @endphp
    <p class="mb-1 text-primary fw-bold">
        Powered by <a href="https://github.com/matteocisilino" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-primary">Matteo</a>
    </p>
    <p class="mb-0 small">
        {{ $releaseInfo['version_label'] }} {{ $releaseInfo['release'] }}
        <span class="d-block d-md-inline text-muted mt-1 mt-md-0">Last modified {{ $releaseInfo['timestamp'] }}</span>
    </p>
</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"
></script>
<script src="{{ asset('js/tournament.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const alertLightboxTimeout = {{ json_encode((int) config('app.alert_lightbox_timeout', 1000)) }};
    @if(!$isAuthPage)
    // Disable cache/autocomplete in the application area forms
    (function disableAutocomplete() {
        document.querySelectorAll('form').forEach(function (form) {
            if (form.dataset.allowAutocomplete === '1') return;
            form.setAttribute('autocomplete', 'off');
            form.querySelectorAll('input, textarea, select').forEach(function (field) {
                if (field.dataset.allowAutocomplete === '1') return;
                field.setAttribute('autocomplete', 'off');
            });
        });
    })();
    @endif
    var dropdownTriggers = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
    dropdownTriggers.forEach(function (triggerEl) {
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
            new bootstrap.Dropdown(triggerEl);
        }
    });

    var manualDropdownTriggers = [].slice.call(document.querySelectorAll('[data-dropdown-manual]'));
    function closeManualDropdowns() {
        document.querySelectorAll('[data-dropdown-manual-container] .dropdown-menu').forEach(function (menu) {
            menu.classList.remove('show');
        });
        manualDropdownTriggers.forEach(function (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        });
    }
    manualDropdownTriggers.forEach(function (triggerEl) {
        triggerEl.addEventListener('click', function (event) {
            event.preventDefault();
            var parent = triggerEl.closest('[data-dropdown-manual-container]');
            if (!parent) return;
            var menu = parent.querySelector('.dropdown-menu');
            if (!menu) return;
            var isOpen = menu.classList.contains('show');
            closeManualDropdowns();
            if (!isOpen) {
                menu.classList.add('show');
                triggerEl.setAttribute('aria-expanded', 'true');
            }
        });
    });
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-dropdown-manual-container]')) {
            return;
        }
        closeManualDropdowns();
    });

    document.querySelectorAll('[data-alert-lightbox]').forEach(function (lightbox) {
        var closeLightbox = function () {
            if (lightbox.parentNode) {
                lightbox.remove();
            }
        };

        lightbox.addEventListener('click', closeLightbox);
        setTimeout(closeLightbox, alertLightboxTimeout);
    });
});
</script>
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('/sw.js').then(function(registration) {
                console.log('ServiceWorker registration successful with scope: ', registration.scope);
            }, function(err) {
                console.log('ServiceWorker registration failed: ', err);
            });
        });
    }
</script>
@stack('scripts')
</body>
</html>
