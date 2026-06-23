<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – API Key Create Form Template
 *
 * Variables available in this template:
 *   string[]            $allowedScopes – scopes the current user may grant
 *   array<string,list>  $profiles      – pre-defined scope profiles
 *   string[]            $allScopes     – all scope identifiers in order
 *   class-string        $scopeHelper   – ScopeHelper class name
 *   array[]             $agents        – all agents from the database
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$allowedScopes = isset($allowedScopes) && is_array($allowedScopes) ? $allowedScopes : [];
$profiles      = isset($profiles)      && is_array($profiles)      ? $profiles      : [];
$allScopes     = isset($allScopes)     && is_array($allScopes)     ? $allScopes     : [];
$agents        = isset($agents)        && is_array($agents)        ? $agents        : [];
?>

<div class="max-w-2xl">

    <!-- Page header -->
    <div class="mb-6">
        <a href="/api-keys" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 flex items-center gap-1 mb-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Zurück
        </a>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Neuer API Key</h1>
    </div>

    <form method="POST" action="/api-keys" id="create-api-key-form">
        <input type="hidden" name="_csrf" value="<?= $h($csrf_token ?? '') ?>">

        <div class="space-y-6">

            <!-- Name -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                <label for="key-name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Name <span class="text-red-500">*</span>
                </label>
                <input type="text" id="key-name" name="name" required
                       placeholder="z.B. Grafana Monitoring, Deploy-Pipeline"
                       class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700
                              text-gray-900 dark:text-gray-100 px-3 py-2 text-sm
                              focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    Erkennbarer Name für diesen Key (nur für dich sichtbar).
                </p>
            </div>

            <!-- Profiles (quick select) -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Schnellauswahl (Profil)</p>
                <div class="flex flex-wrap gap-2 mb-4" id="profile-buttons">
                    <?php foreach ($profiles as $profileName => $profileScopes): ?>
                        <?php
                        $available = array_intersect($profileScopes, $allowedScopes);
                        if ($available === []) {
                            continue;
                        }
                        ?>
                        <button type="button"
                                data-profile="<?= $h(json_encode(array_values($available), JSON_THROW_ON_ERROR)) ?>"
                                class="profile-btn text-sm px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600
                                       text-gray-700 dark:text-gray-300 hover:bg-blue-50 dark:hover:bg-blue-900/20
                                       hover:border-blue-400 dark:hover:border-blue-500 transition-colors">
                            <?= $h($profileName) ?>
                        </button>
                    <?php endforeach; ?>
                    <button type="button" id="clear-scopes"
                            class="text-sm px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600
                                   text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        Alle abwählen
                    </button>
                </div>

                <!-- Scope checkboxes -->
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Berechtigungen <span class="text-red-500">*</span></p>
                <div class="space-y-2">
                    <?php foreach ($allScopes as $scope): ?>
                        <?php $allowed = in_array($scope, $allowedScopes, strict: true); ?>
                        <label class="flex items-center gap-3 <?= $allowed ? 'cursor-pointer' : 'opacity-40 cursor-not-allowed' ?>">
                            <input type="checkbox" name="scopes[]" value="<?= $h($scope) ?>"
                                   class="scope-checkbox w-4 h-4 text-blue-600 rounded border-gray-300 dark:border-gray-600
                                          focus:ring-blue-500"
                                   <?= $allowed ? '' : 'disabled' ?>>
                            <div>
                                <span class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                    <?= $h($scopeHelper::label($scope)) ?>
                                </span>
                                <span class="ml-2 text-xs text-gray-400 dark:text-gray-500 font-mono"><?= $h($scope) ?></span>
                                <?php if (!$allowed): ?>
                                    <span class="ml-2 text-xs text-gray-400 dark:text-gray-500">(nicht erlaubt für deine Rolle)</span>
                                <?php endif; ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Optional: Agent restriction -->
            <?php if (count($agents) > 1): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Agents (optional)</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mb-3">
                    Leer lassen = Key gilt für alle Agents.
                </p>
                <div class="space-y-2">
                    <?php foreach ($agents as $agent): ?>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="agent_ids[]" value="<?= (int) $agent['id'] ?>"
                                   class="w-4 h-4 text-blue-600 rounded border-gray-300 dark:border-gray-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-800 dark:text-gray-200">
                                <?= $h((string) $agent['name']) ?>
                                <span class="text-xs text-gray-400 dark:text-gray-500 ml-1">
                                    (<?= $h((string) $agent['url']) ?>)
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Optional: Expiry + IP -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">Optionale Einschränkungen</p>
                <div class="space-y-4">
                    <!-- Expiry -->
                    <div>
                        <label for="expires-at" class="block text-sm text-gray-700 dark:text-gray-300 mb-1">
                            Ablaufdatum
                        </label>
                        <input type="date" id="expires-at" name="expires_at"
                               min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                               class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700
                                      text-gray-900 dark:text-gray-100 px-3 py-2 text-sm
                                      focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Leer = kein Ablauf</p>
                    </div>

                    <!-- IP whitelist -->
                    <div>
                        <label for="ip-whitelist" class="block text-sm text-gray-700 dark:text-gray-300 mb-1">
                            IP-Whitelist
                        </label>
                        <input type="text" id="ip-whitelist" name="ip_whitelist"
                               placeholder="10.0.0.0/8, 192.168.1.5/32"
                               class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700
                                      text-gray-900 dark:text-gray-100 px-3 py-2 text-sm
                                      focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            Komma-getrennte CIDR-Blöcke. Leer = keine Einschränkung.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="flex items-center gap-3">
                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2 rounded-lg text-sm transition-colors">
                    API Key erstellen
                </button>
                <a href="/api-keys" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">
                    Abbrechen
                </a>
            </div>

        </div><!-- /space-y-6 -->
    </form>
</div>

<script>
(function () {
    const checkboxes = document.querySelectorAll('.scope-checkbox:not([disabled])');

    document.querySelectorAll('.profile-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const scopes = JSON.parse(btn.dataset.profile);
            checkboxes.forEach(cb => {
                cb.checked = scopes.includes(cb.value);
            });
        });
    });

    document.getElementById('clear-scopes')?.addEventListener('click', () => {
        checkboxes.forEach(cb => { cb.checked = false; });
    });

    document.getElementById('create-api-key-form')?.addEventListener('submit', e => {
        const any = [...checkboxes].some(cb => cb.checked);
        if (!any) {
            e.preventDefault();
            alert('Bitte wähle mindestens einen Scope aus.');
        }
    });
}());
</script>
