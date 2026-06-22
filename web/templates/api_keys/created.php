<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – API Key Created (One-Time Display) Template
 *
 * Shows the plain-text API key exactly once immediately after creation.
 * The key is cleared from the session before this template renders.
 *
 * Variables available in this template:
 *   string $plainText – the newly generated API key (plain text)
 *   string $keyName   – human-readable label of the new key
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$plainText = isset($plainText) ? (string) $plainText : '';
$keyName   = isset($keyName)   ? (string) $keyName   : 'API Key';
?>

<div class="max-w-2xl">

    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">API Key erstellt</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Dein neuer Key <strong><?= $h($keyName) ?></strong> wurde erfolgreich erstellt.
        </p>
    </div>

    <!-- Warning -->
    <div class="mb-6 bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 rounded-xl p-4 flex gap-3">
        <svg class="flex-shrink-0 w-5 h-5 text-amber-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <div class="text-sm text-amber-800 dark:text-amber-300">
            <strong>Einmalige Anzeige!</strong>
            Kopiere den Key jetzt – er wird danach nicht mehr angezeigt. Nur der Hash ist in der Datenbank gespeichert.
        </div>
    </div>

    <!-- Key display -->
    <div class="bg-gray-900 dark:bg-gray-950 rounded-xl p-5 mb-6 flex items-center gap-3">
        <code id="api-key-value" class="flex-1 text-sm font-mono text-green-400 break-all select-all">
            <?= $h($plainText) ?>
        </code>
        <button id="copy-btn" onclick="copyKey()"
                class="flex-shrink-0 flex items-center gap-1.5 text-xs bg-gray-700 hover:bg-gray-600 text-gray-200
                       px-3 py-1.5 rounded-lg transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
            </svg>
            Kopieren
        </button>
    </div>

    <!-- Usage example -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 mb-6">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Verwendung</p>
        <pre class="bg-gray-50 dark:bg-gray-900 rounded-lg p-3 text-xs font-mono text-gray-700 dark:text-gray-300 overflow-x-auto"><code>curl -H "Authorization: Bearer <?= $h($plainText) ?>" \
     https://&lt;cronmanager-host&gt;/api/v1/jobs</code></pre>
    </div>

    <a href="/api-keys"
       class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2 rounded-lg text-sm transition-colors">
        Zur Key-Übersicht
    </a>
</div>

<script>
function copyKey() {
    const val = document.getElementById('api-key-value')?.textContent?.trim() ?? '';
    navigator.clipboard.writeText(val).then(() => {
        const btn = document.getElementById('copy-btn');
        if (btn) {
            btn.textContent = '✓ Kopiert';
            setTimeout(() => { btn.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg> Kopieren`; }, 2000);
        }
    });
}
</script>
