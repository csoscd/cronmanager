<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Cron Job List Template
 *
 * Displays all cron jobs in a filterable table with tag and user filters.
 *
 * Variables available in this template:
 *   array  $jobs        – array of job records from the agent
 *   array  $tags        – all known tag records
 *   string $filterTag   – currently active tag filter value
 *   string $filterUser  – currently active user filter value
 *   array  $users       – unique linux_users derived from all jobs
 *   bool   $isAdmin     – whether the current user has admin role
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

$jobs         = isset($jobs)         && is_array($jobs)         ? $jobs         : [];
$tags         = isset($tags)         && is_array($tags)         ? $tags         : [];
$users        = isset($users)        && is_array($users)        ? $users        : [];
$allTargets   = isset($allTargets)   && is_array($allTargets)   ? $allTargets   : [];
$filterTag    = isset($filterTag)    ? (string) $filterTag      : '';
$filterUser   = isset($filterUser)   ? (string) $filterUser     : '';
$filterTarget = isset($filterTarget) ? (string) $filterTarget   : '';
$filterSearch = isset($filterSearch) ? (string) $filterSearch   : '';
$filterResult = isset($filterResult) ? (string) $filterResult   : '';
$isAdmin      = isset($isAdmin)      && (bool)  $isAdmin;

// Pagination
$pageSize    = isset($pageSize)    ? (int) $pageSize    : 25;
$currentPage = isset($currentPage) ? (int) $currentPage : 1;
$totalJobs   = isset($totalJobs)   ? (int) $totalJobs   : count($jobs);
$totalPages  = isset($totalPages)  ? (int) $totalPages  : 1;

$showFrom = $totalJobs === 0 ? 0 : ($pageSize > 0 ? ($currentPage - 1) * $pageSize + 1 : 1);
$showTo   = $pageSize > 0 ? min($currentPage * $pageSize, $totalJobs) : $totalJobs;

/**
 * Build a pagination URL preserving all current filters.
 *
 * @param int $targetPage The page number for the link.
 */
$pageUrl = static function (int $targetPage) use ($filterTag, $filterUser, $filterTarget, $filterSearch, $filterResult, $pageSize): string {
    $params = array_filter([
        'tag'    => $filterTag,
        'user'   => $filterUser,
        'target' => $filterTarget,
        'search' => $filterSearch,
        'result' => $filterResult,
        'limit'  => $pageSize > 0 ? (string) $pageSize : '0',
        'page'   => (string) $targetPage,
    ], static fn(string $v): bool => $v !== '');
    return '/crons?' . http_build_query($params);
};
?>

<!-- ======================================================================
     Page header
     ====================================================================== -->
