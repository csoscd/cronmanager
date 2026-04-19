<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Timeline Template
 *
 * Displays a paginated, filterable execution history across all jobs.
 *
 * Variables available in this template:
 *   array  $history    – execution history records from the agent
 *   array  $tags       – all known tags (for filter dropdown)
 *   array  $users      – unique linux_users (for filter dropdown)
 *   array  $allTargets – unique execution targets (for filter dropdown)
 *   int    $total      – total number of matching records (for pagination)
 *   int    $limit      – records per page
 *   int    $offset     – current page offset
 *   array  $filters    – active filter values: search, job_id, tag, user, target, status, from, to
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

$history    = isset($history)    && is_array($history)    ? $history    : [];
$tags       = isset($tags)       && is_array($tags)       ? $tags       : [];
$users      = isset($users)      && is_array($users)      ? $users      : [];
$allTargets = isset($allTargets) && is_array($allTargets) ? $allTargets : [];
$total      = isset($total)  ? (int) $total              : 0;
$limit      = isset($limit)  ? max(1, (int) $limit)      : 50;
$offset     = isset($offset) ? max(0, (int) $offset)     : 0;
$filters    = isset($filters) && is_array($filters) ? $filters : [];

$activeSearch = (string) ($filters['search'] ?? '');
$activeJobId  = (string) ($filters['job_id'] ?? '');
$activeStatus = (string) ($filters['status'] ?? '');
$activeTag    = (string) ($filters['tag']    ?? '');
$activeUser   = (string) ($filters['user']   ?? '');
$activeTarget = (string) ($filters['target'] ?? '');
$activeFrom   = (string) ($filters['from']   ?? '');
$activeTo     = (string) ($filters['to']     ?? '');

$hasActiveFilter = $activeSearch !== '' || $activeJobId !== '' || $activeTag !== ''
    || $activeUser !== '' || $activeTarget !== '' || $activeStatus !== ''
    || $activeFrom !== '' || $activeTo !== '';

// Direct-link mode: filters come from a programmatic link (dashboard / notification)
// and have not been saved to cookies. Pagination links preserve this mode so the
// date-range cookie cannot silently narrow the result set across pages.
$isDirect = isset($isDirect) && (bool) $isDirect;

// Pagination helpers
$prevOffset  = max(0, $offset - $limit);
$nextOffset  = $offset + $limit;
$hasPrev     = $offset > 0;
$hasNext     = $nextOffset < $total;
$showFrom    = $total === 0 ? 0 : $offset + 1;
$showTo      = min($offset + $limit, $total);

/**
 * Build the pagination URL preserving all current filters except offset.
 *
 * @param int $newOffset Offset to use in the generated URL.
 */
$pageUrl = static function (int $newOffset) use ($filters, $limit, $isDirect): string {
    $params = array_filter([
        'search' => $filters['search'] ?? '',
        'job_id' => $filters['job_id'] ?? '',
        'tag'    => $filters['tag']    ?? '',
        'user'   => $filters['user']   ?? '',
        'target' => $filters['target'] ?? '',
        'status' => $filters['status'] ?? '',
        'from'   => $filters['from']   ?? '',
        'to'     => $filters['to']     ?? '',
        'limit'  => (string) $limit,
        'offset' => (string) $newOffset,
    ], static fn(string $v): bool => $v !== '');

    if ($isDirect) {
        $params['_direct'] = '1';
    }

    return '/timeline?' . http_build_query($params);
};
?>

<!-- ======================================================================
     Page header
     ====================================================================== -->
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
        <?= htmlspecialchars($t('timeline_title'), ENT_QUOTES, 'UTF-8') ?>
    </h1>
</div>

<!-- ======================================================================
     Direct-link notice (shown when arriving via dashboard / notification link)
     ====================================================================== -->
<?php if ($isDirect): ?>
<div class="mb-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm
            text-blue-800 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-300">
    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <span><?= htmlspecialchars($t('timeline_direct_link_notice'), ENT_QUOTES, 'UTF-8') ?></span>
</div>
<?php endif; ?>

