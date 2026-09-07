<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * Serves /support and /privacy as complete HTML from the server.
 *
 * The App Store requires a working support URL and a privacy policy URL, and
 * the reviewer — like any crawler that does not run JavaScript — sees only what
 * the first response contains. Served through the SPA these paths returned an
 * empty body with a correct title: a page that looks reachable to anything
 * checking a status code and is blank to a person.
 *
 * The text comes from the same locale files the Vue application uses, so there
 * is a single source for it. Rendering it twice from one source cannot drift;
 * writing it twice would.
 */
class LegalPageController extends Controller
{
    private const PAGES = ['support', 'privacy'];

    /** Locales with a translation file, and the fallback. */
    private const LOCALES = ['en', 'ar'];
    private const FALLBACK = 'en';

    public function show(Request $request, string $page): View
    {
        abort_unless(in_array($page, self::PAGES, true), 404);

        $locale = $this->resolveLocale($request);
        $messages = $this->messages($locale);

        // A missing or malformed translation must not produce a blank page —
        // that is the failure this controller exists to prevent. English is
        // complete, so it is the floor.
        $t = $messages[$page] ?? $this->messages(self::FALLBACK)[$page];
        $legal = $messages['legal'] ?? $this->messages(self::FALLBACK)['legal'];

        $fallback = $this->messages(self::FALLBACK);

        return view('legal', [
            'page' => $page,
            'locale' => $locale,
            't' => $t,
            'legal' => $legal,
            // Not in the locale files: vue-i18n reserves '@' for its linked
            // message syntax, so an address stored there fails to compile.
            'email' => 'support@diafootcare.tech',
            'titles' => [
                'support' => $messages['support']['title'] ?? $fallback['support']['title'],
                'privacy' => $messages['privacy']['title'] ?? $fallback['privacy']['title'],
            ],
            // Carries the chosen language across the footer links.
            'qs' => $locale === self::FALLBACK ? '' : '?lang='.$locale,
        ]);
    }

    /**
     * ?lang= wins, then Accept-Language, then English.
     *
     * A crawler sends no useful Accept-Language and gets English, which is what
     * the App Store review is conducted in.
     */
    private function resolveLocale(Request $request): string
    {
        $asked = (string) $request->query('lang', '');
        if (in_array($asked, self::LOCALES, true)) {
            return $asked;
        }

        $preferred = $request->getPreferredLanguage(self::LOCALES);

        return in_array($preferred, self::LOCALES, true)
            ? $preferred
            : self::FALLBACK;
    }

    /**
     * @return array<string, mixed>
     */
    private function messages(string $locale): array
    {
        $path = resource_path("js/i18n/locales/{$locale}.json");

        if (! File::exists($path)) {
            $path = resource_path('js/i18n/locales/'.self::FALLBACK.'.json');
        }

        return json_decode(File::get($path), true) ?: [];
    }
}
