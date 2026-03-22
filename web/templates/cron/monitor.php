<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Job Monitor Template
 *
 * Displays performance statistics and execution history charts for a single
 * cron job.  Data comes from the agent's GET /crons/{id}/monitor endpoint.
 *
 * Variables available in this template:
 *   array   $job            – cron job record (id, description, schedule, …)
 *   array   $stats          – aggregated KPIs (success_rate, avg_duration, …)
 *   string  $durationSeries – JSON array [{started_at, duration_seconds, success}]
 *   string  $barBuckets     – JSON array [{label, success, failed}]
 *   array   $recent         – most recent execution records
 *   string  $period         – currently selected period (e.g. "30d")
 *   array   $validPeriods   – list of all valid period strings
 *   string  $fromStr        – ISO 8601 start of current window
 *   string  $toStr          – ISO 8601 end of current window
 *   array   $targets        – all configured targets for this job
 *   string|null $selectedTarget – currently active target filter, or null for all
 *   bool    $isAdmin        – whether the current user has admin role
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

// Ensure safe defaults
$job           = isset($job)          && is_array($job)          ? $job          : [];
$stats         = isset($stats)        && is_array($stats)        ? $stats        : [];
$recent        = isset($recent)       && is_array($recent)       ? $recent       : [];
$validPeriods  = isset($validPeriods) && is_array($validPeriods) ? $validPeriods : ['1h','6h','12h','24h','7d','30d','3m','6m','1y'];
$period        = isset($period)        ? (string) $period        : '30d';
$fromStr       = isset($fromStr)       ? (string) $fromStr       : '';
$toStr         = isset($toStr)         ? (string) $toStr         : '';
$durationSeries = isset($durationSeries) ? (string) $durationSeries : '[]';
$barBuckets    = isset($barBuckets)    ? (string) $barBuckets    : '[]';
$targets        = isset($targets)        && is_array($targets)        ? $targets        : [];
$selectedTarget = isset($selectedTarget) && is_string($selectedTarget) ? $selectedTarget : null;

$jobId   = (string) ($job['id']          ?? '');
$desc    = (string) ($job['description'] ?? "Job #{$jobId}");
$isAdmin = isset($isAdmin) && (bool) $isAdmin;

// Whether to show the target filter (only useful when the job has multiple targets)
$showTargetFilter = count($targets) > 1;

// Stats
$successRate  = $stats['success_rate']    ?? null;
$avgDuration  = $stats['avg_duration']    ?? null;
$minDuration  = $stats['min_duration']    ?? null;
$maxDuration  = $stats['max_duration']    ?? null;
$execCount    = (int) ($stats['execution_count'] ?? 0);
$alertCount   = (int) ($stats['alert_count']     ?? 0);
$successCount = (int) ($stats['success_count']   ?? 0);
$failureCount = (int) ($stats['failure_count']   ?? 0);

// Determine success rate colour
$rateClass = 'text-gray-700 dark:text-gray-300';
if ($successRate !== null) {
    if ((float) $successRate >= 95) {
        $rateClass = 'text-green-600 dark:text-green-400';
    } elseif ((float) $successRate >= 80) {
        $rateClass = 'text-yellow-600 dark:text-yellow-400';
    } else {
        $rateClass = 'text-red-600 dark:text-red-400';
    }
}
?>

<!-- Load Chart.js (self-hosted, required by CSP) -->
<script src="/assets/js/chart.min.js"></script>

<!-- ======================================================================
     Breadcrumb
     ====================================================================== -->
