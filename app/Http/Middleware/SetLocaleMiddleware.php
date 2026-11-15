<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Session::get('locale', config('app.locale', 'en_GB'));

        if ($locale && preg_match('/^[A-Za-z_]{2,10}$/', $locale) && (file_exists(base_path("lang/{$locale}.json")) || is_dir(base_path("lang/{$locale}")))) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
