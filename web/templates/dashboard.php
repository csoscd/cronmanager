<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Dashboard Template
 *
 * Displays aggregated statistics about cron jobs plus recent failures.
 *
 * Variables available in this template:
 *   array  $jobs           – all job records from the agent
 *   array  $recentFailures – last 10 failed execution records
 *   array  $tags           – all known tags
 *   array  $stats          – keys: total, active, inactive, byUser, failedLast24h, tagsCount
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

$jobs           = isset($jobs)           && is_array($jobs)           ? $jobs           : [];
$recentFailures = isset($recentFailures) && is_array($recentFailures) ? $recentFailures : [];
$tags           = isset($tags)           && is_array($tags)           ? $tags           : [];
$stats          = isset($stats)          && is_array($stats)          ? $stats          : [];

$total         = (int) ($stats['total']         ?? 0);
$active        = (int) ($stats['active']        ?? 0);
$inactive      = (int) ($stats['inactive']      ?? 0);
$tagsCount     = (int) ($stats['tagsCount']     ?? 0);
$failedLast24h = (int) ($stats['failedLast24h'] ?? 0);
$byUser        = (array) ($stats['byUser']      ?? []);
?>

<!-- ======================================================================
     Page header
     ====================================================================== -->
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
        <?= htmlspecialchars($t('dashboard_title'), ENT_QUOTES, 'UTF-8') ?>
    </h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        <?= htmlspecialchars(date('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?>
    </p>
</div>

<!-- ======================================================================
     Stat cards row
     ====================================================================== -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">

    <!-- Total jobs -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 flex items-center gap-4">
        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
            <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
        </div>
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($t('dashboard_total_jobs'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $total ?></p>
        </div>
    </div>

    <!-- Active jobs -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 flex items-center gap-4">
        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
            <svg class="w-6 h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($t('dashboard_active'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $active ?></p>
        </div>
    </div>

    <!-- Inactive jobs -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 flex items-center gap-4">
        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center">
            <svg class="w-6 h-6 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($t('dashboard_inactive'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $inactive ?></p>
        </div>
    </div>

    <!-- Tags -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 flex items-center gap-4">
        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center">
            <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
            </svg>
        </div>
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($t('nav_export') !== 'nav_export' ? $t('cron_tags') : 'Tags', ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $tagsCount ?></p>
        </div>
    </div>

</div>

<!-- ======================================================================
     Second row: Recent Failures + Jobs by User
     ====================================================================== -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- Recent failures --------------------------------------------------- -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                <?= htmlspecialchars($t('dashboard_recent_failures'), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <?php if ($failedLast24h > 0): ?>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                    <?= $failedLast24h ?> <?= htmlspecialchars($t('filter_status_failed'), ENT_QUOTES, 'UTF-8') ?> (24h)
                </span>
            <?php endif; ?>
        </div>

        <?php if (empty($recentFailures)): ?>
            <div class="px-6 py-8 text-center text-gray-400 dark:text-gray-500 text-sm">
                <?= htmlspecialchars($t('no_results'), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                <?= htmlspecialchars($t('cron_description'), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <?= htmlspecialchars($t('cron_linux_user'), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <?= htmlspecialchars($t('exit_code'), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <?= htmlspecialchars($t('started_at'), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <?= htmlspecialchars($t('duration'), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php foreach ($recentFailures as $entry): ?>
                            <?php
                                $jobId      = (string) ($entry['job_id']     ?? '');
                                $desc       = (string) ($entry['job_description'] ?? $entry['description'] ?? "Job #{$jobId}");
                                $user       = (string) ($entry['linux_user'] ?? '');
                                $exitCode   = isset($entry['exit_code']) ? (int) $entry['exit_code'] : null;
                                $startedAt  = (string) ($entry['started_at']  ?? '');
                                $duration   = isset($entry['duration_seconds'])
                                    ? round((float) $entry['duration_seconds'], 1) . 's'
                                    : '–';
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-3 text-sm">
                                    <?php if ($jobId !== ''): ?>
                                        <a href="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>"
                                           class="text-blue-600 hover:underline font-medium">
                                            <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    <?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                        <?= $exitCode !== null ? $exitCode : '?' ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                    <?= htmlspecialchars($startedAt, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                    <?= htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Jobs by user ------------------------------------------------------- -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                <?= htmlspecialchars($t('dashboard_jobs_by_user'), ENT_QUOTES, 'UTF-8') ?>
            </h2>
        </div>

        <?php if (empty($byUser)): ?>
            <div class="px-6 py-8 text-center text-gray-400 dark:text-gray-500 text-sm">
                <?= htmlspecialchars($t('no_results'), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                <?= htmlspecialchars($t('cron_linux_user'), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <?= htmlspecialchars($t('dashboard_total_jobs'), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php
                            // Sort by job count descending
                            arsort($byUser);
                            foreach ($byUser as $username => $count):
                        ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-6 py-3 text-sm font-medium text-gray-800 dark:text-gray-200">
                                    <a href="/crons?user=<?= htmlspecialchars(rawurlencode((string) $username), ENT_QUOTES, 'UTF-8') ?>"
                                       class="text-blue-600 hover:underline">
                                        <?= htmlspecialchars((string) $username, ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    <?= (int) $count ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>
