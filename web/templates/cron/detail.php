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

/** @var int $agentId */
$agentId  = isset($agentId) ? (int) $agentId : 0;
$agSuffix = $agentId > 0 ? '?agent_id=' . $agentId : '';
$agParam  = $agentId > 0 ? 'agent_id=' . $agentId . '&amp;' : '';

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
    <a href="/crons<?= $agSuffix ?>" class="inline-flex items-center text-sm text-blue-600 hover:underline">
        &larr; <?= htmlspecialchars($t('crons_title'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<?php
// Flash messages from kill and acknowledge actions
$killNoticeKey = \Cronmanager\Web\Session\SessionManager::flash('_flash_kill_notice');
$killErrorKey  = \Cronmanager\Web\Session\SessionManager::flash('_flash_kill_error');
$ackNoticeKey  = \Cronmanager\Web\Session\SessionManager::flash('_flash_ack_notice');
$ackErrorKey   = \Cronmanager\Web\Session\SessionManager::flash('_flash_ack_error');
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
<?php if ($ackNoticeKey !== null): ?>
<div class="mb-4 flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
    </svg>
    <?= htmlspecialchars($t($ackNoticeKey), ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>
<?php if ($ackErrorKey !== null): ?>
<div class="mb-4 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <?= htmlspecialchars($t($ackErrorKey), ENT_QUOTES, 'UTF-8') ?>
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
                <a href="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>/monitor<?= $agSuffix ?>"
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
                <a href="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>/edit<?= $agSuffix ?>"
                   class="inline-flex items-center gap-1 bg-blue-600 hover:bg-blue-700 text-white
                          text-sm font-medium px-4 py-2 rounded-lg transition focus:outline-none
                          focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <?= htmlspecialchars($t('cron_edit'), ENT_QUOTES, 'UTF-8') ?>
                </a>
                <a href="/crons/new?<?= $agParam ?>copy_from=<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>"
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
                <?php
                    $retryCount        = (int) ($job['retry_count']          ?? 0);
                    $retryDelay        = (int) ($job['retry_delay_minutes']  ?? 1);
                    $restartExitcodes  = isset($job['restart_on_exitcodes']) && $job['restart_on_exitcodes'] !== null
                        ? (string) $job['restart_on_exitcodes']
                        : null;
                ?>
                <?php if ($retryCount > 0): ?>
                    <?= htmlspecialchars($retryCount . '× / ' . $retryDelay . ' ' . $t('cron_retry_delay_unit'), ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($restartExitcodes !== null): ?>
                        <span class="ml-1 text-xs text-gray-400 dark:text-gray-500 font-mono">(<?= htmlspecialchars($restartExitcodes, ENT_QUOTES, 'UTF-8') ?>)</span>
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
    <?php
        // Detect whether any execution is still running so auto-refresh can be armed.
        $hasRunning = false;
        foreach ($history as $_e) {
            $ec = $_e['exit_code'] ?? null;
            $fa = (string) ($_e['finished_at'] ?? '');
            if ($ec === null && $fa === '') { $hasRunning = true; break; }
        }
    ?>
    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200">
            <?= htmlspecialchars($t('cron_history'), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <div class="flex items-center gap-1">
            <!-- Auto-reload toggle -->
            <button id="cm-reload-toggle"
                    type="button"
                    title="<?= htmlspecialchars($t('detail_auto_reload_toggle'), ENT_QUOTES, 'UTF-8') ?>"
                    class="inline-flex items-center justify-center w-7 h-7 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition focus:outline-none focus:ring-2 focus:ring-blue-400">
                <!-- Icon is swapped by JS; initial state reflects $hasRunning -->
                <?php if ($hasRunning): ?>
                <!-- Pause icon (auto-reload is ON by default when job runs) -->
                <svg id="cm-icon-pause" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5"/>
                </svg>
                <svg id="cm-icon-play" class="w-4 h-4 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z"/>
                </svg>
                <?php else: ?>
                <!-- Play icon (auto-reload is OFF by default when no job runs) -->
                <svg id="cm-icon-pause" class="w-4 h-4 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5"/>
                </svg>
                <svg id="cm-icon-play" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z"/>
                </svg>
                <?php endif; ?>
            </button>
            <!-- Manual reload -->
            <button id="cm-reload-manual"
                    type="button"
                    title="<?= htmlspecialchars($t('detail_manual_reload'), ENT_QUOTES, 'UTF-8') ?>"
                    class="inline-flex items-center justify-center w-7 h-7 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition focus:outline-none focus:ring-2 focus:ring-blue-400">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>
                </svg>
            </button>
        </div>
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
                <tbody id="cm-history-tbody" class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php include __DIR__ . '/_detail_history_rows.php'; ?>
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

<script>
(function () {
    var JOB_ID      = <?= (int) $jobId ?>;
    var AGENT_ID    = <?= $agentId ?>;
    var INTERVAL_MS = 10000;
    var STORE_KEY   = 'cm_autoreload_' + JOB_ID;

    // Default: ON when a job is currently running, OFF otherwise.
    var hasRunning  = <?= $hasRunning ? 'true' : 'false' ?>;
    var stored      = sessionStorage.getItem(STORE_KEY);
    var autoEnabled = stored !== null ? stored === 'true' : hasRunning;

    var timer       = null;
    var btnToggle   = document.getElementById('cm-reload-toggle');
    var btnManual   = document.getElementById('cm-reload-manual');
    var tbody       = document.getElementById('cm-history-tbody');
    var iconPause   = document.getElementById('cm-icon-pause');
    var iconPlay    = document.getElementById('cm-icon-play');

    function applyIcon(enabled) {
        if (!iconPause || !iconPlay) { return; }
        iconPause.classList.toggle('hidden', !enabled);
        iconPlay.classList.toggle('hidden', enabled);
    }

    function startPolling() {
        if (timer) { return; }
        timer = setInterval(doFetch, INTERVAL_MS);
    }

    function stopPolling() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    function setAuto(enabled) {
        autoEnabled = enabled;
        sessionStorage.setItem(STORE_KEY, enabled ? 'true' : 'false');
        applyIcon(enabled);
        if (enabled) { startPolling(); } else { stopPolling(); }
    }

    function doFetch() {
        var url = window.location.pathname + '?_json=1'
                + (AGENT_ID ? '&agent_id=' + AGENT_ID : '');
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) { throw new Error('http ' + r.status); }
                return r.json();
            })
            .then(function (data) {
                if (tbody && typeof data.rows_html === 'string') {
                    tbody.innerHTML = data.rows_html;
                }
                hasRunning = !!data.has_running;
                // Auto-stop once the job finishes (but respect a manual re-enable).
                if (!hasRunning && autoEnabled) {
                    setAuto(false);
                }
            })
            .catch(function () { /* network error – retry on next tick */ });
    }

    if (btnToggle) {
        btnToggle.addEventListener('click', function () { setAuto(!autoEnabled); });
    }
    if (btnManual) {
        btnManual.addEventListener('click', function () { doFetch(); });
    }

    // Apply initial icon and start polling if appropriate.
    applyIcon(autoEnabled);
    if (autoEnabled) { startPolling(); }

    // Allow external triggers (e.g. acknowledge AJAX) to reload the history rows.
    document.addEventListener('cm:reload-detail', doFetch);
}());
</script>

<script>
// AJAX acknowledge / unacknowledge via event delegation on the history tbody.
// Buttons in _detail_history_rows.php carry data-ack-action and data-ack-id.
(function () {
    'use strict';
    var CSRF  = <?= json_encode($csrf_token ?? '') ?>;
    var tbody = document.getElementById('cm-history-tbody');
    if (!tbody) { return; }

    tbody.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ack-action]');
        if (!btn || btn.disabled) { return; }

        var action = btn.dataset.ackAction;   // 'acknowledge' | 'unacknowledge'
        var id     = btn.dataset.ackId;
        if (!id) { return; }

        btn.disabled = true;

        var url  = '/execution/' + encodeURIComponent(id) + '/' + action + '?_json=1';
        var body = new URLSearchParams({ _csrf: CSRF });

        fetch(url, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:        body.toString(),
            credentials: 'same-origin',
        })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (data) {
            if (data.success) {
                document.dispatchEvent(new CustomEvent('cm:reload-detail'));
            } else {
                btn.disabled = false;
            }
        })
        .catch(function () {
            btn.disabled = false;
        });
    });
}());
</script>
