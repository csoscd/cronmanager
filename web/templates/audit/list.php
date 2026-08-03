<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Audit Log Template
 *
 * Displays a paginated, filterable list of audit log entries.
 *
 * Variables injected by AuditController::index():
 *   array  $entries      – audit log rows for the current page
 *   int    $total        – total matching entries
 *   int    $page         – current page number
 *   int    $lastPage     – last page number
 *   int    $pageSize     – entries per page
 *   string $username     – current username filter value
 *   string $actionPrefix – current action-prefix filter value
 *   string $dateFrom     – current date-from filter value
 *   string $dateTo       – current date-to filter value
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$entries      = isset($entries)      && is_array($entries) ? $entries : [];
$total        = isset($total)        ? (int) $total        : 0;
$page         = isset($page)         ? (int) $page         : 1;
$lastPage     = isset($lastPage)     ? (int) $lastPage     : 1;
$pageSize     = isset($pageSize)     ? (int) $pageSize     : 25;
$username     = isset($username)     ? (string) $username  : '';
$actionPrefix = isset($actionPrefix) ? (string) $actionPrefix : '';
$dateFrom     = isset($dateFrom)     ? (string) $dateFrom  : '';
$dateTo       = isset($dateTo)       ? (string) $dateTo    : '';

$showFrom = $total === 0 ? 0 : ($page - 1) * $pageSize + 1;
$showTo   = min($page * $pageSize, $total);

$agentId  = isset($agentId) ? (int) $agentId : 0;
$agSuffix = $agentId > 0 ? '?agent_id=' . $agentId : '';

/** Build a pagination URL preserving current filters. */
$pageUrl = static function (int $targetPage) use ($username, $actionPrefix, $dateFrom, $dateTo, $pageSize, $agentId): string {
    return '/audit?' . http_build_query(array_filter([
        'agent_id'      => $agentId > 0 ? (string) $agentId : '',
        'page'          => (string) $targetPage,
        'username'      => $username,
        'action_prefix' => $actionPrefix,
        'date_from'     => $dateFrom,
        'date_to'       => $dateTo,
    ], static fn ($v): bool => $v !== ''));
};

/** Map an action string to a colour-coded badge CSS class. */
$actionBadge = static function (string $action): string {
    if (str_starts_with($action, 'cron.bulk')) {
        return 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200';
    }
    if (str_starts_with($action, 'cron')) {
        return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
    }
    if (str_starts_with($action, 'tag')) {
        return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
    }
    if (str_starts_with($action, 'maintenance_window')) {
        return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
    }
    return 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
};
?>

<!-- Page header -->
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
        <?= $h($t('nav_audit_log')) ?>
    </h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        <?= $h($t('audit_subtitle')) ?>
    </p>
</div>