<div class="mb-4">
    <a href="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>"
       class="inline-flex items-center text-sm text-blue-600 hover:underline">
        &larr; <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<!-- ======================================================================
     Page header + period selector
     ====================================================================== -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

    <!-- Title + window -->
    <div>
        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">
            <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>
            <span class="text-sm font-normal text-gray-500 dark:text-gray-400 ml-1">
                — <?= htmlspecialchars($t('monitor_title'), ENT_QUOTES, 'UTF-8') ?>
            </span>
        </h1>
        <?php if ($fromStr !== '' && $toStr !== ''): ?>
            <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                <?= htmlspecialchars($fromStr, ENT_QUOTES, 'UTF-8') ?>
                &ndash;
                <?= htmlspecialchars($toStr, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
    </div>

    <!-- Period selector -->
    <div class="flex flex-wrap items-center gap-1">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 mr-1">
            <?= htmlspecialchars($t('monitor_period'), ENT_QUOTES, 'UTF-8') ?>:
        </span>
        <?php foreach ($validPeriods as $p): ?>
            <?php
                $isActive = $p === $period;
                // Preserve active target filter when switching periods
                $url = '/crons/' . rawurlencode($jobId) . '/monitor?period=' . rawurlencode($p);
                if ($selectedTarget !== null) {
                    $url .= '&target=' . rawurlencode($selectedTarget);
                }
                $btnClass = $isActive
                    ? 'px-3 py-1.5 text-xs font-semibold rounded-md bg-indigo-600 text-white shadow-sm cursor-default'
                    : 'px-3 py-1.5 text-xs font-medium rounded-md bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors';
            ?>
            <?php if ($isActive): ?>
                <span class="<?= $btnClass ?>">
                    <?= htmlspecialchars($t('monitor_period_' . $p), ENT_QUOTES, 'UTF-8') ?>
                </span>
            <?php else: ?>
                <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                   class="<?= $btnClass ?>">
                    <?= htmlspecialchars($t('monitor_period_' . $p), ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<!-- ======================================================================
     Target filter (only shown when the job has more than one target)
     ====================================================================== -->
<?php if ($showTargetFilter): ?>
<div class="mb-6 flex flex-wrap items-center gap-1">
    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 mr-1">
        <?= htmlspecialchars($t('monitor_target'), ENT_QUOTES, 'UTF-8') ?>:
    </span>

    <?php
        // "All targets" button
        $allActive   = $selectedTarget === null;
        $allUrl      = '/crons/' . rawurlencode($jobId) . '/monitor?period=' . rawurlencode($period);
        $allBtnClass = $allActive
            ? 'px-3 py-1.5 text-xs font-semibold rounded-md bg-indigo-600 text-white shadow-sm cursor-default'
            : 'px-3 py-1.5 text-xs font-medium rounded-md bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors';
    ?>
    <?php if ($allActive): ?>
        <span class="<?= $allBtnClass ?>">
            <?= htmlspecialchars($t('monitor_all_targets'), ENT_QUOTES, 'UTF-8') ?>
        </span>
    <?php else: ?>
        <a href="<?= htmlspecialchars($allUrl, ENT_QUOTES, 'UTF-8') ?>"
           class="<?= $allBtnClass ?>">
            <?= htmlspecialchars($t('monitor_all_targets'), ENT_QUOTES, 'UTF-8') ?>
        </a>
    <?php endif; ?>

    <?php foreach ($targets as $tgt): ?>
        <?php
            $tgtActive   = $selectedTarget === $tgt;
            $tgtUrl      = '/crons/' . rawurlencode($jobId) . '/monitor?period=' . rawurlencode($period) . '&target=' . rawurlencode($tgt);
            $tgtBtnClass = $tgtActive
                ? 'px-3 py-1.5 text-xs font-semibold rounded-md bg-indigo-600 text-white shadow-sm cursor-default font-mono'
                : 'px-3 py-1.5 text-xs font-medium rounded-md bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors font-mono';
        ?>
        <?php if ($tgtActive): ?>
            <span class="<?= $tgtBtnClass ?>">
                <?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>
            </span>
        <?php else: ?>
            <a href="<?= htmlspecialchars($tgtUrl, ENT_QUOTES, 'UTF-8') ?>"
               class="<?= $tgtBtnClass ?>">
                <?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ======================================================================
     KPI cards
     ====================================================================== -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

    <!-- Success Rate -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 px-5 py-4">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            <?= htmlspecialchars($t('monitor_success_rate'), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p class="text-3xl font-bold <?= $rateClass ?>">
            <?= $successRate !== null
                ? htmlspecialchars(number_format((float) $successRate, 1) . ' %', ENT_QUOTES, 'UTF-8')
                : '<span class="text-gray-300 dark:text-gray-600">—</span>' ?>
        </p>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
            <?= htmlspecialchars($successCount . ' / ' . $execCount, ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>

    <!-- Avg Duration -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 px-5 py-4">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            <?= htmlspecialchars($t('monitor_avg_duration'), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p class="text-3xl font-bold text-gray-700 dark:text-gray-300">
            <?php if ($avgDuration !== null): ?>
                <?= htmlspecialchars(number_format((float) $avgDuration, 1), ENT_QUOTES, 'UTF-8') ?>
                <span class="text-base font-normal text-gray-400"><?= htmlspecialchars($t('monitor_seconds'), ENT_QUOTES, 'UTF-8') ?></span>
            <?php else: ?>
                <span class="text-gray-300 dark:text-gray-600">—</span>
            <?php endif; ?>
        </p>
        <?php if ($minDuration !== null && $maxDuration !== null): ?>
            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                <?= htmlspecialchars($t('monitor_min'), ENT_QUOTES, 'UTF-8') ?>
                <?= htmlspecialchars((string) $minDuration, ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars($t('monitor_seconds'), ENT_QUOTES, 'UTF-8') ?>
                &nbsp;/&nbsp;
                <?= htmlspecialchars($t('monitor_max'), ENT_QUOTES, 'UTF-8') ?>
                <?= htmlspecialchars((string) $maxDuration, ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars($t('monitor_seconds'), ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
    </div>

    <!-- Executions -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 px-5 py-4">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            <?= htmlspecialchars($t('monitor_executions'), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p class="text-3xl font-bold text-gray-700 dark:text-gray-300">
            <?= htmlspecialchars(number_format($execCount), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
            <?php if ($failureCount > 0): ?>
                <span class="text-red-500"><?= htmlspecialchars((string) $failureCount, ENT_QUOTES, 'UTF-8') ?></span>
                <?= htmlspecialchars($t('monitor_failed_label'), ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
                <?= htmlspecialchars($t('monitor_success_label'), ENT_QUOTES, 'UTF-8') ?>
                <?= htmlspecialchars((string) $successCount, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </p>
    </div>

    <!-- Alerts -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 px-5 py-4">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            <?= htmlspecialchars($t('monitor_alerts'), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p class="text-3xl font-bold <?= $alertCount > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' ?>">
            <?= htmlspecialchars(number_format($alertCount), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
            <?php if (!(bool) ($job['notify_on_failure'] ?? false)): ?>
                <?= htmlspecialchars($t('monitor_notify_disabled'), ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
                <?= htmlspecialchars($t('monitor_notify_enabled'), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </p>
    </div>

</div>

<!-- ======================================================================
     Charts row
     ====================================================================== -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">

    <!-- Duration line chart -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                <?= htmlspecialchars($t('monitor_duration_chart'), ENT_QUOTES, 'UTF-8') ?>
            </h2>
        </div>
        <div class="p-4">
            <canvas id="durationChart" height="220"></canvas>
        </div>
    </div>

    <!-- Stacked activity bar chart -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                <?= htmlspecialchars($t('monitor_activity_chart'), ENT_QUOTES, 'UTF-8') ?>
            </h2>
        </div>
        <div class="p-4">
            <canvas id="activityChart" height="220"></canvas>
        </div>
    </div>

</div>

<!-- ======================================================================
     Recent executions table
     ====================================================================== -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
            <?= htmlspecialchars($t('monitor_recent_title'), ENT_QUOTES, 'UTF-8') ?>
        </h2>
    </div>

    <?php if (empty($recent)): ?>
        <div class="px-6 py-10 text-center text-gray-400 dark:text-gray-500 text-sm">
            <?= htmlspecialchars($t('monitor_no_data'), ENT_QUOTES, 'UTF-8') ?>
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
                            <?= htmlspecialchars($t('duration'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_host'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('exit_code'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($recent as $entry): ?>
                        <?php
                            $exitCode   = isset($entry['exit_code']) ? (int) $entry['exit_code'] : null;
                            $duration   = isset($entry['duration_seconds'])
                                ? number_format((float) $entry['duration_seconds'], 1) . ' ' . $t('monitor_seconds')
                                : '—';
                            $entryTarget = (string) ($entry['target'] ?? '');
                            $startedAt   = (string) ($entry['started_at'] ?? '');

                            $rowFailed = $exitCode !== null && $exitCode !== 0;

                            if ($exitCode === null) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">'
                                    . htmlspecialchars($t('status_running'), ENT_QUOTES, 'UTF-8') . '</span>';
                            } elseif ($exitCode === 0) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">0</span>';
                            } else {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">'
                                    . htmlspecialchars((string) $exitCode, ENT_QUOTES, 'UTF-8') . '</span>';
                            }
                        ?>
                        <tr class="<?= $rowFailed ? 'bg-red-50 dark:bg-red-950' : 'hover:bg-gray-50 dark:hover:bg-gray-700' ?>">
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap font-mono">
                                <?= htmlspecialchars($startedAt, ENT_QUOTES, 'UTF-8') ?>
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
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ======================================================================
     Chart.js initialisation
     ====================================================================== -->
<script>
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Data from PHP
    // -------------------------------------------------------------------------
    var DURATION_DATA = <?= $durationSeries ?>;
    var BAR_DATA      = <?= $barBuckets ?>;
    var AVG_DURATION  = <?= $avgDuration !== null ? json_encode((float) $avgDuration) : 'null' ?>;
    var LABEL_SUCCESS = <?= json_encode($t('monitor_success_label')) ?>;
    var LABEL_FAILED  = <?= json_encode($t('monitor_failed_label')) ?>;
    var LABEL_AVG     = <?= json_encode($t('monitor_avg_duration')) ?>;

    // -------------------------------------------------------------------------
    // Theme-aware colours
    // -------------------------------------------------------------------------
    var isDark     = document.documentElement.classList.contains('dark');
    var textColor  = isDark ? 'rgba(209,213,219,0.85)' : 'rgba(75,85,99,0.9)';
    var gridColor  = isDark ? 'rgba(75,85,99,0.35)'    : 'rgba(229,231,235,0.9)';
    var colorGreen = 'rgba(34,197,94,0.75)';
    var colorRed   = 'rgba(239,68,68,0.75)';
    var colorLine  = isDark ? 'rgba(129,140,248,0.7)'  : 'rgba(99,102,241,0.7)';
    var colorAvg   = 'rgba(249,115,22,0.8)';

    // -------------------------------------------------------------------------
    // Duration line chart
    // -------------------------------------------------------------------------
    var durationCtx = document.getElementById('durationChart');
    if (durationCtx && DURATION_DATA.length > 0) {
        // Build per-point colours based on success flag
        var pointColors = DURATION_DATA.map(function (d) {
            return d.success ? colorGreen : colorRed;
        });

        var datasets = [{
            label: '<?= addslashes($t('duration')) ?>',
            data: DURATION_DATA.map(function (d) { return d.duration_seconds; }),
            borderColor: colorLine,
            backgroundColor: isDark ? 'rgba(99,102,241,0.08)' : 'rgba(99,102,241,0.06)',
            pointBackgroundColor: pointColors,
            pointBorderColor: pointColors,
            pointRadius: DURATION_DATA.length > 100 ? 2 : 4,
            pointHoverRadius: 6,
            tension: 0.2,
            fill: true,
        }];

        // Add average line if we have a meaningful average
        if (AVG_DURATION !== null) {
            datasets.push({
                label: LABEL_AVG,
                data: DURATION_DATA.map(function () { return AVG_DURATION; }),
                borderColor: colorAvg,
                borderDash: [6, 4],
                borderWidth: 1.5,
                pointRadius: 0,
                fill: false,
                tension: 0,
            });
        }

        new Chart(durationCtx, {
            type: 'line',
            data: {
                labels: DURATION_DATA.map(function (d) {
                    // Display only the time portion for short labels
                    return d.started_at.replace('T', ' ').substring(0, 16);
                }),
                datasets: datasets,
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: { intersect: false, mode: 'index' },
                scales: {
                    x: {
                        ticks: {
                            maxTicksLimit: 10,
                            color: textColor,
                            maxRotation: 30,
                            font: { size: 10 },
                        },
                        grid: { color: gridColor },
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: '<?= addslashes($t('monitor_seconds')) ?>',
                            color: textColor,
                            font: { size: 11 },
                        },
                        ticks: { color: textColor, font: { size: 10 } },
                        grid: { color: gridColor },
                    },
                },
                plugins: {
                    legend: {
                        display: AVG_DURATION !== null,
                        labels: { color: textColor, boxWidth: 20, font: { size: 11 } },
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                if (ctx.datasetIndex === 0) {
                                    var d = DURATION_DATA[ctx.dataIndex];
                                    var status = d.success ? LABEL_SUCCESS : LABEL_FAILED;
                                    return status + ': ' + ctx.parsed.y + ' <?= addslashes($t('monitor_seconds')) ?>';
                                }
                                return ctx.dataset.label + ': ' + ctx.parsed.y + ' <?= addslashes($t('monitor_seconds')) ?>';
                            },
                        },
                    },
                },
            },
        });
    } else if (durationCtx) {
        // No data: show placeholder text
        var ctx2d = durationCtx.getContext('2d');
        durationCtx.height = 80;
        ctx2d.fillStyle = textColor;
        ctx2d.textAlign = 'center';
        ctx2d.font = '13px sans-serif';
        ctx2d.fillText('<?= addslashes($t('monitor_no_data')) ?>', durationCtx.width / 2, 50);
    }

    // -------------------------------------------------------------------------
    // Activity stacked bar chart
    // -------------------------------------------------------------------------
    var activityCtx = document.getElementById('activityChart');
    if (activityCtx && BAR_DATA.length > 0) {
        new Chart(activityCtx, {
            type: 'bar',
            data: {
                labels: BAR_DATA.map(function (b) { return b.label; }),
                datasets: [
                    {
                        label: LABEL_SUCCESS,
                        data: BAR_DATA.map(function (b) { return b.success; }),
                        backgroundColor: colorGreen,
                        stack: 'stack',
                    },
                    {
                        label: LABEL_FAILED,
                        data: BAR_DATA.map(function (b) { return b.failed; }),
                        backgroundColor: colorRed,
                        stack: 'stack',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    x: {
                        stacked: true,
                        ticks: {
                            maxTicksLimit: 14,
                            color: textColor,
                            maxRotation: 30,
                            font: { size: 10 },
                        },
                        grid: { color: gridColor },
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            color: textColor,
                            font: { size: 10 },
                            callback: function (v) { return Number.isInteger(v) ? v : null; },
                        },
                        grid: { color: gridColor },
                    },
                },
                plugins: {
                    legend: {
                        labels: { color: textColor, boxWidth: 14, font: { size: 11 } },
                    },
                },
            },
        });
    } else if (activityCtx) {
        var ctx2d2 = activityCtx.getContext('2d');
        activityCtx.height = 80;
        ctx2d2.fillStyle = textColor;
        ctx2d2.textAlign = 'center';
        ctx2d2.font = '13px sans-serif';
        ctx2d2.fillText('<?= addslashes($t('monitor_no_data')) ?>', activityCtx.width / 2, 50);
    }

})();
</script>
