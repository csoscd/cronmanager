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

$agentId  = isset($agentId) ? (int) $agentId : 0;
$agSuffix = $agentId > 0 ? '?agent_id=' . $agentId : '';
$agParam  = $agentId > 0 ? 'agent_id=' . $agentId . '&amp;' : '';

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

// ── Feature toggles ──────────────────────────────────────────────────────────
// Set to false to instantly hide a feature without touching the controller.
$showOutputPreview = true;   // Ausgabe-Vorschau in der Fehler-Tabelle

$jobs               = isset($jobs)           && is_array($jobs)           ? $jobs           : [];
$recentFailures     = isset($recentFailures) && is_array($recentFailures) ? $recentFailures : [];
$tags               = isset($tags)           && is_array($tags)           ? $tags           : [];
$stats              = isset($stats)          && is_array($stats)          ? $stats          : [];
$executionStats     = isset($executionStats) && is_array($executionStats) ? $executionStats : [];
$showExecutionStats = isset($showExecutionStats) ? (bool) $showExecutionStats : false;

$total         = (int) ($stats['total']         ?? 0);
$active        = (int) ($stats['active']        ?? 0);
$inactive      = (int) ($stats['inactive']      ?? 0);
$tagsCount     = (int) ($stats['tagsCount']     ?? 0);
$failedLast24h = (int) ($stats['failedLast24h'] ?? 0);
$byUser        = (array) ($stats['byUser']      ?? []);
$multiUser     = isset($multiUser) ? (bool) $multiUser : true;
$isOperator    = isset($isOperator) ? (bool) $isOperator : false;
?>

<!-- ======================================================================
     Page header
     ====================================================================== -->
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
        <?= htmlspecialchars($t('dashboard_title'), ENT_QUOTES, 'UTF-8') ?>
    </h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        <?php
            // Build a locale-aware date string using translated day/month names.
            // PHP's date('N') returns 1 (Mon) … 7 (Sun); date('n') returns 1–12.
            $dayKeys  = ['day_monday','day_tuesday','day_wednesday','day_thursday',
                         'day_friday','day_saturday','day_sunday'];
            $dayName  = $translator->t($dayKeys[(int) date('N') - 1]);
            $monthName = $translator->t('month_' . date('n'));
            $dayNum   = (int) date('j');
            $year     = date('Y');
            $lang     = $translator->getLang();
            $dateStr  = $lang === 'de'
                ? "{$dayName}, {$dayNum}. {$monthName} {$year}"
                : "{$dayName}, {$monthName} {$dayNum}, {$year}";
        ?>
        <?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?>
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
            <p id="cm-dash-total" class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $total ?></p>
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
            <p id="cm-dash-active" class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $active ?></p>
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
            <p id="cm-dash-inactive" class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $inactive ?></p>
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
            <p id="cm-dash-tags" class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $tagsCount ?></p>
        </div>
    </div>

</div>

<!-- ======================================================================
     Second row: Recent Failures (3/4) + Execution Stats widget (1/4)
     ====================================================================== -->
