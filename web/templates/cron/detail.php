<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Cron Job Detail Template
 *
 * Displays all attributes of a single cron job plus its execution history.
 *
 * Variables available in this template:
 *   array  $job     – job record from the agent
 *   array  $history – up to 20 most recent execution records for this job
 *   bool   $isAdmin – whether the current user has admin role
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

$job     = isset($job)     && is_array($job)     ? $job     : [];
$history = isset($history) && is_array($history) ? $history : [];
$isAdmin = isset($isAdmin) && (bool) $isAdmin;

$jobId     = (string) ($job['id']             ?? '');
$desc      = (string) ($job['description']    ?? "Job #{$jobId}");
$user      = (string) ($job['linux_user']     ?? '');
$sched     = (string) ($job['schedule']       ?? '');
$command   = (string) ($job['command']        ?? '');
$jobTags    = (array)  ($job['tags']           ?? []);
$jobTargets = (array)  ($job['targets']        ?? ['local']);
$active     = !empty($job['active']);
$notify     = !empty($job['notify_on_failure']);
$created    = (string) ($job['created_at']     ?? '');
$lastRun    = (string) ($job['last_run']       ?? '');
?>

<!-- ======================================================================
     Breadcrumb / back link
     ====================================================================== -->
<div class="mb-4">
    <a href="/crons" class="inline-flex items-center text-sm text-blue-600 hover:underline">
        &larr; <?= htmlspecialchars($t('crons_title'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<!-- ======================================================================
     Job detail card
     ====================================================================== -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 mb-6">

    <!-- Header row with title + admin actions -->
    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>
            </h1>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <!-- Active / Inactive badge -->
                <?php if ($active): ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        <?= htmlspecialchars($t('cron_active'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                        <?= htmlspecialchars($t('cron_inactive'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>

                <!-- Target badges -->
                <?php foreach ($jobTargets as $tgt): ?>
                    <?php $tgt = (string) $tgt; ?>
                    <?php if ($tgt === 'local'): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
                            <?= htmlspecialchars($t('cron_local_badge'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 font-mono">
                            <?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- Tag badges -->
                <?php foreach ($jobTags as $tag): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                        <?= htmlspecialchars((string) $tag, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Admin: Edit + Delete -->
        <?php if ($isAdmin && $jobId !== ''): ?>
            <div class="flex items-center gap-2 flex-shrink-0">
                <a href="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>/edit"
                   class="inline-flex items-center gap-1 bg-blue-600 hover:bg-blue-700 text-white
                          text-sm font-medium px-4 py-2 rounded-lg transition focus:outline-none
                          focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <?= htmlspecialchars($t('cron_edit'), ENT_QUOTES, 'UTF-8') ?>
                </a>
                <form method="POST"
                      action="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>/delete"
                      onsubmit="return confirm('<?= htmlspecialchars($t('cron_delete_confirm'), ENT_QUOTES, 'UTF-8') ?>')">
                    <button type="submit"
                            class="inline-flex items-center gap-1 bg-red-50 hover:bg-red-100 text-red-700
                                   text-sm font-medium px-4 py-2 rounded-lg border border-red-200 transition
                                   focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                        <?= htmlspecialchars($t('cron_delete'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Detail grid -->
    <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">

        <!-- Linux user -->
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_linux_user'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm text-gray-900 dark:text-gray-100 font-medium">
                <?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?>
            </dd>
        </div>

        <!-- Schedule -->
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_schedule'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm text-gray-900 dark:text-gray-100 font-mono">
                <?= htmlspecialchars($sched, ENT_QUOTES, 'UTF-8') ?>
            </dd>
        </div>

        <!-- Command -->
        <div class="sm:col-span-2">
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_command'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm text-gray-900 dark:text-gray-100 font-mono bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600
                       rounded-lg px-3 py-2 break-all">
                <?= htmlspecialchars($command, ENT_QUOTES, 'UTF-8') ?>
            </dd>
        </div>

        <!-- Created at -->
        <?php if ($created !== ''): ?>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                    <?= htmlspecialchars($t('cron_created_at'), ENT_QUOTES, 'UTF-8') ?>
                </dt>
                <dd class="text-sm text-gray-600 dark:text-gray-300">
                    <?= htmlspecialchars($created, ENT_QUOTES, 'UTF-8') ?>
                </dd>
            </div>
        <?php endif; ?>

        <!-- Last run -->
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_last_run'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm text-gray-600">
                <?= htmlspecialchars($lastRun !== '' ? $lastRun : $t('cron_never_run'), ENT_QUOTES, 'UTF-8') ?>
            </dd>
        </div>

        <!-- Notify on failure -->
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_notify_on_failure'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm text-gray-600">
                <?= $notify ? '✓' : '—' ?>
            </dd>
        </div>

        <!-- Targets -->
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_targets'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm flex flex-wrap gap-1">
                <?php foreach ($jobTargets as $tgt): ?>
                    <?php $tgt = (string) $tgt; ?>
                    <?php if ($tgt === 'local'): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            <?= htmlspecialchars($t('cron_local_badge'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 font-mono">
                            <?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </dd>
        </div>

    </div>
</div>

<!-- ======================================================================
     Execution history section
     ====================================================================== -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
        <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200">
            <?= htmlspecialchars($t('cron_history'), ENT_QUOTES, 'UTF-8') ?>
        </h2>
    </div>

    <?php if (empty($history)): ?>
        <div class="px-6 py-10 text-center text-gray-400 dark:text-gray-500 text-sm">
            <?= htmlspecialchars($t('no_results'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('started_at'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('finished_at'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('duration'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_host'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('exit_code'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('output'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($history as $idx => $entry): ?>
                        <?php
                            $startedAt  = (string) ($entry['started_at']      ?? '');
                            $finishedAt = (string) ($entry['finished_at']     ?? '');
                            $exitCode   = isset($entry['exit_code']) ? $entry['exit_code'] : null;
                            $duration   = isset($entry['duration_seconds'])
                                ? round((float) $entry['duration_seconds'], 1) . 's'
                                : '–';
                            $entryTarget = (string) ($entry['target'] ?? '');
                            $output      = (string) ($entry['output'] ?? '');
                            $outputId    = 'hist-output-' . $idx;
                            $outputTrunc = mb_strlen($output) > 200
                                ? mb_substr($output, 0, 200) . '…'
                                : $output;

                            // Exit code badge
                            if ($exitCode === null) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">'
                                    . htmlspecialchars($t('status_running'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
                            } elseif ((int) $exitCode === 0) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">0</span>';
                            } else {
                                $safeCode  = htmlspecialchars((string) $exitCode, ENT_QUOTES, 'UTF-8');
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">' . $safeCode . '</span>';
                            }
                        ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 align-top">
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                <?= htmlspecialchars($startedAt, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                <?= htmlspecialchars($finishedAt, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                <?= htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="px-4 py-3 text-sm whitespace-nowrap">
                                <?php if ($entryTarget === '' || $entryTarget === 'local'): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        <?= htmlspecialchars($t('cron_local_badge'), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 font-mono">
                                        <?= htmlspecialchars($entryTarget, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <?= $exitBadge ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 max-w-sm">
                                <?php if ($output !== ''): ?>
                                    <span id="<?= htmlspecialchars($outputId . '-short', ENT_QUOTES, 'UTF-8') ?>"
                                          class="font-mono text-xs break-all">
                                        <?= htmlspecialchars($outputTrunc, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if (mb_strlen($output) > 200): ?>
                                        <span id="<?= htmlspecialchars($outputId . '-full', ENT_QUOTES, 'UTF-8') ?>"
                                              class="font-mono text-xs break-all hidden">
                                            <?= htmlspecialchars($output, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <button type="button"
                                                onclick="toggleOutput('<?= htmlspecialchars($outputId, ENT_QUOTES, 'UTF-8') ?>')"
                                                class="ml-1 text-xs text-blue-600 hover:underline focus:outline-none">
                                            show more
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
/**
 * Toggle between truncated and full output for a history entry.
 *
 * @param {string} id Base element id (without -short / -full suffix).
 */
function toggleOutput(id) {
    const shortEl = document.getElementById(id + '-short');
    const fullEl  = document.getElementById(id + '-full');
    const btn     = event.target;

    if (!shortEl || !fullEl) return;

    if (fullEl.classList.contains('hidden')) {
        shortEl.classList.add('hidden');
        fullEl.classList.remove('hidden');
        btn.textContent = 'show less';
    } else {
        fullEl.classList.add('hidden');
        shortEl.classList.remove('hidden');
        btn.textContent = 'show more';
    }
}
</script>
