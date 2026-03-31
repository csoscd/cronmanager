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

$jobId         = (string) ($job['id']             ?? '');
$desc          = (string) ($job['description']    ?? "Job #{$jobId}");
$user          = (string) ($job['linux_user']     ?? '');
$sched         = (string) ($job['schedule']       ?? '');
$scheduleHuman = isset($scheduleHuman) ? (string) $scheduleHuman : '';
$command       = (string) ($job['command']        ?? '');
$jobTags       = (array)  ($job['tags']            ?? []);
$jobTargets    = (array)  ($job['targets']         ?? ['local']);
$active        = !empty($job['active']);
$notify        = !empty($job['notify_on_failure']);
$created       = (string) ($job['created_at']      ?? '');
$lastRun       = (string) ($job['last_run']        ?? '');
$limitSeconds  = isset($job['execution_limit_seconds']) && $job['execution_limit_seconds'] !== null
    ? (int) $job['execution_limit_seconds']
    : null;
$autoKill      = !empty($job['auto_kill_on_limit']);
?>

<!-- ======================================================================
     Breadcrumb / back link
     ====================================================================== -->
<div class="mb-4">
    <a href="/crons" class="inline-flex items-center text-sm text-blue-600 hover:underline">
        &larr; <?= htmlspecialchars($t('crons_title'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<?php
// Flash messages from kill action
$killNoticeKey = \Cronmanager\Web\Session\SessionManager::flash('_flash_kill_notice');
$killErrorKey  = \Cronmanager\Web\Session\SessionManager::flash('_flash_kill_error');
?>
<?php if ($killNoticeKey !== null): ?>
<div class="mb-4 flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
    </svg>
    <?= htmlspecialchars($t($killNoticeKey), ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>
<?php if ($killErrorKey !== null): ?>
<div class="mb-4 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <?= htmlspecialchars($t($killErrorKey), ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

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

        <!-- Actions: Monitor (all users) + Edit/Delete (admin) -->
        <div class="flex items-center gap-2 flex-shrink-0">
            <?php if ($jobId !== ''): ?>
                <a href="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>/monitor"
                   class="inline-flex items-center gap-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700
                          text-sm font-medium px-4 py-2 rounded-lg border border-indigo-200 transition
                          focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-2">
                    <!-- Chart bar icon -->
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <?= htmlspecialchars($t('monitor_link'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endif; ?>

        <!-- Admin: Edit + Copy + Delete + Run Now -->
        <?php if ($isAdmin && $jobId !== ''): ?>
                <a href="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>/edit"
                   class="inline-flex items-center gap-1 bg-blue-600 hover:bg-blue-700 text-white
                          text-sm font-medium px-4 py-2 rounded-lg transition focus:outline-none
                          focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <?= htmlspecialchars($t('cron_edit'), ENT_QUOTES, 'UTF-8') ?>
                </a>
                <a href="/crons/new?copy_from=<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>"
                   class="inline-flex items-center gap-1 bg-green-50 hover:bg-green-100 text-green-700
                          text-sm font-medium px-4 py-2 rounded-lg border border-green-200 transition
                          focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    <?= htmlspecialchars($t('cron_copy'), ENT_QUOTES, 'UTF-8') ?>
                </a>
                <form method="POST"
                      action="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>/delete"
                      onsubmit="return confirm('<?= htmlspecialchars($t('cron_delete_confirm'), ENT_QUOTES, 'UTF-8') ?>')">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit"
                            class="inline-flex items-center gap-1 bg-red-50 hover:bg-red-100 text-red-700
                                   text-sm font-medium px-4 py-2 rounded-lg border border-red-200 transition
                                   focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                        <?= htmlspecialchars($t('cron_delete'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>
                <?php if (count($jobTargets) <= 1): ?>
                    <!-- Single target: simple confirm + submit -->
                    <form method="POST"
                          action="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>/execute"
                          onsubmit="return confirm('<?= htmlspecialchars($t('cron_run_confirm'), ENT_QUOTES, 'UTF-8') ?>')">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit"
                                class="inline-flex items-center gap-1 bg-yellow-50 hover:bg-yellow-100 text-yellow-700
                                       text-sm font-medium px-4 py-2 rounded-lg border border-yellow-200 transition
                                       focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                            <?= htmlspecialchars($t('cron_run_now'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </form>
                <?php else: ?>
                    <!-- Multiple targets: open target-selection modal -->
                    <button type="button"
                            onclick="document.getElementById('run-now-modal').classList.remove('hidden')"
                            class="inline-flex items-center gap-1 bg-yellow-50 hover:bg-yellow-100 text-yellow-700
                                   text-sm font-medium px-4 py-2 rounded-lg border border-yellow-200 transition
                                   focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                        <?= htmlspecialchars($t('cron_run_now'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php endif; ?>
        <?php endif; ?>
        </div>
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
            <?php if ($scheduleHuman !== '' && $scheduleHuman !== $sched): ?>
            <dd class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                <?= htmlspecialchars($scheduleHuman, ENT_QUOTES, 'UTF-8') ?>
            </dd>
            <?php endif; ?>
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

        <!-- Notify on failure / limit exceeded -->
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_notify_on_failure'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm text-gray-600">
                <?= $notify ? '✓' : '—' ?>
            </dd>
        </div>

        <!-- Execution limit -->
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_execution_limit'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm text-gray-600 dark:text-gray-300">
                <?php if ($limitSeconds !== null): ?>
                    <?= htmlspecialchars((string) $limitSeconds, ENT_QUOTES, 'UTF-8') ?>
                    <?= htmlspecialchars($t('cron_execution_limit_seconds'), ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($autoKill): ?>
                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300">
                            <?= htmlspecialchars($t('cron_auto_kill'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                <?php else: ?>
                    —
                <?php endif; ?>
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
                        <?php if ($isAdmin): ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('actions'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($history as $idx => $entry): ?>
                        <?php
                            $startedAt    = (string) ($entry['started_at']  ?? '');
                            $finishedAt   = (string) ($entry['finished_at'] ?? '');
                            $exitCode     = isset($entry['exit_code']) ? $entry['exit_code'] : null;
                            $executionId  = (string) ($entry['execution_id'] ?? '');
                            $isRunning    = $exitCode === null && $finishedAt === '';
                            $duration     = isset($entry['duration_seconds'])
                                ? round((float) $entry['duration_seconds'], 1) . 's'
                                : '–';
                            $entryTarget  = (string) ($entry['target'] ?? '');
                            $output       = (string) ($entry['output'] ?? '');
                            $outputId     = 'hist-output-' . $idx;
                            $outputTrunc  = mb_strlen($output) > 200
                                ? mb_substr($output, 0, 200) . '…'
                                : $output;

                            // Exit code badge
                            if ($isRunning) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">'
                                    . htmlspecialchars($t('status_running'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
                            } elseif ($exitCode !== null && (int) $exitCode === 0) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">0</span>';
                            } elseif ($exitCode !== null && (int) $exitCode === -2) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800">'
                                    . htmlspecialchars($t('cron_kill_running'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
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
                                <?= htmlspecialchars($finishedAt !== '' ? $finishedAt : '—', ENT_QUOTES, 'UTF-8') ?>
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
                            <?php if ($isAdmin): ?>
                            <td class="px-4 py-3 text-sm whitespace-nowrap">
                                <?php if ($isRunning && $executionId !== ''): ?>
                                    <form method="POST"
                                          action="/execution/<?= htmlspecialchars(rawurlencode($executionId), ENT_QUOTES, 'UTF-8') ?>/kill"
                                          onsubmit="return confirm('<?= htmlspecialchars($t('cron_kill_confirm'), ENT_QUOTES, 'UTF-8') ?>')">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="_return" value="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit"
                                                class="inline-flex items-center gap-1 px-3 py-1 rounded text-xs font-medium
                                                       bg-red-50 hover:bg-red-100 text-red-700 border border-red-200
                                                       transition focus:outline-none focus:ring-2 focus:ring-red-400">
                                            <?= htmlspecialchars($t('cron_kill_running'), ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($isAdmin && count($jobTargets) > 1): ?>
<!-- ======================================================================
     Run Now – target-selection modal (multi-target jobs only)
     ====================================================================== -->
<div id="run-now-modal"
     class="hidden fixed inset-0 z-50 flex items-center justify-center p-4"
     role="dialog" aria-modal="true"
     aria-labelledby="run-now-modal-title">

    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/40 dark:bg-black/60"
         onclick="document.getElementById('run-now-modal').classList.add('hidden')"></div>

    <!-- Dialog card -->
    <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700
                w-full max-w-sm p-6">

        <h2 id="run-now-modal-title"
            class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-1">
            <?= htmlspecialchars($t('cron_run_now'), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
            <?= htmlspecialchars($t('cron_run_select_targets'), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <form id="run-now-form"
              method="POST"
              action="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>/execute">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <!-- Target checkboxes (all checked by default) -->
            <div class="space-y-2 mb-5">
                <?php foreach ($jobTargets as $tgt): ?>
                    <?php $tgt = (string) $tgt; ?>
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox"
                               name="targets[]"
                               value="<?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>"
                               checked
                               class="w-4 h-4 rounded border-gray-300 text-yellow-500 focus:ring-yellow-400">
                        <span class="text-sm text-gray-800 dark:text-gray-200 font-mono">
                            <?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button"
                        onclick="document.getElementById('run-now-modal').classList.add('hidden')"
                        class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300
                               bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600
                               rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition
                               focus:outline-none focus:ring-2 focus:ring-gray-400">
                    <?= htmlspecialchars($t('cancel'), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit"
                        onclick="return validateRunNowTargets()"
                        class="px-4 py-2 text-sm font-medium text-yellow-800 bg-yellow-50
                               hover:bg-yellow-100 border border-yellow-200 rounded-lg transition
                               focus:outline-none focus:ring-2 focus:ring-yellow-400">
                    <?= htmlspecialchars($t('cron_run_now'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

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

/**
 * Prevent submitting the Run Now modal form when no target is selected.
 *
 * @returns {boolean} false when no checkbox is checked (blocks submission).
 */
function validateRunNowTargets() {
    const form = document.getElementById('run-now-form');
    if (!form) return true;
    const checked = form.querySelectorAll('input[name="targets[]"]:checked');
    if (checked.length === 0) {
        alert('<?= addslashes($t('cron_run_select_at_least_one')) ?>');
        return false;
    }
    return true;
}

// Close modal on Escape key
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('run-now-modal');
        if (modal) modal.classList.add('hidden');
    }
});
</script>
