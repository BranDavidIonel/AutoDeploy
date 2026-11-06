<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/** Language switch (RO / EN) from the top bar. */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', Rule::in(array_keys(config('autodeploy.locales')))],
        ]);

        Cookie::queue(SetLocale::COOKIE, $validated['locale'], 60 * 24 * 365);

        return back();
    }
}
