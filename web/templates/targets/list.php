<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Targets & Maintenance Windows List
 *
 * Shows all maintenance windows grouped by target.  Admin action buttons
 * are rendered for each window row.
 *
 * Variables available:
 *   array<string, array> $byTarget – windows grouped by target name
 *   bool                 $isAdmin  – whether the current user has admin role
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

/** @var callable(string): string $h */
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$byTarget = isset($byTarget) && is_array($byTarget) ? $byTarget : [];
$isAdmin  = isset($isAdmin) && (bool) $isAdmin;

/** @var string $csrf_token */
?>

<!-- Page header -->
<div class="mb-6 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
            <?= $h($t('targets_title')) ?>
        </h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 max-w-3xl">
            <?= $h($t('targets_desc')) ?>
        </p>
    </div>
</div>

<?php if (empty($byTarget)): ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 px-6 py-12 text-center text-gray-400 dark:text-gray-500 text-sm">
        <?= $h($t('no_results')) ?>
    </div>
<?php else: ?>

    <?php foreach ($byTarget as $targetName => $windows): ?>

        <div class="mb-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">

            <!-- Target header -->
            <div class="px-5 py-3 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        <?= $targetName === 'local'
                            ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'
                            : 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' ?>">
                        <?= $targetName === 'local' ? 'local' : 'ssh' ?>
                    </span>
                    <span class="font-semibold text-gray-900 dark:text-gray-100">
                        <?= $h($targetName) ?>
                    </span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        (<?= count($windows) ?> window<?= count($windows) !== 1 ? 's' : '' ?>)
                    </span>
                </div>
                <?php if ($isAdmin): ?>
                    <a href="/targets/<?= rawurlencode($targetName) ?>/windows/new"
                       class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-200 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        <?= $h($t('targets_add_window')) ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Windows table -->
            <?php if (empty($windows)): ?>
                <div class="px-5 py-5 text-sm text-gray-400 dark:text-gray-500 italic">
                    <?= $h($t('targets_no_windows')) ?>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <?= $h($t('targets_window_schedule')) ?>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <?= $h($t('targets_window_duration')) ?>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <?= $h($t('targets_window_description')) ?>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <?= $h($t('targets_window_active')) ?>
                                </th>
                                <?php if ($isAdmin): ?>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        <?= $h($t('actions')) ?>
                                    </th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <?php foreach ($windows as $window): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-4 py-3 text-sm font-mono text-gray-900 dark:text-gray-100">
                                        <?= $h((string) ($window['cron_schedule'] ?? '')) ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                        <?= (int) ($window['duration_minutes'] ?? 0) ?> <?= $h($t('targets_window_duration_min')) ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                        <?= $h((string) ($window['description'] ?? '—')) ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ((bool) ($window['active'] ?? false)): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">
                                                <?= $h($t('cron_active')) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                                                <?= $h($t('cron_inactive')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($isAdmin): ?>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center gap-2">
                                                <a href="/targets/windows/<?= (int) ($window['id'] ?? 0) ?>/edit"
                                                   class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                                                    <?= $h($t('targets_window_edit')) ?>
                                                </a>

                                                <form method="POST"
                                                      action="/targets/windows/<?= (int) ($window['id'] ?? 0) ?>/delete"
                                                      onsubmit="return confirm(<?= $h(json_encode($t('targets_window_delete_confirm'))) ?>)">
                                                    <input type="hidden" name="_csrf" value="<?= $h($csrf_token) ?>">
                                                    <button type="submit"
                                                            class="text-xs text-red-600 dark:text-red-400 hover:underline">
                                                        <?= $h($t('targets_window_delete')) ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php endforeach; ?>

<?php endif; ?>
