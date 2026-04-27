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

// Determine success rate colour via brand inline styles
$rateStyle = 'color:var(--cm-muted)';
if ($successRate !== null) {
    if ((float) $successRate >= 95) {
        $rateStyle = 'color:var(--cm-success)';
    } elseif ((float) $successRate >= 80) {
        $rateStyle = 'color:var(--cm-warning)';
    } else {
        $rateStyle = 'color:var(--cm-danger)';
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
        <p id="cm-mon-daterange" class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
            <?php if ($fromStr !== '' && $toStr !== ''): ?>
                <?= htmlspecialchars($fromStr, ENT_QUOTES, 'UTF-8') ?>
                &ndash;
                <?= htmlspecialchars($toStr, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </p>
    </div>

    <!-- Period selector -->
    <div class="flex flex-wrap items-center gap-1">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 mr-1">
            <?= htmlspecialchars($t('monitor_period'), ENT_QUOTES, 'UTF-8') ?>:
        </span>
        <?php foreach ($validPeriods as $p): ?>
            <?php
                $isActive = $p === $period;
                $url = '/crons/' . rawurlencode($jobId) . '/monitor?period=' . rawurlencode($p);
                if ($selectedTarget !== null) {
                    $url .= '&target=' . rawurlencode($selectedTarget);
                }
                $btnClass = $isActive
                    ? 'px-3 py-1.5 text-xs font-semibold rounded-md text-white shadow-sm'
                    : 'px-3 py-1.5 text-xs font-medium rounded-md border transition-colors';
                $btnStyle = $isActive
                    ? 'background:var(--cm-grad);border:none'
                    : 'background:var(--cm-bg-card);border-color:var(--cm-border);color:var(--cm-muted)';
            ?>
            <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
               class="<?= $btnClass ?>" style="<?= $btnStyle ?>"
               data-period="<?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($t('monitor_period_' . $p), ENT_QUOTES, 'UTF-8') ?>
            </a>
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
        $allActive   = $selectedTarget === null;
        $allUrl      = '/crons/' . rawurlencode($jobId) . '/monitor?period=' . rawurlencode($period);
        $allBtnClass = $allActive
            ? 'px-3 py-1.5 text-xs font-semibold rounded-md text-white shadow-sm'
            : 'px-3 py-1.5 text-xs font-medium rounded-md border transition-colors';
        $allBtnStyle = $allActive
            ? 'background:var(--cm-grad);border:none'
            : 'background:var(--cm-bg-card);border-color:var(--cm-border);color:var(--cm-muted)';
    ?>
    <a href="<?= htmlspecialchars($allUrl, ENT_QUOTES, 'UTF-8') ?>"
       class="<?= $allBtnClass ?>" style="<?= $allBtnStyle ?>"
       data-target-btn="">
        <?= htmlspecialchars($t('monitor_all_targets'), ENT_QUOTES, 'UTF-8') ?>
    </a>

    <?php foreach ($targets as $tgt): ?>
        <?php
            $tgtActive   = $selectedTarget === $tgt;
            $tgtUrl      = '/crons/' . rawurlencode($jobId) . '/monitor?period=' . rawurlencode($period) . '&target=' . rawurlencode($tgt);
            $tgtBtnClass = $tgtActive
                ? 'px-3 py-1.5 text-xs font-semibold rounded-md text-white shadow-sm font-mono'
                : 'px-3 py-1.5 text-xs font-medium rounded-md border transition-colors font-mono';
            $tgtBtnStyle = $tgtActive
                ? 'background:var(--cm-grad);border:none'
                : 'background:var(--cm-bg-card);border-color:var(--cm-border);color:var(--cm-muted)';
        ?>
        <a href="<?= htmlspecialchars($tgtUrl, ENT_QUOTES, 'UTF-8') ?>"
           class="<?= $tgtBtnClass ?>" style="<?= $tgtBtnStyle ?>"
           data-target-btn="<?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>
        </a>
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
        <p id="cm-mon-rate" class="text-3xl font-bold" style="<?= $rateStyle ?>">
            <?= $successRate !== null
                ? htmlspecialchars(number_format((float) $successRate, 1) . ' %', ENT_QUOTES, 'UTF-8')
                : '<span class="text-gray-300 dark:text-gray-600">—</span>' ?>
        </p>
        <p id="cm-mon-rate-sub" class="mt-1 text-xs text-gray-400 dark:text-gray-500">
            <?= htmlspecialchars($successCount . ' / ' . $execCount, ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>

    <!-- Avg Duration -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 px-5 py-4">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            <?= htmlspecialchars($t('monitor_avg_duration'), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p id="cm-mon-avg" class="text-3xl font-bold text-gray-700 dark:text-gray-300">
            <?php if ($avgDuration !== null): ?>
                <?= htmlspecialchars(number_format((float) $avgDuration, 1), ENT_QUOTES, 'UTF-8') ?>
                <span class="text-base font-normal text-gray-400"><?= htmlspecialchars($t('monitor_seconds'), ENT_QUOTES, 'UTF-8') ?></span>
            <?php else: ?>
                <span class="text-gray-300 dark:text-gray-600">—</span>
            <?php endif; ?>
        </p>
        <p id="cm-mon-avg-sub" class="mt-1 text-xs text-gray-400 dark:text-gray-500">
            <?php if ($minDuration !== null && $maxDuration !== null): ?>
                <?= htmlspecialchars($t('monitor_min'), ENT_QUOTES, 'UTF-8') ?>
                <?= htmlspecialchars((string) $minDuration, ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars($t('monitor_seconds'), ENT_QUOTES, 'UTF-8') ?>
                &nbsp;/&nbsp;
                <?= htmlspecialchars($t('monitor_max'), ENT_QUOTES, 'UTF-8') ?>
                <?= htmlspecialchars((string) $maxDuration, ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars($t('monitor_seconds'), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </p>
    </div>

    <!-- Executions -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 px-5 py-4">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
            <?= htmlspecialchars($t('monitor_executions'), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p id="cm-mon-exec" class="text-3xl font-bold text-gray-700 dark:text-gray-300">
            <?= htmlspecialchars(number_format($execCount), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p id="cm-mon-exec-sub" class="mt-1 text-xs text-gray-400 dark:text-gray-500">
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
        <p id="cm-mon-alerts" class="text-3xl font-bold <?= $alertCount > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' ?>">
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
                <tbody id="cm-mon-recent-tbody" class="divide-y divide-gray-100 dark:divide-gray-700">
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
     Chart.js initialisation + AJAX period/target switching + auto-refresh
     ====================================================================== -->
<script>
(function () {
    'use strict';

    // ── Server-injected constants ─────────────────────────────────────────────
    var JOB_ID         = <?= json_encode($jobId) ?>;
    var INIT_PERIOD    = <?= json_encode($period) ?>;
    var INIT_TARGET    = <?= $selectedTarget !== null ? json_encode($selectedTarget) : 'null' ?>;
    var INIT_DURATION  = <?= $durationSeries ?>;
    var INIT_BARS      = <?= $barBuckets ?>;
    var INIT_AVG       = <?= $avgDuration !== null ? json_encode((float) $avgDuration) : 'null' ?>;

    // ── Translated labels (PHP-injected once, reused in JS rendering) ─────────
    var L = {
        success : <?= json_encode($t('monitor_success_label')) ?>,
        failed  : <?= json_encode($t('monitor_failed_label')) ?>,
        avg     : <?= json_encode($t('monitor_avg_duration')) ?>,
        seconds : <?= json_encode($t('monitor_seconds')) ?>,
        noData  : <?= json_encode($t('monitor_no_data')) ?>,
        running : <?= json_encode($t('status_running')) ?>,
        local   : <?= json_encode($t('cron_local_badge')) ?>,
        min     : <?= json_encode($t('monitor_min')) ?>,
        max     : <?= json_encode($t('monitor_max')) ?>,
        duration: <?= json_encode($t('duration')) ?>,
    };

    // ── Chart colour tokens ───────────────────────────────────────────────────
    var C_TEXT  = '#8888bb';
    var C_GRID  = '#1e1e40';
    var C_GREEN = '#34d399';
    var C_RED   = '#f87171';
    var C_LINE  = '#818cf8';
    var C_AVG   = 'rgba(251,191,36,0.8)';

    // ── State ─────────────────────────────────────────────────────────────────
    var currentPeriod = INIT_PERIOD;
    var currentTarget = INIT_TARGET;
    var durationChart = null;
    var activityChart = null;
    var poller        = null;
    var SHORT_PERIODS = ['1h', '6h', '12h', '24h'];

    // ── Helpers ───────────────────────────────────────────────────────────────
    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function el(id) { return document.getElementById(id); }

    function setText(id, text) {
        var e = el(id);
        if (e) { e.textContent = String(text); }
    }

    // ── Chart rendering ───────────────────────────────────────────────────────
    function initCharts(durationData, barData, avgDuration) {
        if (durationChart) { durationChart.destroy(); durationChart = null; }
        if (activityChart) { activityChart.destroy(); activityChart = null; }

        var durCtx = el('durationChart');
        if (durCtx) {
            if (durationData.length > 0) {
                var ptColors = durationData.map(function (d) { return d.success ? C_GREEN : C_RED; });
                var sets = [{
                    label: L.duration,
                    data: durationData.map(function (d) { return d.duration_seconds; }),
                    borderColor: C_LINE,
                    backgroundColor: 'rgba(129,140,248,.08)',
                    pointBackgroundColor: ptColors,
                    pointBorderColor: ptColors,
                    pointRadius: durationData.length > 100 ? 2 : 4,
                    pointHoverRadius: 6,
                    tension: 0.2,
                    fill: true,
                }];
                if (avgDuration !== null) {
                    sets.push({
                        label: L.avg,
                        data: durationData.map(function () { return avgDuration; }),
                        borderColor: C_AVG,
                        borderDash: [6, 4],
                        borderWidth: 1.5,
                        pointRadius: 0,
                        fill: false,
                        tension: 0,
                    });
                }
                durationChart = new Chart(durCtx, {
                    type: 'line',
                    data: {
                        labels: durationData.map(function (d) {
                            return d.started_at.replace('T', ' ').substring(0, 16);
                        }),
                        datasets: sets,
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        interaction: { intersect: false, mode: 'index' },
                        scales: {
                            x: {
                                ticks: { maxTicksLimit: 10, color: C_TEXT, maxRotation: 30, font: { size: 10 } },
                                grid:  { color: C_GRID },
                            },
                            y: {
                                beginAtZero: true,
                                title: { display: true, text: L.seconds, color: C_TEXT, font: { size: 11 } },
                                ticks: { color: C_TEXT, font: { size: 10 } },
                                grid:  { color: C_GRID },
                            },
                        },
                        plugins: {
                            legend: {
                                display: avgDuration !== null,
                                labels: { color: C_TEXT, boxWidth: 20, font: { size: 11 } },
                            },
                            tooltip: {
                                backgroundColor: '#12122a', borderColor: '#1e1e40', borderWidth: 1,
                                titleColor: '#f0f0ff', bodyColor: '#8888bb',
                                callbacks: {
                                    label: function (ctx) {
                                        if (ctx.datasetIndex === 0) {
                                            var d = durationData[ctx.dataIndex];
                                            return (d.success ? L.success : L.failed) + ': ' + ctx.parsed.y + ' ' + L.seconds;
                                        }
                                        return ctx.dataset.label + ': ' + ctx.parsed.y + ' ' + L.seconds;
                                    },
                                },
                            },
                        },
                    },
                });
            } else {
                var c = durCtx.getContext('2d');
                durCtx.height = 80;
                c.fillStyle = C_TEXT;
                c.textAlign = 'center';
                c.font = '13px sans-serif';
                c.fillText(L.noData, durCtx.width / 2, 50);
            }
        }

        var actCtx = el('activityChart');
        if (actCtx) {
            if (barData.length > 0) {
                activityChart = new Chart(actCtx, {
                    type: 'bar',
                    data: {
                        labels: barData.map(function (b) { return b.label; }),
                        datasets: [
                            { label: L.success, data: barData.map(function (b) { return b.success; }), backgroundColor: C_GREEN, stack: 'stack' },
                            { label: L.failed,  data: barData.map(function (b) { return b.failed;  }), backgroundColor: C_RED,   stack: 'stack' },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        scales: {
                            x: { stacked: true, ticks: { maxTicksLimit: 14, color: C_TEXT, maxRotation: 30, font: { size: 10 } }, grid: { color: C_GRID } },
                            y: {
                                stacked: true, beginAtZero: true,
                                ticks: { stepSize: 1, color: C_TEXT, font: { size: 10 }, callback: function (v) { return Number.isInteger(v) ? v : null; } },
                                grid: { color: C_GRID },
                            },
                        },
                        plugins: {
                            legend: { labels: { color: C_TEXT, boxWidth: 14, font: { size: 11 } } },
                            tooltip: { backgroundColor: '#12122a', borderColor: '#1e1e40', borderWidth: 1, titleColor: '#f0f0ff', bodyColor: '#8888bb' },
                        },
                    },
                });
            } else {
                var c2 = actCtx.getContext('2d');
                actCtx.height = 80;
                c2.fillStyle = C_TEXT;
                c2.textAlign = 'center';
                c2.font = '13px sans-serif';
                c2.fillText(L.noData, actCtx.width / 2, 50);
            }
        }
    }

    // ── KPI card updaters ─────────────────────────────────────────────────────
    function updateKpis(stats) {
        var rate = stats.success_rate != null ? parseFloat(stats.success_rate) : null;
        var rateEl = el('cm-mon-rate');
        if (rateEl) {
            rateEl.textContent = rate !== null ? rate.toFixed(1) + ' %' : '—';
            rateEl.style.color = rate === null ? 'var(--cm-muted)'
                : rate >= 95 ? 'var(--cm-success)'
                : rate >= 80 ? 'var(--cm-warning)'
                : 'var(--cm-danger)';
        }
        setText('cm-mon-rate-sub', (stats.success_count || 0) + ' / ' + (stats.execution_count || 0));

        var avgEl = el('cm-mon-avg');
        if (avgEl) {
            avgEl.innerHTML = stats.avg_duration != null
                ? esc(parseFloat(stats.avg_duration).toFixed(1)) + '<span class="text-base font-normal text-gray-400"> ' + esc(L.seconds) + '</span>'
                : '<span class="text-gray-300 dark:text-gray-600">—</span>';
        }
        var avgSubEl = el('cm-mon-avg-sub');
        if (avgSubEl) {
            avgSubEl.innerHTML = (stats.min_duration != null && stats.max_duration != null)
                ? esc(L.min) + ' ' + esc(stats.min_duration) + esc(L.seconds) + '&nbsp;/&nbsp;' + esc(L.max) + ' ' + esc(stats.max_duration) + esc(L.seconds)
                : '';
        }

        setText('cm-mon-exec', parseInt(stats.execution_count || 0, 10).toLocaleString());
        var execSubEl = el('cm-mon-exec-sub');
        if (execSubEl) {
            var fails = parseInt(stats.failure_count || 0, 10);
            execSubEl.innerHTML = fails > 0
                ? '<span class="text-red-500">' + fails + '</span> ' + esc(L.failed)
                : esc(L.success) + ' ' + parseInt(stats.success_count || 0, 10);
        }

        var alertEl = el('cm-mon-alerts');
        if (alertEl) {
            var alerts = parseInt(stats.alert_count || 0, 10);
            alertEl.textContent = alerts.toLocaleString();
            alertEl.className = 'text-3xl font-bold ' + (alerts > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300');
        }
    }

    // ── Recent table renderer ─────────────────────────────────────────────────
    function renderRecent(recent) {
        if (!recent || recent.length === 0) {
            return '<tr><td colspan="4" class="px-6 py-10 text-center text-gray-400 dark:text-gray-500 text-sm">' + esc(L.noData) + '</td></tr>';
        }
        return recent.map(function (e) {
            var code   = e.exit_code != null ? parseInt(e.exit_code, 10) : null;
            var dur    = e.duration_seconds != null ? parseFloat(e.duration_seconds).toFixed(1) + ' ' + esc(L.seconds) : '—';
            var tgt    = e.target || '';
            var cls    = (code !== null && code !== 0) ? 'bg-red-50 dark:bg-red-950' : 'hover:bg-gray-50 dark:hover:bg-gray-700';
            var tgtBadge = (tgt === '' || tgt === 'local')
                ? '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">' + esc(L.local) + '</span>'
                : '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 font-mono">' + esc(tgt) + '</span>';
            var exitBadge = code === null
                ? '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">' + esc(L.running) + '</span>'
                : code === 0
                    ? '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">0</span>'
                    : '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">' + esc(String(code)) + '</span>';
            return '<tr class="' + cls + '">'
                + '<td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap font-mono">' + esc(e.started_at || '') + '</td>'
                + '<td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">' + dur + '</td>'
                + '<td class="px-4 py-3 text-sm whitespace-nowrap">' + tgtBadge + '</td>'
                + '<td class="px-4 py-3 text-sm">' + exitBadge + '</td>'
                + '</tr>';
        }).join('');
    }

    // ── Button active-state updater ───────────────────────────────────────────
    function ACTIVE_STYLE() { return 'background:var(--cm-grad);border:none'; }
    function IDLE_STYLE()   { return 'background:var(--cm-bg-card);border-color:var(--cm-border);color:var(--cm-muted)'; }

    function refreshButtonStates(period, target) {
        document.querySelectorAll('[data-period]').forEach(function (btn) {
            var active = btn.dataset.period === period;
            btn.className = active
                ? 'px-3 py-1.5 text-xs font-semibold rounded-md text-white shadow-sm'
                : 'px-3 py-1.5 text-xs font-medium rounded-md border transition-colors';
            btn.style.cssText = active ? ACTIVE_STYLE() : IDLE_STYLE();
        });
        document.querySelectorAll('[data-target-btn]').forEach(function (btn) {
            var btnTgt = btn.dataset.targetBtn;
            var active = (btnTgt === '' && target === null) || (btnTgt !== '' && btnTgt === target);
            var mono   = btn.classList.contains('font-mono') ? ' font-mono' : '';
            btn.className = (active
                ? 'px-3 py-1.5 text-xs font-semibold rounded-md text-white shadow-sm'
                : 'px-3 py-1.5 text-xs font-medium rounded-md border transition-colors') + mono;
            btn.style.cssText = active ? ACTIVE_STYLE() : IDLE_STYLE();
        });
    }

    // ── Auto-refresh ──────────────────────────────────────────────────────────
    function setupAutoRefresh() {
        if (poller) { poller.stop(); poller = null; }
        if (SHORT_PERIODS.indexOf(currentPeriod) !== -1) {
            poller = cmPoll(function () {
                fetchMonitorData(currentPeriod, currentTarget, false);
            }, 60000);
        }
    }

    // ── Main fetch + update function ──────────────────────────────────────────
    function fetchMonitorData(period, target, pushHistory) {
        currentPeriod = period;
        currentTarget = target;

        var url = '/crons/' + encodeURIComponent(JOB_ID) + '/monitor?_json=1&period=' + encodeURIComponent(period);
        if (target !== null) { url += '&target=' + encodeURIComponent(target); }

        cmFetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // Date range
                var drEl = el('cm-mon-daterange');
                if (drEl) { drEl.textContent = (data.from || '') + ' – ' + (data.to || ''); }

                // KPI cards
                updateKpis(data.stats || {});

                // Charts
                initCharts(data.duration_series || [], data.bar_buckets || [], (data.stats || {}).avg_duration || null);

                // Recent table
                var tbody = el('cm-mon-recent-tbody');
                if (tbody) { tbody.innerHTML = renderRecent(data.recent || []); }

                // Button states
                refreshButtonStates(period, target);

                // History API — update URL without reload for bookmarkability
                if (pushHistory) {
                    var histUrl = '/crons/' + encodeURIComponent(JOB_ID) + '/monitor?period=' + encodeURIComponent(period);
                    if (target !== null) { histUrl += '&target=' + encodeURIComponent(target); }
                    history.pushState({ period: period, target: target }, '', histUrl);
                }

                setupAutoRefresh();
            })
            .catch(function () {
                cmToast(<?= json_encode($t('error_agent_unavailable')) ?>, 'error');
            });
    }

    // ── Event: period buttons ─────────────────────────────────────────────────
    document.querySelectorAll('[data-period]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            fetchMonitorData(btn.dataset.period, currentTarget, true);
        });
    });

    // ── Event: target buttons ─────────────────────────────────────────────────
    document.querySelectorAll('[data-target-btn]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var tgt = btn.dataset.targetBtn || null;
            fetchMonitorData(currentPeriod, tgt === '' ? null : tgt, true);
        });
    });

    // ── Event: browser back/forward ───────────────────────────────────────────
    window.addEventListener('popstate', function (e) {
        if (e.state && e.state.period) {
            fetchMonitorData(e.state.period, e.state.target || null, false);
        }
    });

    // ── Initial render from PHP-injected data (no extra HTTP request) ─────────
    initCharts(INIT_DURATION, INIT_BARS, INIT_AVG);
    setupAutoRefresh();

}());
</script>
