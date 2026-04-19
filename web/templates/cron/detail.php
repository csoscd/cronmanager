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
$autoKill          = !empty($job['auto_kill_on_limit']);
$singleton         = !empty($job['singleton']);
$runInMaintenance  = !empty($job['run_in_maintenance']);
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

        <!-- Singleton -->
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_singleton'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm text-gray-600 dark:text-gray-300">
                <?php if ($singleton): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">
                        <?= htmlspecialchars($t('cron_singleton'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php else: ?>
                    —
                <?php endif; ?>
            </dd>
        </div>

        <!-- Run in maintenance window -->
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_run_in_maintenance'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm text-gray-600 dark:text-gray-300">
                <?php if ($runInMaintenance): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300">
                        <?= htmlspecialchars($t('cron_run_in_maintenance'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php else: ?>
                    —
                <?php endif; ?>
            </dd>
        </div>

        <!-- Log retention -->
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_retention_days'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm text-gray-600 dark:text-gray-300">
                <?php $retentionDays = isset($job['retention_days']) && $job['retention_days'] !== null ? (int) $job['retention_days'] : null; ?>
                <?= $retentionDays !== null
                    ? htmlspecialchars($retentionDays . ' ' . $t('cron_retention_days_unit'), ENT_QUOTES, 'UTF-8')
                    : '—' ?>
            </dd>
        </div>

        <!-- Auto-retry -->
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                <?= htmlspecialchars($t('cron_retry'), ENT_QUOTES, 'UTF-8') ?>
            </dt>
            <dd class="text-sm text-gray-600 dark:text-gray-300">
                <?php $retryCount = (int) ($job['retry_count'] ?? 0); $retryDelay = (int) ($job['retry_delay_minutes'] ?? 1); ?>
                <?php if ($retryCount > 0): ?>
                    <?= htmlspecialchars($retryCount . '× / ' . $retryDelay . ' ' . $t('cron_retry_delay_unit'), ENT_QUOTES, 'UTF-8') ?>
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
                    <span class="inline-flex items-center gap-0.5">
                        <?php if ($tgt === 'local'): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                <?= htmlspecialchars($t('cron_local_badge'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 font-mono">
                                <?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!$runInMaintenance && $active): ?>
                            <span class="js-maint-badge hidden inline-flex items-center px-1 py-0.5 rounded text-xs font-medium
                                         bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 cursor-help"
                                  data-maint-schedule="<?= htmlspecialchars($sched, ENT_QUOTES, 'UTF-8') ?>"
                                  data-maint-target="<?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>"
                                  data-title-all="<?= htmlspecialchars($t('targets_conflict_badge_all'), ENT_QUOTES, 'UTF-8') ?>"
                                  title="<?= htmlspecialchars($t('targets_conflict_warning'), ENT_QUOTES, 'UTF-8') ?>">⚠</span>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </dd>
        </div>

        <?php if (!$runInMaintenance && $active): ?>
        <!-- Maintenance conflict severity banner (populated by JS) -->
        <div id="detail-maint-conflict" class="hidden col-span-full mt-1">
            <div id="detail-maint-some"
                 class="hidden flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm
                        text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <span><?= htmlspecialchars($t('targets_conflict_warning_some'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div id="detail-maint-all"
                 class="hidden flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm
                        text-red-800 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span><?= htmlspecialchars($t('targets_conflict_warning_all'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <?php endif; ?>

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
                            $entryTarget       = (string) ($entry['target'] ?? '');
                            $output            = (string) ($entry['output'] ?? '');
                            $outputId          = 'hist-output-' . $idx;
                            $outputTrunc       = mb_strlen($output) > 200
                                ? mb_substr($output, 0, 200) . '…'
                                : $output;
                            $duringMaintenance = !empty($entry['during_maintenance']);
                            $retryAttempt = (int) ($entry['retry_attempt'] ?? 0);
                            $retryTotal   = (int) ($job['retry_count'] ?? 0);

                            // Exit code badge
                            if ($isRunning) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">'
                                    . htmlspecialchars($t('status_running'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
                            } elseif ($exitCode !== null && (int) $exitCode === -5) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400">'
                                    . htmlspecialchars($t('cron_interrupted_badge'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
                            } elseif ($exitCode !== null && (int) $exitCode === -4) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400">'
                                    . htmlspecialchars($t('cron_maintenance_skipped_badge'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
                            } elseif ($exitCode !== null && (int) $exitCode === 0) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">0</span>';
                                if ($duringMaintenance) {
                                    $exitBadge .= ' <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">'
                                        . htmlspecialchars($t('cron_during_maintenance_badge'), ENT_QUOTES, 'UTF-8')
                                        . '</span>';
                                }
                            } elseif ($exitCode !== null && (int) $exitCode === -2) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800">'
                                    . htmlspecialchars($t('cron_kill_running'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
                            } else {
                                $safeCode  = htmlspecialchars((string) $exitCode, ENT_QUOTES, 'UTF-8');
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">' . $safeCode . '</span>';
                                if ($duringMaintenance) {
                                    $exitBadge .= ' <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">'
                                        . htmlspecialchars($t('cron_during_maintenance_badge'), ENT_QUOTES, 'UTF-8')
                                        . '</span>';
                                }
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
                                <?php if ($retryAttempt > 0): ?>
                                    <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                        <?= htmlspecialchars($t('cron_retry_badge', ['attempt' => $retryAttempt, 'total' => $retryTotal]), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 max-w-sm">
                                <?php if ($output !== ''): ?>
                                    <!-- Full output stored for copy/download (hidden) -->
                                    <span id="<?= htmlspecialchars($outputId . '-data', ENT_QUOTES, 'UTF-8') ?>"
                                          class="hidden"><?= htmlspecialchars($output, ENT_QUOTES, 'UTF-8') ?></span>
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
                                    <!-- Copy / Download buttons -->
                                    <div class="mt-1 flex items-center gap-1">
                                        <button type="button"
                                                onclick="copyOutput('<?= htmlspecialchars($outputId, ENT_QUOTES, 'UTF-8') ?>', this)"
                                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium
                                                       bg-gray-100 hover:bg-gray-200 text-gray-600 dark:bg-gray-700
                                                       dark:hover:bg-gray-600 dark:text-gray-300 border border-gray-200
                                                       dark:border-gray-600 transition focus:outline-none focus:ring-1 focus:ring-gray-400"
                                                title="<?= htmlspecialchars($t('output_copy_title'), ENT_QUOTES, 'UTF-8') ?>">
                                            <svg class="w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                            </svg>
                                            <span><?= htmlspecialchars($t('output_copy'), ENT_QUOTES, 'UTF-8') ?></span>
                                        </button>
                                        <button type="button"
                                                onclick="downloadOutput('<?= htmlspecialchars($outputId, ENT_QUOTES, 'UTF-8') ?>', <?= (int) $jobId ?>, '<?= htmlspecialchars(addslashes($startedAt), ENT_QUOTES, 'UTF-8') ?>')"
                                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium
                                                       bg-gray-100 hover:bg-gray-200 text-gray-600 dark:bg-gray-700
                                                       dark:hover:bg-gray-600 dark:text-gray-300 border border-gray-200
                                                       dark:border-gray-600 transition focus:outline-none focus:ring-1 focus:ring-gray-400"
                                                title="<?= htmlspecialchars($t('output_download_title'), ENT_QUOTES, 'UTF-8') ?>">
                                            <svg class="w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                            </svg>
                                            <span><?= htmlspecialchars($t('output_download'), ENT_QUOTES, 'UTF-8') ?></span>
                                        </button>
                                    </div>
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
 * Copy the full output of a history entry to the clipboard.
 *
 * @param {string}      id  Base element id.
 * @param {HTMLElement} btn The button element (for visual feedback).
 */
function copyOutput(id, btn) {
    const dataEl = document.getElementById(id + '-data');
    if (!dataEl) return;

    const text = dataEl.textContent;
    const span = btn.querySelector('span');

    const setFeedback = function (label, ok) {
        if (span) span.textContent = label;
        btn.classList.toggle('text-green-600', ok);
        btn.classList.toggle('border-green-300', ok);
    };

    const reset = function () {
        setTimeout(function () { setFeedback('<?= addslashes($t('output_copy')) ?>', false); }, 2000);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
            setFeedback('<?= addslashes($t('output_copied')) ?>', true);
            reset();
        }).catch(function () { fallbackCopy(text); });
    } else {
        fallbackCopy(text);
    }

    function fallbackCopy(t) {
        const ta = document.createElement('textarea');
        ta.value = t;
        ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* ignore */ }
        document.body.removeChild(ta);
    }
}

/**
 * Download the full output of a history entry as a plain-text log file.
 *
 * @param {string} id        Base element id.
 * @param {number} jobId     Cron job ID (used in filename).
 * @param {string} startedAt Execution start timestamp (used in filename).
 */
function downloadOutput(id, jobId, startedAt) {
    const dataEl = document.getElementById(id + '-data');
    if (!dataEl) return;

    const blob = new Blob([dataEl.textContent], { type: 'text/plain;charset=utf-8' });
    const url  = URL.createObjectURL(blob);

    const safeDateStr = String(startedAt).replace(' ', '_').replace(/[^0-9_T:-]/g, '').replace(/:/g, '-').slice(0, 19);
    const filename = 'cronmanager-job' + jobId + '-' + safeDateStr + '.log';

    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.style.cssText = 'display:none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

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

/**
 * Async maintenance-conflict severity check for the detail page.
 * Fetches conflict data per target, shows per-target badge icons and the
 * severity banner (amber = some runs skip, red = all/most runs skip).
 */
(function () {
    const LOOK_AHEAD    = 50;
    const RED_THRESHOLD = 0.9;

    const badges = Array.from(document.querySelectorAll('.js-maint-badge'));
    if (badges.length === 0) return;

    const bannerWrap = document.getElementById('detail-maint-conflict');
    const bannerSome = document.getElementById('detail-maint-some');
    const bannerAll  = document.getElementById('detail-maint-all');

    let anyConflict = false;
    let anyRed      = false;
    let pending      = badges.length;

    function updateBanner() {
        if (pending > 0) return;
        if (!bannerWrap) return;
        if (!anyConflict) return;
        bannerWrap.classList.remove('hidden');
        if (anyRed) {
            if (bannerAll)  bannerAll.classList.remove('hidden');
            if (bannerSome) bannerSome.classList.add('hidden');
        } else {
            if (bannerSome) bannerSome.classList.remove('hidden');
            if (bannerAll)  bannerAll.classList.add('hidden');
        }
    }

    badges.forEach(function (el) {
        fetch(
            '/maintenance/windows/conflict?' +
            new URLSearchParams({
                schedule:    el.dataset.maintSchedule,
                target:      el.dataset.maintTarget,
                look_ahead:  LOOK_AHEAD,
            }),
            { credentials: 'same-origin' }
        )
        .then(function (res) { return res.ok ? res.json() : null; })
        .catch(function () { return null; })
        .then(function (data) {
            pending--;
            if (data && data.conflicts && data.conflicts.length > 0) {
                el.classList.remove('hidden');
                anyConflict = true;
                const ratio = data.conflicts.length / LOOK_AHEAD;
                if (ratio >= RED_THRESHOLD) {
                    anyRed = true;
                    el.classList.remove('bg-amber-100', 'text-amber-700', 'dark:bg-amber-900/40', 'dark:text-amber-300');
                    el.classList.add('bg-red-100', 'text-red-700', 'dark:bg-red-900/40', 'dark:text-red-300');
                    el.textContent = '✕';
                    if (el.dataset.titleAll) el.title = el.dataset.titleAll;
                }
            } else {
                pending = Math.max(0, pending);
            }
            updateBanner();
        });
    });
})();
</script>
