<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the app language for every request: the one chosen in the top bar
 * (the "locale" cookie), otherwise APP_LOCALE (English by default).
 */
class SetLocale
{
    public const string COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie(self::COOKIE);

        if (! is_string($locale) || ! array_key_exists($locale, config('autodeploy.locales'))) {
            $locale = config('app.locale');
        }

        app()->setLocale($locale);
        Date::setLocale($locale);

        return $next($request);
    }
}