<!-- ======================================================================
     Filter bar
     ====================================================================== -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-6">
    <form method="GET" action="/timeline" class="flex flex-wrap items-end gap-3">

        <!-- If a job_id deep-link is active, preserve it as a hidden field so
             subsequent filter changes and pagination retain the scope. -->
        <?php if ($activeJobId !== ''): ?>
            <input type="hidden" name="job_id" value="<?= htmlspecialchars($activeJobId, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>

        <!-- Search field -->
        <div class="flex-1 min-w-40">
            <label for="filter-search" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('filter_search'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input type="text" id="filter-search" name="search"
                   value="<?= htmlspecialchars($activeSearch, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="<?= htmlspecialchars($t('filter_search_placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                   class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                          bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                          focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <!-- Tag filter -->
        <div class="flex-1 min-w-32">
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
                        $sel     = $activeTag === $tagName ? ' selected' : '';
                    ?>
                    <option value="<?= htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') ?>"<?= $sel ?>>
                        <?= htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- User filter -->
        <div class="flex-1 min-w-32">
            <label for="filter-user" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('cron_linux_user'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="filter-user" name="user"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                           focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value=""><?= htmlspecialchars($t('filter_all_users'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($users as $u): ?>
                    <?php $sel = $activeUser === $u ? ' selected' : ''; ?>
                    <option value="<?= htmlspecialchars($u, ENT_QUOTES, 'UTF-8') ?>"<?= $sel ?>>
                        <?= htmlspecialchars($u, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Target filter (shown only when more than one unique target exists) -->
        <?php if (count($allTargets) > 1): ?>
        <div class="flex-1 min-w-32">
            <label for="filter-target" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('cron_targets'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="filter-target" name="target"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                           focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value=""><?= htmlspecialchars($t('filter_all_targets'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($allTargets as $tgt): ?>
                    <?php $sel = $activeTarget === $tgt ? ' selected' : ''; ?>
                    <option value="<?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>"<?= $sel ?>>
                        <?= htmlspecialchars($tgt, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <!-- Status filter -->
        <div class="flex-1 min-w-32">
            <label for="filter-status" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('filter_status'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="filter-status" name="status"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                           focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value=""><?= htmlspecialchars($t('filter_status_all'), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="success"<?= $activeStatus === 'success' ? ' selected' : '' ?>>
                    <?= htmlspecialchars($t('filter_status_success'), ENT_QUOTES, 'UTF-8') ?>
                </option>
                <option value="failed"<?= $activeStatus === 'failed' ? ' selected' : '' ?>>
                    <?= htmlspecialchars($t('filter_status_failed'), ENT_QUOTES, 'UTF-8') ?>
                </option>
                <option value="running"<?= $activeStatus === 'running' ? ' selected' : '' ?>>
                    <?= htmlspecialchars($t('filter_status_running'), ENT_QUOTES, 'UTF-8') ?>
                </option>
            </select>
        </div>

        <!-- From date -->
        <div class="flex-1 min-w-32">
            <label for="filter-from" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('filter_from'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input type="date" id="filter-from" name="from"
                   value="<?= htmlspecialchars($activeFrom, ENT_QUOTES, 'UTF-8') ?>"
                   class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                          bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                          focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <!-- To date -->
        <div class="flex-1 min-w-32">
            <label for="filter-to" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('filter_to'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input type="date" id="filter-to" name="to"
                   value="<?= htmlspecialchars($activeTo, ENT_QUOTES, 'UTF-8') ?>"
                   class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                          bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                          focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <!-- Page size selector -->
        <div class="flex-1 min-w-28">
            <label for="filter-limit" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('pagination_page_size'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="filter-limit" name="limit"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                           focus:outline-none focus:ring-2 focus:ring-blue-500">
                <?php foreach ([10, 25, 50, 100, 500] as $sz): ?>
                    <option value="<?= $sz ?>"<?= $limit === $sz ? ' selected' : '' ?>><?= $sz ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Apply -->
        <div>
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium
                           px-5 py-2 rounded-lg transition focus:outline-none
                           focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <?= htmlspecialchars($t('filter_apply'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>

        <!-- Reset filters link -->
        <?php if ($hasActiveFilter): ?>
        <div>
            <a href="/timeline?_reset=1"
               class="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white underline py-2 block">
                &times; <?= htmlspecialchars($t('filter_reset'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
        <?php endif; ?>

    </form>
</div>

<!-- ======================================================================
     Results summary
     ====================================================================== -->
<?php if ($total > 0): ?>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        <?= htmlspecialchars($t('timeline_showing', [
            'from'  => (string) $showFrom,
            'to'    => (string) $showTo,
            'total' => (string) $total,
        ]), ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<!-- ======================================================================
     History table
     ====================================================================== -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">

    <?php if (empty($history)): ?>
        <div class="px-6 py-12 text-center text-gray-400 dark:text-gray-500 text-sm">
            <?= htmlspecialchars($t('timeline_no_results'), ENT_QUOTES, 'UTF-8') ?>
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
                            Job
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_linux_user'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_tags'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('cron_targets'), ENT_QUOTES, 'UTF-8') ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= htmlspecialchars($t('duration'), ENT_QUOTES, 'UTF-8') ?>
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
                            $jobId      = (string) ($entry['job_id']          ?? '');
                            $jobDesc    = ((string) ($entry['description'] ?? '')) !== '' ? (string) $entry['description'] : "Job #{$jobId}";
                            $entryUser  = (string) ($entry['linux_user']      ?? '');
                            $entryTags  = (array)  ($entry['tags']            ?? []);
                            $exitCode   = isset($entry['exit_code']) ? $entry['exit_code'] : null;
                            $startedAt  = (string) ($entry['started_at']      ?? '');
                            $duration   = isset($entry['duration_seconds'])
                                ? round((float) $entry['duration_seconds'], 1) . 's'
                                : '–';
                            $entryTarget = (string) ($entry['target'] ?? '');
                            $output      = (string) ($entry['output'] ?? '');
                            $isTruncated = mb_strlen($output) > 200;
                            $outputTrunc = $isTruncated
                                ? mb_substr($output, 0, 200) . '…'
                                : $output;

                            // Status badge
                            if ($exitCode === null) {
                                $statusBadge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">'
                                    . '<svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>'
                                    . htmlspecialchars($t('status_running'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
                            } elseif ((int) $exitCode === 0) {
                                $statusBadge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">'
                                    . '<svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>'
                                    . htmlspecialchars($t('status_success'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
                            } elseif ((int) $exitCode === -4) {
                                $statusBadge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">'
                                    . htmlspecialchars($t('cron_maintenance_skipped_badge'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
                            } elseif ((int) $exitCode === -5) {
                                $statusBadge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">'
                                    . htmlspecialchars($t('cron_interrupted_badge'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
                            } else {
                                $statusBadge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">'
                                    . '<svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>'
                                    . htmlspecialchars($t('status_failed'), ENT_QUOTES, 'UTF-8')
                                    . '</span>';
                            }
                        ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 align-top">
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                <?= htmlspecialchars($startedAt, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <?php if ($jobId !== ''): ?>
                                    <a href="/crons/<?= htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') ?>"
                                       class="text-blue-600 hover:underline font-medium">
                                        <?= htmlspecialchars($jobDesc, ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($jobDesc, ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                                <?= htmlspecialchars($entryUser, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <div class="flex flex-wrap gap-1">
                                    <?php foreach ($entryTags as $tag): ?>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                            <?= htmlspecialchars((string) $tag, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                <?php if ($entryTarget !== ''): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                        <?= htmlspecialchars($entryTarget, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                <?= htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <?= $statusBadge ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 max-w-xs">
                                <?php if ($output !== ''): ?>
                                    <!-- Full output stored for copy/download (hidden) -->
                                    <span id="tl-output-<?= $idx ?>-data"
                                          class="hidden"><?= htmlspecialchars($output, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span id="tl-output-<?= $idx ?>-short"
                                          class="font-mono text-xs break-all">
                                        <?= htmlspecialchars($outputTrunc, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if ($isTruncated): ?>
                                        <span id="tl-output-<?= $idx ?>-full"
                                              class="font-mono text-xs break-all hidden">
                                            <?= htmlspecialchars($output, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <button type="button"
                                                onclick="tlToggleOutput('tl-output-<?= $idx ?>')"
                                                class="ml-1 text-xs text-blue-600 hover:underline focus:outline-none">
                                            show more
                                        </button>
                                    <?php endif; ?>
                                    <!-- Copy / Download buttons -->
                                    <div class="mt-1 flex items-center gap-1">
                                        <button type="button"
                                                onclick="tlCopyOutput('tl-output-<?= $idx ?>', this)"
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
                                                onclick="tlDownloadOutput('tl-output-<?= $idx ?>', <?= (int) $jobId ?>, '<?= htmlspecialchars(addslashes($startedAt), ENT_QUOTES, 'UTF-8') ?>')"
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
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ======================================================================
     Pagination
     ====================================================================== -->
<?php if ($hasPrev || $hasNext): ?>
    <div class="flex items-center justify-between">
        <div>
            <?php if ($hasPrev): ?>
                <a href="<?= htmlspecialchars($pageUrl($prevOffset), ENT_QUOTES, 'UTF-8') ?>"
                   class="inline-flex items-center gap-1 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300
                          hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition
                          focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    &larr; <?= htmlspecialchars($t('pagination_previous'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($hasNext): ?>
                <a href="<?= htmlspecialchars($pageUrl($nextOffset), ENT_QUOTES, 'UTF-8') ?>"
                   class="inline-flex items-center gap-1 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300
                          hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition
                          focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <?= htmlspecialchars($t('pagination_next'), ENT_QUOTES, 'UTF-8') ?> &rarr;
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
/**
 * Copy the full output of a timeline row to the clipboard.
 *
 * @param {string}      id  Base element id.
 * @param {HTMLElement} btn The button element (for visual feedback).
 */
function tlCopyOutput(id, btn) {
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
 * Download the full output of a timeline row as a plain-text log file.
 *
 * @param {string} id        Base element id.
 * @param {number} jobId     Cron job ID (used in filename).
 * @param {string} startedAt Execution start timestamp (used in filename).
 */
function tlDownloadOutput(id, jobId, startedAt) {
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
 * Toggle between truncated and full output for a timeline row.
 *
 * @param {string} id Base element id (without -short / -full suffix).
 */
function tlToggleOutput(id) {
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