<div class="mb-6 flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
        <?= htmlspecialchars($t('crons_title'), ENT_QUOTES, 'UTF-8') ?>
    </h1>
    <?php if ($isAdmin): ?>
        <div class="flex items-center gap-2">
            <a href="/crons/import"
               class="inline-flex items-center gap-1.5 bg-gray-600 hover:bg-gray-700 text-white
                      text-sm font-medium px-4 py-2.5 rounded-lg transition focus:outline-none
                      focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                <?= htmlspecialchars($t('import_title'), ENT_QUOTES, 'UTF-8') ?>
            </a>
            <a href="/crons/new?_return=<?= htmlspecialchars(rawurlencode($pageUrl($currentPage)), ENT_QUOTES, 'UTF-8') ?>"
               class="inline-flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white
                      text-sm font-medium px-4 py-2.5 rounded-lg transition focus:outline-none
                      focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                <?= htmlspecialchars($t('cron_add'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- ======================================================================
     Filter bar
     ====================================================================== -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-6">
    <form method="GET" action="/crons" class="flex flex-wrap items-end gap-3">

        <!-- Free-text search -->
        <div class="flex-1 min-w-48">
            <label for="filter-search" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('filter_search'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                    </svg>
                </div>
                <input type="text"
                       id="filter-search"
                       name="search"
                       value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="<?= htmlspecialchars($t('filter_search_placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                       class="w-full border border-gray-300 dark:border-gray-600 rounded-lg pl-9 pr-3 py-2 text-sm
                              bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                              focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </div>

        <!-- Tag filter -->
        <div class="flex-1 min-w-36">
            <label for="filter-tag" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('cron_tags'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="filter-tag" name="tag"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                           focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value=""><?= htmlspecialchars($t('filter_all_tags'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($tags as $tag): ?>
                    <?php
                        $tagName = (string) ($tag['name'] ?? $tag);
                        $sel     = $filterTag === $tagName ? ' selected' : '';
                    ?>
                    <option value="<?= htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') ?>"<?= $sel ?>>
                        <?= htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- User filter -->
        <div class="flex-1 min-w-36">
            <label for="filter-user" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('cron_linux_user'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="filter-user" name="user"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                           focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value=""><?= htmlspecialchars($t('filter_all_users'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($users as $user): ?>
                    <?php $sel = $filterUser === $user ? ' selected' : ''; ?>
                    <option value="<?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?>"<?= $sel ?>>
                        <?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Target filter (shown when more than one unique target exists) -->
        <?php if (count($allTargets) > 1): ?>
        <div class="flex-1 min-w-36">
            <label for="filter-target" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('cron_targets'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="filter-target" name="target"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                           focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value=""><?= htmlspecialchars($t('filter_all_targets'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($allTargets as $target): ?>
                    <?php $sel = $filterTarget === $target ? ' selected' : ''; ?>
                    <option value="<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>"<?= $sel ?>>
                        <?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <!-- Last result filter -->
        <div class="flex-1 min-w-36">
            <label for="filter-result" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('filter_result'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="filter-result" name="result"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                           focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value=""><?= htmlspecialchars($t('filter_result_all'), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="ok"<?= $filterResult === 'ok' ? ' selected' : '' ?>>
                    <?= htmlspecialchars($t('filter_result_ok'), ENT_QUOTES, 'UTF-8') ?>
                </option>
                <option value="failed"<?= $filterResult === 'failed' ? ' selected' : '' ?>>
                    <?= htmlspecialchars($t('filter_result_failed'), ENT_QUOTES, 'UTF-8') ?>
                </option>
                <option value="not_run"<?= $filterResult === 'not_run' ? ' selected' : '' ?>>
                    <?= htmlspecialchars($t('filter_result_not_run'), ENT_QUOTES, 'UTF-8') ?>
                </option>
            </select>
        </div>

        <!-- Page size selector -->
        <div class="flex-1 min-w-28">
            <label for="filter-limit" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('pagination_page_size'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="filter-limit" name="limit"
                    onchange="this.form.submit()"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                           focus:outline-none focus:ring-2 focus:ring-blue-500">
                <?php foreach ([10, 25, 50, 0] as $size): ?>
                    <option value="<?= $size ?>"<?= $pageSize === $size ? ' selected' : '' ?>>
                        <?= $size === 0
                            ? htmlspecialchars($t('pagination_all'), ENT_QUOTES, 'UTF-8')
                            : $size ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Apply button -->
        <div>
            <button type="submit"
                    class="bg-gray-700 hover:bg-gray-800 text-white text-sm font-medium
                           px-5 py-2 rounded-lg transition focus:outline-none
                           focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                <?= htmlspecialchars($t('filter_apply'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>

        <?php if ($filterTag !== '' || $filterUser !== '' || $filterTarget !== '' || $filterSearch !== '' || $filterResult !== ''): ?>
            <div>
                <a href="/crons?_reset=1"
                   class="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white underline py-2 block">
                    &times; <?= htmlspecialchars($t('filter_reset'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        <?php endif; ?>

    </form>
</div>

<!-- ======================================================================
     Jobs table
     ====================================================================== -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">

    <?php if ($totalJobs > 0): ?>
        <div class="px-4 py-2 border-b border-gray-100 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400">
            <?= htmlspecialchars(
                $t('pagination_showing', [
                    'from'  => (string) $showFrom,
                    'to'    => (string) $showTo,
                    'total' => (string) $totalJobs,
                ]),
                ENT_QUOTES, 'UTF-8'
            ) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($jobs)): ?>
        <div class="px-6 py-12 text-center text-gray-400 dark:text-gray-500 text-sm">
            <?= htmlspecialchars($t('cron_no_jobs'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_schedule'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_description'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_linux_user'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_tags'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_targets'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_active'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_last_run'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?= htmlspecialchars($t('exit_code'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?= htmlspecialchars($t('actions'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($jobs as $job): ?>
                        <?php
                            $jobId     = (string) ($job['id']          ?? '');
                            $schedule  = (string) ($job['schedule']    ?? '');
                            $desc      = (string) ($job['description'] ?? '');
                            $linuxUser = (string) ($job['linux_user']  ?? '');
                            $jobTags   = (array)  ($job['tags']    ?? []);
                            $jobTargets    = (array)  ($job['targets'] ?? ['local']);
                            $isActive      = !empty($job['active']);
                            $crontabOk     = !isset($job['crontab_ok']) || (bool) $job['crontab_ok'];
                            $lastRun       = (string) ($job['last_run'] ?? '');
                            $exitCode      = isset($job['last_exit_code']) ? (int) $job['last_exit_code'] : null;
                            $limitSeconds  = isset($job['execution_limit_seconds']) && $job['execution_limit_seconds'] !== null
                                ? (int) $job['execution_limit_seconds'] : null;

                            // Exit code badge style
                            if ($exitCode === null) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">—</span>';
                            } elseif ($exitCode === 0) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">0</span>';
                            } elseif ($exitCode === -2) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800">'
                                    . htmlspecialchars($t('cron_killed_badge'), ENT_QUOTES, 'UTF-8') . '</span>';
                            } elseif ($exitCode === -3) {
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">'
                                    . htmlspecialchars($t('cron_limit_badge'), ENT_QUOTES, 'UTF-8') . '</span>';
                            } else {
                                $safeCode  = htmlspecialchars((string) $exitCode, ENT_QUOTES, 'UTF-8');
                                $exitBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">' . $safeCode . '</span>';
                            }
                        ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">

                            <!-- Schedule -->
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                <span class="font-mono"><?= htmlspecialchars($schedule, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php $schedHuman = (string) ($job['schedule_human'] ?? ''); ?>
                                <?php if ($schedHuman !== '' && $schedHuman !== $schedule): ?>
                                    <br><span class="text-xs text-gray-400 dark:text-gray-500"><?= htmlspecialchars($schedHuman, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Description / link to detail -->
                            <td class="px-4 py-3 text-sm">
                                <?php if ($jobId !== ''): ?>
                                    <a href="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>"
                                       class="text-blue-600 hover:underline font-medium">
                                        <?= htmlspecialchars($desc !== '' ? $desc : "Job #{$jobId}", ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </td>

                            <!-- Linux user -->
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                                <?= htmlspecialchars($linuxUser, ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <!-- Tags -->
                            <td class="px-4 py-3 text-sm">
                                <div class="flex flex-wrap gap-1">
                                    <?php foreach ($jobTags as $tag): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                            <?= htmlspecialchars((string) $tag, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </td>

                            <!-- Targets badges -->
                            <td class="px-4 py-3 text-sm">
                                <div class="flex flex-wrap gap-1">
                                    <?php foreach ($jobTargets as $tgt): ?>
                                        <?php $tgt = (string) $tgt; ?>
                                        <?php if ($tgt === 'local'): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                                <?= htmlspecialchars($t('cron_local_badge'), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 font-mono">
                                                <?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </td>

                            <!-- Status badge -->
                            <td class="px-4 py-3 text-sm">
                                <div class="flex flex-col gap-1">
                                <?php if ($isActive): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <?= htmlspecialchars($t('cron_active'), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if (!$crontabOk): ?>
                                        <span title="<?= htmlspecialchars($t('cron_crontab_missing_hint'), ENT_QUOTES, 'UTF-8') ?>"
                                              class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700 cursor-help">
                                            <svg class="w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                                            </svg>
                                            <?= htmlspecialchars($t('cron_crontab_missing'), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                        <?= htmlspecialchars($t('cron_inactive'), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                                </div>
                            </td>

                            <!-- Last run -->
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                <?= htmlspecialchars($lastRun !== '' ? $lastRun : $t('cron_never_run'), ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <!-- Exit code badge + limit indicator -->
                            <td class="px-4 py-3 text-sm">
                                <div class="flex flex-wrap items-center gap-1">
                                    <?= $exitBadge ?>
                                    <?php if ($limitSeconds !== null): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900 dark:text-blue-200"
                                              title="<?= htmlspecialchars($t('cron_execution_limit') . ': ' . $limitSeconds . 's', ENT_QUOTES, 'UTF-8') ?>">
                                            &#9201; <?= (int) $limitSeconds ?>s
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Open button (all users) -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <?php if ($jobId !== ''): ?>
                                    <a href="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>"
                                       class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold transition"
                                       style="background:rgba(59,130,246,.1);color:var(--cm-primary);border:1px solid rgba(59,130,246,.2)">
                                        <?= htmlspecialchars($t('cron_open'), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php endif; ?>
                            </td>

                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ======================================================================
     Pagination controls
     ====================================================================== -->
<?php if ($totalPages > 1): ?>
<div class="mt-4 flex items-center justify-between">

    <!-- Previous -->
    <div>
        <?php if ($currentPage > 1): ?>
            <a href="<?= htmlspecialchars($pageUrl($currentPage - 1), ENT_QUOTES, 'UTF-8') ?>"
               class="inline-flex items-center gap-1 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                      text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700
                      text-sm font-medium px-4 py-2 rounded-lg transition
                      focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                &larr; <?= htmlspecialchars($t('pagination_previous'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php else: ?>
            <span class="inline-flex items-center gap-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                         text-gray-300 dark:text-gray-600 text-sm font-medium px-4 py-2 rounded-lg cursor-default">
                &larr; <?= htmlspecialchars($t('pagination_previous'), ENT_QUOTES, 'UTF-8') ?>
            </span>
        <?php endif; ?>
    </div>

    <!-- Page numbers (sliding window of up to 7) -->
    <div class="flex items-center gap-1">
        <?php
        $windowStart = max(1, $currentPage - 3);
        $windowEnd   = min($totalPages, $windowStart + 6);
        $windowStart = max(1, $windowEnd - 6);

        if ($windowStart > 1): ?>
            <a href="<?= htmlspecialchars($pageUrl(1), ENT_QUOTES, 'UTF-8') ?>"
               class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm border border-gray-300 dark:border-gray-600
                      bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                      hover:bg-gray-50 dark:hover:bg-gray-700 transition">1</a>
            <?php if ($windowStart > 2): ?>
                <span class="text-gray-400 dark:text-gray-500 px-1">…</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
            <?php if ($p === $currentPage): ?>
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm font-semibold
                             bg-blue-600 text-white border border-blue-600">
                    <?= $p ?>
                </span>
            <?php else: ?>
                <a href="<?= htmlspecialchars($pageUrl($p), ENT_QUOTES, 'UTF-8') ?>"
                   class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm border border-gray-300 dark:border-gray-600
                          bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                          hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    <?= $p ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($windowEnd < $totalPages): ?>
            <?php if ($windowEnd < $totalPages - 1): ?>
                <span class="text-gray-400 dark:text-gray-500 px-1">…</span>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($pageUrl($totalPages), ENT_QUOTES, 'UTF-8') ?>"
               class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm border border-gray-300 dark:border-gray-600
                      bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                      hover:bg-gray-50 dark:hover:bg-gray-700 transition"><?= $totalPages ?></a>
        <?php endif; ?>
    </div>

    <!-- Next -->
    <div>
        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= htmlspecialchars($pageUrl($currentPage + 1), ENT_QUOTES, 'UTF-8') ?>"
               class="inline-flex items-center gap-1 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                      text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700
                      text-sm font-medium px-4 py-2 rounded-lg transition
                      focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <?= htmlspecialchars($t('pagination_next'), ENT_QUOTES, 'UTF-8') ?> &rarr;
            </a>
        <?php else: ?>
            <span class="inline-flex items-center gap-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                         text-gray-300 dark:text-gray-600 text-sm font-medium px-4 py-2 rounded-lg cursor-default">
                <?= htmlspecialchars($t('pagination_next'), ENT_QUOTES, 'UTF-8') ?> &rarr;
            </span>
        <?php endif; ?>
    </div>

</div>
<?php endif; ?>
