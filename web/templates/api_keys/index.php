<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – API Keys List Template
 *
 * Variables available in this template:
 *   \Cronmanager\Web\Auth\ApiKey[] $keys        – own API keys
 *   class-string                  $scopeHelper  – ScopeHelper class name
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

/** @var \Cronmanager\Web\Auth\ApiKey[] $keys */
$keys = isset($keys) && is_array($keys) ? $keys : [];

/** @var string $csrfToken */
?>

<!-- Page header -->
<div class="mb-6 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">API Keys</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Persönliche API Keys für externe Anwendungen.
        </p>
    </div>
    <a href="/api-keys/create"
       class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Neuer API Key
    </a>
</div>

<?php if ($keys === []): ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 px-6 py-12 text-center">
        <svg class="mx-auto mb-3 w-10 h-10 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
        </svg>
        <p class="text-gray-500 dark:text-gray-400 text-sm">Noch keine API Keys vorhanden.</p>
        <a href="/api-keys/create" class="mt-3 inline-block text-blue-600 dark:text-blue-400 text-sm hover:underline">
            Ersten API Key erstellen
        </a>
    </div>
<?php else: ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Scopes</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Agents</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Ablauf</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Letzter Zugriff</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Erstellt</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($keys as $key): ?>
                    <?php
                    $expired = $key->isExpired();
                    ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 <?= $expired ? 'opacity-50' : '' ?>">
                        <!-- Name + status -->
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-gray-800 dark:text-gray-200 text-sm">
                                    <?= $h($key->name) ?>
                                </span>
                                <?php if ($expired): ?>
                                    <span class="text-xs bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 px-1.5 py-0.5 rounded font-medium">
                                        Abgelaufen
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($key->ipWhitelist !== null): ?>
                                <div class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                    IP: <?= $h(implode(', ', $key->ipWhitelist)) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Scopes -->
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <?php foreach ($key->scopes as $scope): ?>
                                    <span class="text-xs px-1.5 py-0.5 rounded font-medium <?= $h($scopeHelper::badgeColor($scope)) ?>">
                                        <?= $h($scopeHelper::label($scope)) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>

                        <!-- Agents -->
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                            <?= $key->agentIds === null ? 'Alle' : implode(', ', array_map(fn($id) => '#' . $id, $key->agentIds)) ?>
                        </td>

                        <!-- Expiry -->
                        <td class="px-4 py-3 text-sm <?= $expired ? 'text-red-600 dark:text-red-400' : 'text-gray-600 dark:text-gray-400' ?>">
                            <?= $key->expiresAt !== null ? $h($key->expiresAt->format('d.m.Y')) : '—' ?>
                        </td>

                        <!-- Last used -->
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                            <?= $key->lastUsedAt !== null ? $h($key->lastUsedAt->format('d.m.Y H:i')) : 'Noch nie' ?>
                        </td>

                        <!-- Created -->
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                            <?= $h($key->createdAt->format('d.m.Y')) ?>
                        </td>

                        <!-- Actions -->
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="/api-keys/<?= (int) $key->id ?>/delete"
                                  onsubmit="return confirm('API Key &quot;<?= $h(addslashes($key->name)) ?>&quot; wirklich löschen?')">
                                <input type="hidden" name="_csrf" value="<?= $h($csrfToken ?? '') ?>">
                                <button type="submit"
                                        class="text-xs text-red-500 hover:text-red-700 dark:hover:text-red-400 font-medium transition-colors">
                                    Löschen
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Info box -->
<div class="mt-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 text-sm text-blue-800 dark:text-blue-300">
    <strong>Hinweis:</strong> Der API Key wird beim Erstellen nur einmal im Klartext angezeigt.
    Speichere ihn sicher, bevor du die Seite verlässt.
    Authentifiziere dich mit: <code class="bg-blue-100 dark:bg-blue-900/40 px-1 rounded">Authorization: Bearer &lt;key&gt;</code>
</div>
