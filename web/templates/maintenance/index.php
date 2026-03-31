<?php

declare(strict_types=1);

/**
 * Cronmanager – Maintenance page template
 *
 * Variables injected by MaintenanceController::index():
 *   int                $hours            Stuck-execution threshold
 *   array              $stuckExecutions  List of stuck execution rows
 *   string|null        $stuckError       Error message if agent unreachable
 *   int|null           $flashSyncOk      Jobs synced (post-resync flash)
 *   bool               $flashSyncErr     Resync error (post-resync flash)
 *   bool               $flashResolved    Execution was marked finished
 *   bool               $flashExecDel     Execution record was deleted
 *   int|null           $flashCleaned     History records deleted (flash)
 *   int|null           $flashOnceRemoved Stale once-entries removed (flash)
 *   string             $csrf_token       CSRF token
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);
?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-8">

    <!-- Page title -->
    <h1 class="text-2xl font-bold" style="color:var(--cm-text)">
        <?= htmlspecialchars($t('maintenance_title'), ENT_QUOTES, 'UTF-8') ?>
    </h1>

    <!-- ── Flash banners ──────────────────────────────────────────────────── -->

    <?php if ($flashSyncOk !== null): ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(34,197,94,.12);color:#16a34a;border:1px solid rgba(34,197,94,.25)">
            <?= htmlspecialchars($t('maintenance_resync_success', ['synced' => $flashSyncOk]), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($flashSyncErr): ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.2)">
            <?= htmlspecialchars($t('maintenance_resync_error'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($flashResolved): ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(34,197,94,.12);color:#16a34a;border:1px solid rgba(34,197,94,.25)">
            <?= htmlspecialchars($t('maintenance_stuck_resolved'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($flashExecDel): ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(34,197,94,.12);color:#16a34a;border:1px solid rgba(34,197,94,.25)">
            <?= htmlspecialchars($t('maintenance_stuck_deleted'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($flashBulkResolved !== null): ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(34,197,94,.12);color:#16a34a;border:1px solid rgba(34,197,94,.25)">
            <?= htmlspecialchars($t('maintenance_stuck_bulk_resolved', ['count' => $flashBulkResolved]), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($flashBulkDeleted !== null): ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(34,197,94,.12);color:#16a34a;border:1px solid rgba(34,197,94,.25)">
            <?= htmlspecialchars($t('maintenance_stuck_bulk_deleted', ['count' => $flashBulkDeleted]), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($flashCleaned !== null): ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(34,197,94,.12);color:#16a34a;border:1px solid rgba(34,197,94,.25)">
            <?= htmlspecialchars($t('maintenance_cleanup_success', ['count' => $flashCleaned]), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($flashOnceRemoved !== null): ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(34,197,94,.12);color:#16a34a;border:1px solid rgba(34,197,94,.25)">
            <?= htmlspecialchars(
                $flashOnceRemoved > 0
                    ? $t('maintenance_once_success', ['count' => $flashOnceRemoved])
                    : $t('maintenance_once_none'),
                ENT_QUOTES, 'UTF-8'
            ) ?>
        </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════════
         1. CRONTAB SYNC
         ══════════════════════════════════════════════════════════════════════ -->
    <section class="cm-card rounded-xl p-6 space-y-4">

        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold" style="color:var(--cm-text)">
                    <?= htmlspecialchars($t('maintenance_resync_title'), ENT_QUOTES, 'UTF-8') ?>
                </h2>
                <p class="mt-1 text-sm" style="color:var(--cm-text-muted)">
                    <?= htmlspecialchars($t('maintenance_resync_desc'), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
            <form method="POST" action="/maintenance/resync"
                  onsubmit="return confirm(<?= htmlspecialchars(json_encode($t('maintenance_resync_confirm')), ENT_QUOTES, 'UTF-8') ?>)">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit"
                        class="flex-shrink-0 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold transition"
                        style="background:rgba(59,130,246,.1);color:var(--cm-primary);border:1px solid rgba(59,130,246,.2)">
                    <?= htmlspecialchars($t('maintenance_resync_btn'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>
        </div>

    </section>

    <!-- ══════════════════════════════════════════════════════════════════════
         2. STUCK EXECUTIONS
         ══════════════════════════════════════════════════════════════════════ -->
    <section class="cm-card rounded-xl p-6 space-y-4">

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold" style="color:var(--cm-text)">
                    <?= htmlspecialchars($t('maintenance_stuck_title'), ENT_QUOTES, 'UTF-8') ?>
                </h2>
                <p class="mt-1 text-sm" style="color:var(--cm-text-muted)">
                    <?= htmlspecialchars($t('maintenance_stuck_desc'), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

            <!-- Hours filter -->
            <form method="GET" action="/maintenance" class="flex items-center gap-2">
                <label class="text-sm whitespace-nowrap" style="color:var(--cm-text-muted)">
                    <?= htmlspecialchars($t('maintenance_stuck_hours'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input type="number" name="hours" min="1" max="720"
                       value="<?= htmlspecialchars((string) $hours, ENT_QUOTES, 'UTF-8') ?>"
                       class="w-20 border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1.5 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                <label class="text-sm" style="color:var(--cm-text-muted)">
                    <?= htmlspecialchars($t('maintenance_stuck_hours_unit'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <button type="submit"
                        class="px-3 py-1.5 rounded-lg text-sm font-medium transition cm-btn-secondary">
                    <?= htmlspecialchars($t('maintenance_stuck_refresh'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>
        </div>

        <?php if ($stuckError !== null): ?>
            <p class="text-sm px-3 py-2 rounded-lg"
               style="background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.2)">
                <?= htmlspecialchars($t('error_agent_unavailable'), ENT_QUOTES, 'UTF-8') ?>
                <span class="opacity-70">(<?= htmlspecialchars($stuckError, ENT_QUOTES, 'UTF-8') ?>)</span>
            </p>
        <?php elseif ($stuckExecutions === []): ?>
            <p class="text-sm" style="color:var(--cm-text-muted)">
                <?= htmlspecialchars($t('maintenance_stuck_none'), ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php else: ?>

            <!-- Bulk action form wraps the entire table -->
            <form id="stuck-bulk-form" method="POST" action="/maintenance/executions/bulk">
                <input type="hidden" name="_csrf"  value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="hours"  value="<?= htmlspecialchars((string) $hours, ENT_QUOTES, 'UTF-8') ?>">

                <!-- Bulk toolbar – hidden until at least one row is selected -->
                <div id="stuck-bulk-toolbar"
                     class="hidden items-center gap-3 mb-3 px-3 py-2 rounded-lg"
                     style="background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.18)">
                    <span id="stuck-bulk-count" class="text-sm font-medium"
                          style="color:var(--cm-primary)"></span>
                    <div class="flex items-center gap-2 ml-auto">
                        <button type="submit" name="_action" value="finish"
                                id="stuck-bulk-finish-btn"
                                class="px-3 py-1 rounded text-xs font-semibold transition"
                                style="background:rgba(245,158,11,.1);color:#d97706;border:1px solid rgba(245,158,11,.25)">
                            <?= htmlspecialchars($t('maintenance_stuck_bulk_resolve'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="submit" name="_action" value="delete"
                                id="stuck-bulk-delete-btn"
                                class="px-3 py-1 rounded text-xs font-semibold transition"
                                style="background:rgba(239,68,68,.08);color:#dc2626;border:1px solid rgba(239,68,68,.2)">
                            <?= htmlspecialchars($t('maintenance_stuck_bulk_delete'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="cm-table-head">
                                <!-- Select-all checkbox -->
                                <th class="px-3 py-2 w-8">
                                    <input type="checkbox" id="stuck-select-all"
                                           class="rounded cursor-pointer"
                                           title="<?= htmlspecialchars($t('select_all'), ENT_QUOTES, 'UTF-8') ?>">
                                </th>
                                <th class="px-3 py-2 text-left font-semibold">ID</th>
                                <th class="px-3 py-2 text-left font-semibold">
                                    <?= htmlspecialchars($t('cron_description'), ENT_QUOTES, 'UTF-8') ?>
                                </th>
                                <th class="px-3 py-2 text-left font-semibold">
                                    <?= htmlspecialchars($t('cron_linux_user'), ENT_QUOTES, 'UTF-8') ?>
                                </th>
                                <th class="px-3 py-2 text-left font-semibold">
                                    <?= htmlspecialchars($t('cron_host'), ENT_QUOTES, 'UTF-8') ?>
                                </th>
                                <th class="px-3 py-2 text-left font-semibold">
                                    <?= htmlspecialchars($t('started_at'), ENT_QUOTES, 'UTF-8') ?>
                                </th>
                                <th class="px-3 py-2 text-right font-semibold">
                                    <?= htmlspecialchars($t('duration'), ENT_QUOTES, 'UTF-8') ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stuckExecutions as $exec): ?>
                            <?php
                                $execId        = (int) ($exec['id']               ?? 0);
                                $jobId         = (int) ($exec['job_id']           ?? 0);
                                $mins          = (int) ($exec['duration_minutes'] ?? 0);
                                $durationLabel = $mins >= 60
                                    ? sprintf('%dh %dm', intdiv($mins, 60), $mins % 60)
                                    : sprintf('%dm', $mins);
                            ?>
                            <tr class="cm-table-row stuck-row">
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="ids[]"
                                           value="<?= $execId ?>"
                                           class="stuck-cb rounded cursor-pointer">
                                </td>
                                <td class="px-3 py-2 font-mono text-xs" style="color:var(--cm-text-muted)">
                                    #<?= $execId ?>
                                </td>
                                <td class="px-3 py-2">
                                    <a href="/crons/<?= $jobId ?>"
                                       class="font-medium hover:underline" style="color:var(--cm-primary)">
                                        <?= htmlspecialchars((string) ($exec['description'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                    <div class="text-xs mt-0.5 font-mono truncate max-w-xs"
                                         style="color:var(--cm-text-muted)">
                                        <?= htmlspecialchars((string) ($exec['command'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-xs font-mono">
                                    <?= htmlspecialchars((string) ($exec['linux_user'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <?= htmlspecialchars((string) ($exec['target'] ?? 'local'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="px-3 py-2 text-xs" style="color:var(--cm-text-muted)">
                                    <?= htmlspecialchars((string) ($exec['started_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="px-3 py-2 text-right text-xs font-semibold"
                                    style="color:#f59e0b">
                                    <?= htmlspecialchars($durationLabel, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form><!-- /#stuck-bulk-form -->

        <?php endif; ?>

    </section>

    <!-- ══════════════════════════════════════════════════════════════════════
         3. HISTORY CLEANUP
         ══════════════════════════════════════════════════════════════════════ -->
    <section class="cm-card rounded-xl p-6 space-y-4">

        <div>
            <h2 class="text-lg font-semibold" style="color:var(--cm-text)">
                <?= htmlspecialchars($t('maintenance_cleanup_title'), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <p class="mt-1 text-sm" style="color:var(--cm-text-muted)">
                <?= htmlspecialchars($t('maintenance_cleanup_desc'), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <form method="POST" action="/maintenance/history/cleanup"
              id="cleanup-form"
              onsubmit="return confirmCleanup(this)">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

            <div class="flex flex-wrap items-end gap-3">
                <div class="flex items-center gap-2">
                    <label for="older_than_days" class="text-sm whitespace-nowrap"
                           style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('maintenance_cleanup_older_than'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="number" id="older_than_days" name="older_than_days"
                           min="1" max="3650" value="90"
                           class="w-24 border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1.5 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <span class="text-sm" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('maintenance_cleanup_days'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <button type="submit"
                        class="px-4 py-2 rounded-lg text-sm font-semibold transition"
                        style="background:rgba(239,68,68,.08);color:#dc2626;border:1px solid rgba(239,68,68,.2)">
                    <?= htmlspecialchars($t('maintenance_cleanup_btn'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>

    </section>

    <!-- ══════════════════════════════════════════════════════════════════════
         4. RUN NOW CLEANUP
         ══════════════════════════════════════════════════════════════════════ -->
    <section class="cm-card rounded-xl p-6 space-y-4">

        <div>
            <h2 class="text-lg font-semibold" style="color:var(--cm-text)">
                <?= htmlspecialchars($t('maintenance_once_title'), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <p class="mt-1 text-sm" style="color:var(--cm-text-muted)">
                <?= htmlspecialchars($t('maintenance_once_desc'), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <form method="post" action="/maintenance/once/cleanup"
              id="once-cleanup-form"
              onsubmit="return confirmOnceCleanup()">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit"
                    class="px-4 py-2 rounded-lg text-sm font-semibold transition"
                    style="background:rgba(239,68,68,.08);color:#dc2626;border:1px solid rgba(239,68,68,.2)">
                <?= htmlspecialchars($t('maintenance_once_btn'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </form>

    </section>

</div>

<script>
// ── Stuck executions: bulk selection ──────────────────────────────────────────

(function () {
    const selectAll = document.getElementById('stuck-select-all');
    const toolbar   = document.getElementById('stuck-bulk-toolbar');
    const countEl   = document.getElementById('stuck-bulk-count');
    const finishBtn = document.getElementById('stuck-bulk-finish-btn');
    const deleteBtn = document.getElementById('stuck-bulk-delete-btn');

    // i18n strings rendered server-side
    const i18n = {
        selected:       <?= json_encode($t('maintenance_stuck_selected')) ?>,
        confirmFinish:  <?= json_encode($t('maintenance_stuck_bulk_resolve_confirm')) ?>,
        confirmDelete:  <?= json_encode($t('maintenance_stuck_bulk_delete_confirm')) ?>,
    };

    if (!selectAll) return; // table not present (no stuck executions)

    function getChecked() {
        return Array.from(document.querySelectorAll('.stuck-cb:checked'));
    }

    function updateToolbar() {
        const checked = getChecked();
        const n = checked.length;
        if (n > 0) {
            countEl.textContent = i18n.selected.replace('{count}', n);
            toolbar.classList.remove('hidden');
            toolbar.classList.add('flex');
        } else {
            toolbar.classList.add('hidden');
            toolbar.classList.remove('flex');
        }
        // Update indeterminate state of select-all
        const all = document.querySelectorAll('.stuck-cb');
        selectAll.indeterminate = n > 0 && n < all.length;
        selectAll.checked = n > 0 && n === all.length;
    }

    // Select-all toggle
    selectAll.addEventListener('change', function () {
        document.querySelectorAll('.stuck-cb').forEach(cb => {
            cb.checked = selectAll.checked;
        });
        updateToolbar();
    });

    // Individual checkboxes
    document.querySelectorAll('.stuck-cb').forEach(cb => {
        cb.addEventListener('change', updateToolbar);
    });

    // Bulk action confirmations
    finishBtn.addEventListener('click', function (e) {
        const n = getChecked().length;
        if (!confirm(i18n.confirmFinish.replace('{count}', n))) {
            e.preventDefault();
        }
    });

    deleteBtn.addEventListener('click', function (e) {
        const n = getChecked().length;
        if (!confirm(i18n.confirmDelete.replace('{count}', n))) {
            e.preventDefault();
        }
    });
}());

// ── History cleanup confirmation ───────────────────────────────────────────────

function confirmCleanup(form) {
    const days = parseInt(form.older_than_days.value, 10) || 90;
    const msg  = <?= json_encode($t('maintenance_cleanup_confirm')) ?>
        .replace('{days}', days);
    return confirm(msg);
}

// ── Run Now cleanup confirmation ───────────────────────────────────────────────

function confirmOnceCleanup() {
    return confirm(<?= json_encode($t('maintenance_once_confirm')) ?>);
}
</script>