<!-- Filter bar -->
<form method="get" action="/audit" class="mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
    <input type="text"
           name="username"
           value="<?= $h($username) ?>"
           placeholder="<?= $h($t('audit_filter_username')) ?>"
           class="block w-full rounded-lg border border-gray-300 dark:border-gray-600
                  bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                  px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">

    <select name="action_prefix"
            class="block w-full rounded-lg border border-gray-300 dark:border-gray-600
                   bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                   px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        <option value=""><?= $h($t('audit_filter_all_actions')) ?></option>
        <option value="cron"<?= $actionPrefix === 'cron' ? ' selected' : '' ?>><?= $h($t('audit_category_cron')) ?></option>
        <option value="tag"<?= $actionPrefix === 'tag' ? ' selected' : '' ?>><?= $h($t('audit_category_tag')) ?></option>
        <option value="maintenance_window"<?= $actionPrefix === 'maintenance_window' ? ' selected' : '' ?>><?= $h($t('audit_category_maintenance_window')) ?></option>
    </select>

    <input type="datetime-local"
           name="date_from"
           value="<?= $h($dateFrom) ?>"
           placeholder="<?= $h($t('audit_filter_date_from')) ?>"
           class="block w-full rounded-lg border border-gray-300 dark:border-gray-600
                  bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                  px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">

    <input type="datetime-local"
           name="date_to"
           value="<?= $h($dateTo) ?>"
           placeholder="<?= $h($t('audit_filter_date_to')) ?>"
           class="block w-full rounded-lg border border-gray-300 dark:border-gray-600
                  bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                  px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">

    <div class="sm:col-span-2 lg:col-span-4 flex gap-2">
        <button type="submit"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition
                       focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            <?= $h($t('audit_apply_filter')) ?>
        </button>
        <?php if ($username !== '' || $actionPrefix !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
            <a href="/audit<?= $agSuffix ?>"
               class="px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600
                      text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg transition">
                <?= $h($t('audit_reset_filter')) ?>
            </a>
        <?php endif; ?>
    </div>
</form>

<!-- Entry count -->
<?php if ($total > 0): ?>
    <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">
        <?= $h($t('pagination_showing', ['from' => (string) $showFrom, 'to' => (string) $showTo, 'total' => (string) $total])) ?>
    </p>
<?php endif; ?>

<!-- Table -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">

    <?php if (empty($entries)): ?>
        <div class="px-6 py-12 text-center text-gray-400 dark:text-gray-500 text-sm">
            <?= $h($t('audit_empty')) ?>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-36">
                            <?= $h($t('audit_col_timestamp')) ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= $h($t('audit_col_user')) ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= $h($t('audit_col_action')) ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= $h($t('audit_col_resource')) ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= $h($t('audit_col_details')) ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-32">
                            <?= $h($t('audit_col_ip')) ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($entries as $entry): ?>
                        <?php
                            $entryId    = (int)    ($entry['id']             ?? 0);
                            $entryUser  = (string) ($entry['username']       ?? '');
                            $entryAction = (string) ($entry['action']        ?? '');
                            $resType    = (string) ($entry['resource_type']  ?? '');
                            $resId      = $entry['resource_id']    !== null ? (int) $entry['resource_id'] : null;
                            $resLabel   = (string) ($entry['resource_label'] ?? '');
                            $details    = $entry['details'];
                            $ip         = (string) ($entry['ip_address']     ?? '');
                            $createdAt  = (string) ($entry['created_at']     ?? '');
                        ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <!-- Timestamp -->
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap font-mono">
                                <?= $h($createdAt) ?>
                            </td>

                            <!-- User -->
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                <?= $h($entryUser) ?>
                            </td>

                            <!-- Action badge -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $h($actionBadge($entryAction)) ?>">
                                    <?= $h($entryAction) ?>
                                </span>
                            </td>

                            <!-- Resource -->
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                <?php if ($resType !== ''): ?>
                                    <span class="text-gray-400 dark:text-gray-500 text-xs"><?= $h($resType) ?><?= $resId !== null ? ' #' . $resId : '' ?></span>
                                <?php endif; ?>
                                <?php if ($resLabel !== ''): ?>
                                    <div class="truncate max-w-xs" title="<?= $h($resLabel) ?>"><?= $h($resLabel) ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- Details -->
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                <?php if (is_array($details) && $details !== []): ?>
                                    <ul class="list-none space-y-0.5">
                                        <?php foreach ($details as $k => $v): ?>
                                            <li class="text-xs">
                                                <span class="font-medium text-gray-700 dark:text-gray-300"><?= $h((string) $k) ?>:</span>
                                                <?= $h(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>

                            <!-- IP -->
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 font-mono whitespace-nowrap">
                                <?= $h($ip) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($lastPage > 1): ?>
<div class="mt-4 flex items-center justify-between">

    <!-- Previous -->
    <div>
        <?php if ($page > 1): ?>
            <a href="<?= $h($pageUrl($page - 1)) ?>"
               class="inline-flex items-center gap-1 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                      text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700
                      text-sm font-medium px-4 py-2 rounded-lg transition">
                &larr; <?= $h($t('pagination_previous')) ?>
            </a>
        <?php else: ?>
            <span class="inline-flex items-center gap-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                         text-gray-300 dark:text-gray-600 text-sm font-medium px-4 py-2 rounded-lg cursor-default">
                &larr; <?= $h($t('pagination_previous')) ?>
            </span>
        <?php endif; ?>
    </div>

    <!-- Page numbers (sliding window) -->
    <div class="flex items-center gap-1">
        <?php
        $windowStart = max(1, $page - 3);
        $windowEnd   = min($lastPage, $windowStart + 6);
        $windowStart = max(1, $windowEnd - 6);
        if ($windowStart > 1): ?>
            <a href="<?= $h($pageUrl(1)) ?>"
               class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm border border-gray-300 dark:border-gray-600
                      bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                      hover:bg-gray-50 dark:hover:bg-gray-700 transition">1</a>
            <?php if ($windowStart > 2): ?>
                <span class="text-gray-400 dark:text-gray-500 px-1">…</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
            <?php if ($p === $page): ?>
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm font-semibold
                             bg-blue-600 text-white border border-blue-600"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= $h($pageUrl($p)) ?>"
                   class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm border border-gray-300 dark:border-gray-600
                          bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                          hover:bg-gray-50 dark:hover:bg-gray-700 transition"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($windowEnd < $lastPage): ?>
            <?php if ($windowEnd < $lastPage - 1): ?>
                <span class="text-gray-400 dark:text-gray-500 px-1">…</span>
            <?php endif; ?>
            <a href="<?= $h($pageUrl($lastPage)) ?>"
               class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm border border-gray-300 dark:border-gray-600
                      bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                      hover:bg-gray-50 dark:hover:bg-gray-700 transition"><?= $lastPage ?></a>
        <?php endif; ?>
    </div>

    <!-- Next -->
    <div>
        <?php if ($page < $lastPage): ?>
            <a href="<?= $h($pageUrl($page + 1)) ?>"
               class="inline-flex items-center gap-1 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                      text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700
                      text-sm font-medium px-4 py-2 rounded-lg transition">
                <?= $h($t('pagination_next')) ?> &rarr;
            </a>
        <?php else: ?>
            <span class="inline-flex items-center gap-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                         text-gray-300 dark:text-gray-600 text-sm font-medium px-4 py-2 rounded-lg cursor-default">
                <?= $h($t('pagination_next')) ?> &rarr;
            </span>
        <?php endif; ?>
    </div>

</div>
<?php endif; ?>