<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

    <!-- Recent failures (spans 3 of 4 columns) ---------------------------- -->
    <div class="lg:col-span-3 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                <?= htmlspecialchars($t('dashboard_recent_failures'), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <span id="cm-dash-fail-badge" class="<?= $failedLast24h > 0 ? '' : 'hidden' ?> inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                <span id="cm-dash-fail-count"><?= $failedLast24h ?></span>&nbsp;<?= htmlspecialchars($t('filter_status_failed'), ENT_QUOTES, 'UTF-8') ?> (24h)
            </span>
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
                            <?php if ($multiUser): ?>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <?= htmlspecialchars($t('cron_linux_user'), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <?php endif; ?>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <?= htmlspecialchars($t('cron_targets'), ENT_QUOTES, 'UTF-8') ?>
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
                            <?php if ($showOutputPreview): ?>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <?= htmlspecialchars($t('dashboard_output_preview'), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <?php endif; ?>
                            <?php if ($isOperator): ?>
                            <th class="px-4 py-3"></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="cm-dash-fail-tbody" class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php foreach ($recentFailures as $entry): ?>
                            <?php
                                $jobId       = (string) ($entry['job_id']      ?? '');
                                $executionId = (string) ($entry['execution_id'] ?? '');
                                $descRaw     = (string) ($entry['job_description'] ?? $entry['description'] ?? '');
                                $desc        = $descRaw !== '' ? $descRaw : "Job #{$jobId}";
                                $user        = (string) ($entry['linux_user'] ?? '');
                                $exitCode    = isset($entry['exit_code']) ? (int) $entry['exit_code'] : null;
                                $startedAt   = (string) ($entry['started_at']  ?? '');
                                $entryTarget = (string) ($entry['target'] ?? '');
                                $duration    = isset($entry['duration_seconds'])
                                    ? round((float) $entry['duration_seconds'], 1) . 's'
                                    : '–';
                                // Truncate output to last 120 chars for the preview column
                                $outputRaw     = isset($entry['output']) ? trim((string) $entry['output']) : '';
                                $outputPreview = '';
                                if ($outputRaw !== '') {
                                    $outputPreview = mb_strlen($outputRaw) > 120
                                        ? '…' . mb_substr($outputRaw, -120)
                                        : $outputRaw;
                                }
                                // Deep-link to Timeline pre-filtered for this specific job/target/status.
                                // _direct=1 prevents saved date-range cookies from hiding the entry.
                                $timelineParams = array_filter([
                                    'agent_id' => $agentId > 0 ? (string) $agentId : '',
                                    'job_id'   => $jobId,
                                    'target'   => $entryTarget,
                                    'status'   => 'failed',
                                    '_direct'  => '1',
                                ], static fn(string $v): bool => $v !== '');
                                $timelineUrl = '/timeline?' . http_build_query($timelineParams);
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-3 text-sm">
                                    <?php if ($jobId !== ''): ?>
                                        <a href="<?= htmlspecialchars($timelineUrl, ENT_QUOTES, 'UTF-8') ?>"
                                           class="text-blue-600 hover:underline font-medium">
                                            <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </td>
                                <?php if ($multiUser): ?>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    <?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <?php endif; ?>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    <?php if ($entryTarget !== ''): ?>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                            <?= htmlspecialchars($entryTarget, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                        <?= $exitCode !== null ? $exitCode : '?' ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                    <?= htmlspecialchars($startedAt, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                    <?= htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <?php if ($showOutputPreview): ?>
                                <td class="px-4 py-3 text-xs font-mono text-gray-500 dark:text-gray-400 max-w-xs">
                                    <?php if ($outputPreview !== ''): ?>
                                        <span class="block whitespace-pre-wrap break-words" title="<?= htmlspecialchars($outputRaw, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($outputPreview, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <?php if ($isOperator && $executionId !== ''): ?>
                                <td class="px-4 py-3 text-sm whitespace-nowrap">
                                    <button type="button"
                                            data-ack-id="<?= htmlspecialchars($executionId, ENT_QUOTES, 'UTF-8') ?>"
                                            class="inline-flex items-center gap-1 px-3 py-1 rounded text-xs font-medium
                                                   bg-gray-50 hover:bg-gray-100 text-gray-600 dark:bg-gray-700 dark:hover:bg-gray-600
                                                   dark:text-gray-300 border border-gray-200 dark:border-gray-600
                                                   transition focus:outline-none focus:ring-2 focus:ring-gray-400">
                                        <?= htmlspecialchars($t('execution_acknowledge'), ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                </td>
                                <?php elseif ($isOperator): ?>
                                <td class="px-4 py-3"></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Execution stats widget (1 of 4 columns, under 4th tile) ----------- -->
    <?php if ($showExecutionStats): ?>
    <div class="lg:col-span-1 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                <?= htmlspecialchars($t('dashboard_exec_stats'), ENT_QUOTES, 'UTF-8') ?>
            </h2>
        </div>
        <div class="px-5 py-4 space-y-4">
            <?php
                $execToday    = (int) ($executionStats['executed_today'] ?? 0);
                $failToday    = (int) ($executionStats['failed_today']   ?? 0);
                $exec24h      = (int) ($executionStats['executed_24h']   ?? 0);
                $fail24h      = (int) ($executionStats['failed_24h']     ?? 0);
            ?>
            <!-- Today -->
            <div>
                <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2">
                    <?= htmlspecialchars($t('dashboard_exec_today'), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        <?= htmlspecialchars($t('dashboard_exec_executed'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?= $execToday ?></span>
                </div>
                <div class="flex items-center justify-between mt-1">
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        <?= htmlspecialchars($t('dashboard_exec_failed'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="text-sm font-semibold <?= $failToday > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100' ?>">
                        <?= $failToday ?>
                    </span>
                </div>
            </div>
            <hr class="border-gray-100 dark:border-gray-700">
            <!-- Last 24 h -->
            <div>
                <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2">
                    <?= htmlspecialchars($t('dashboard_exec_last_24h'), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        <?= htmlspecialchars($t('dashboard_exec_executed'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?= $exec24h ?></span>
                </div>
                <div class="flex items-center justify-between mt-1">
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        <?= htmlspecialchars($t('dashboard_exec_failed'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="text-sm font-semibold <?= $fail24h > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100' ?>">
                        <?= $fail24h ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ======================================================================
     Third row: Jobs by User (multi-user mode only)
     ====================================================================== -->
<?php if ($multiUser): ?>
<div class="mt-6">
    <div id="cm-dash-by-user" class="max-w-sm bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
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
                            arsort($byUser);
                            foreach ($byUser as $username => $count):
                        ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-6 py-3 text-sm font-medium text-gray-800 dark:text-gray-200">
                                    <a href="/crons?<?= $agParam ?>user=<?= htmlspecialchars(rawurlencode((string) $username), ENT_QUOTES, 'UTF-8') ?>"
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
<?php endif; ?>

<script>
(function () {
    'use strict';

    function set(id, text) {
        var el = document.getElementById(id);
        if (el) { el.textContent = String(text); }
    }

    function refresh() {
        cmFetch('/dashboard?_json=1')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var s = data.stats || {};
                set('cm-dash-total',   s.total    || 0);
                set('cm-dash-active',  s.active   || 0);
                set('cm-dash-inactive',s.inactive || 0);
                set('cm-dash-tags',    s.tagsCount || 0);

                var badge     = document.getElementById('cm-dash-fail-badge');
                var failCount = document.getElementById('cm-dash-fail-count');
                var n         = parseInt(s.failedLast24h || 0, 10);
                if (badge) { badge.classList.toggle('hidden', n === 0); }
                if (failCount) { failCount.textContent = String(n); }
            })
            .catch(function () {
                /* silent — stale data is acceptable on transient errors */
            });
    }

    cmPoll(refresh, 60000);
}());
</script>

<?php if ($isOperator): ?>
<script>
// AJAX acknowledge via event delegation on the recent-failures tbody.
// On success the row is removed immediately; badge counter is decremented.
(function () {
    'use strict';
    var CSRF  = <?= json_encode($csrf_token ?? '') ?>;
    var tbody = document.getElementById('cm-dash-fail-tbody');
    if (!tbody) { return; }

    tbody.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ack-id]');
        if (!btn || btn.disabled) { return; }

        var id = btn.dataset.ackId;
        if (!id) { return; }

        btn.disabled = true;

        var url  = '/execution/' + encodeURIComponent(id) + '/acknowledge?_json=1';
        var body = new URLSearchParams({ _csrf: CSRF });

        fetch(url, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:        body.toString(),
            credentials: 'same-origin',
        })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (data) {
            if (!data.success) { btn.disabled = false; return; }

            // Remove the row from the DOM.
            var row = btn.closest('tr');
            if (row) { row.remove(); }

            // Decrement the 24h-failure badge.
            var countEl = document.getElementById('cm-dash-fail-count');
            var badge   = document.getElementById('cm-dash-fail-badge');
            if (countEl) {
                var n = Math.max(0, parseInt(countEl.textContent, 10) - 1);
                countEl.textContent = String(n);
                if (badge) { badge.classList.toggle('hidden', n === 0); }
            }

            // If the tbody is now empty, show the "no results" message.
            if (tbody.querySelectorAll('tr').length === 0) {
                var wrap = tbody.closest('.overflow-x-auto');
                if (wrap) {
                    wrap.innerHTML = '<div class="px-6 py-8 text-center text-gray-400 dark:text-gray-500 text-sm">'
                        + <?= json_encode($t('no_results')) ?> + '</div>';
                }
            }
        })
        .catch(function () {
            btn.disabled = false;
        });
    });
}());
</script>
<?php endif; ?>
