<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Translator (I18n)
 *
 * Loads PHP-array language files and provides translation lookups with
 * optional placeholder replacement.  Language resolution order:
 *   1. Session value ('lang' key)
 *   2. Browser Accept-Language header (first matching supported language)
 *   3. Configuration default (i18n.default_language, fallback: 'en')
 *
 * Language files live in:
 *   <web-root>/lang/{lang}.php
 *
 * Each file must return a plain PHP array, e.g.:
 *   return ['app_name' => 'Cronmanager', ...];
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\I18n;

use Cronmanager\Web\Session\SessionManager;
use Noodlehaus\Config;

/**
 * Class Translator
 *
 * Provides the t() method for translating strings with optional placeholder
 * substitution.  Falls back to the translation key if no matching string
 * is found so that missing translations are always visible during development.
 */
class Translator
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /** @var array<string, string> Loaded translation strings */
    private array $strings = [];

    /** @var string Active language code (e.g. 'en', 'de') */
    private string $lang;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * Resolve the active language and load the corresponding language file.
     *
     * @param Config $config Noodlehaus configuration instance.
     */
    public function __construct(Config $config)
    {
        $available    = (array) $config->get('i18n.available', ['en', 'de']);
        $defaultLang  = (string) $config->get('i18n.default_language', 'en');

        $this->lang = $this->resolveLanguage($available, $defaultLang);
        $this->loadStrings($this->lang);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the translation string for the given key.
     *
     * Placeholder syntax: {name} is replaced by the value in $replacements['name'].
     *
     * Falls back to the key itself when no translation is found, making
     * missing translations immediately visible during development.
     *
     * @param string               $key          Translation key (e.g. 'login_title').
     * @param array<string, mixed> $replacements Optional placeholder map.
     *
     * @return string Translated string with replacements applied.
     */
    public function t(string $key, array $replacements = []): string
    {
        $string = $this->strings[$key] ?? $key;

        foreach ($replacements as $placeholder => $value) {
            $string = str_replace('{' . $placeholder . '}', (string) $value, $string);
        }

        return $string;
    }

    /**
     * Return the currently active language code.
     *
     * @return string Language code, e.g. 'en' or 'de'.
     */
    public function getLang(): string
    {
        return $this->lang;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Determine the active language code.
     *
     * Resolution order:
     *   1. Session: SessionManager::get('lang')
     *   2. Accept-Language header: first tag that matches an available language
     *   3. Config default
     *
     * @param array<int, mixed> $available   List of supported language codes.
     * @param string            $defaultLang Fallback language code.
     *
     * @return string Resolved language code.
     */
    private function resolveLanguage(array $available, string $defaultLang): string
    {
        // Normalise available list to strings
        $available = array_map('strval', $available);

        // 1. Session override
        $sessionLang = (string) SessionManager::get('lang', '');
        if ($sessionLang !== '' && in_array($sessionLang, $available, strict: true)) {
            return $sessionLang;
        }

        // 2. Browser Accept-Language header
        $acceptLanguage = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($acceptLanguage !== '') {
            foreach ($this->parseAcceptLanguage($acceptLanguage) as $tag) {
                // Try exact match first, then just the primary subtag (e.g. 'de' from 'de-AT')
                $primary = strtolower(explode('-', $tag)[0]);
                if (in_array($primary, $available, strict: true)) {
                    return $primary;
                }
            }
        }

        // 3. Config default (fall back to 'en' if not in available list)
        if (in_array($defaultLang, $available, strict: true)) {
            return $defaultLang;
        }

        return 'en';
    }

    /**
     * Parse the Accept-Language header into an ordered list of language tags.
     *
     * Sorts by quality factor (q-value) in descending order.
     *
     * @param string $header Raw Accept-Language header value.
     *
     * @return list<string> Language tags ordered by preference.
     */
    private function parseAcceptLanguage(string $header): array
    {
        $tags = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_contains($part, ';q=')) {
                [$tag, $q] = explode(';q=', $part, 2);
                $tags[trim($tag)] = (float) $q;
            } else {
                $tags[$part] = 1.0;
            }
        }

        arsort($tags);

        return array_keys($tags);
    }

    /**
     * Load the language file for the given language code.
     *
     * The language files are located at:
     *   <web-root>/lang/{lang}.php
     *
     * Falls back to 'en' when the requested file does not exist.
     *
     * @param string $lang Language code to load.
     *
     * @return void
     */
    private function loadStrings(string $lang): void
    {
        // __DIR__ is web/src/I18n – go up three levels to reach web root, then lang/
        $langDir  = dirname(__DIR__, 2) . '/lang';
        $langFile = $langDir . '/' . $lang . '.php';

        if (!file_exists($langFile)) {
            // Fall back to English
            $langFile = $langDir . '/en.php';
            $this->lang = 'en';
        }

        if (file_exists($langFile)) {
            $loaded = require $langFile;
            if (is_array($loaded)) {
                $this->strings = array_map('strval', $loaded);
            }
        }
    }
}
