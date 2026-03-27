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

    <?php if ($flashCleaned !== null): ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(34,197,94,.12);color:#16a34a;border:1px solid rgba(34,197,94,.25)">
            <?= htmlspecialchars($t('maintenance_cleanup_success', ['count' => $flashCleaned]), ENT_QUOTES, 'UTF-8') ?>
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
                       class="w-20 px-2 py-1.5 rounded-lg text-sm cm-input">
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
            </p>
        <?php elseif ($stuckExecutions === []): ?>
            <p class="text-sm" style="color:var(--cm-text-muted)">
                <?= htmlspecialchars($t('maintenance_stuck_none'), ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="cm-table-head">
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
                            <th class="px-3 py-2 text-right font-semibold">
                                <?= htmlspecialchars($t('actions'), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stuckExecutions as $exec): ?>
                        <?php
                            $execId  = (int) ($exec['id']               ?? 0);
                            $jobId   = (int) ($exec['job_id']           ?? 0);
                            $mins    = (int) ($exec['duration_minutes'] ?? 0);
                            $durationLabel = $mins >= 60
                                ? sprintf('%dh %dm', intdiv($mins, 60), $mins % 60)
                                : sprintf('%dm', $mins);
                        ?>
                        <tr class="cm-table-row">
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
                            <td class="px-3 py-2 text-right">
                                <div class="inline-flex items-center gap-2">
                                    <!-- Mark as Finished -->
                                    <form method="POST"
                                          action="/maintenance/executions/<?= $execId ?>/finish"
                                          onsubmit="return confirm(<?= htmlspecialchars(json_encode($t('maintenance_stuck_resolve_confirm')), ENT_QUOTES, 'UTF-8') ?>)">
                                        <input type="hidden" name="_csrf"
                                               value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="hours"
                                               value="<?= htmlspecialchars((string) $hours, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit"
                                                class="px-2 py-1 rounded text-xs font-semibold transition"
                                                style="background:rgba(245,158,11,.1);color:#d97706;border:1px solid rgba(245,158,11,.25)">
                                            <?= htmlspecialchars($t('maintenance_stuck_resolve'), ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </form>
                                    <!-- Delete -->
                                    <form method="POST"
                                          action="/maintenance/executions/<?= $execId ?>/delete"
                                          onsubmit="return confirm(<?= htmlspecialchars(json_encode($t('maintenance_stuck_delete_confirm')), ENT_QUOTES, 'UTF-8') ?>)">
                                        <input type="hidden" name="_csrf"
                                               value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="hours"
                                               value="<?= htmlspecialchars((string) $hours, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit"
                                                class="px-2 py-1 rounded text-xs font-semibold transition"
                                                style="background:rgba(239,68,68,.08);color:#dc2626;border:1px solid rgba(239,68,68,.2)">
                                            <?= htmlspecialchars($t('maintenance_stuck_delete'), ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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
                           class="w-24 px-2 py-1.5 rounded-lg text-sm cm-input">
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

</div>

<script>
function confirmCleanup(form) {
    const days = parseInt(form.older_than_days.value, 10) || 90;
    const msg  = <?= json_encode($t('maintenance_cleanup_confirm')) ?>
        .replace('{days}', days);
    return confirm(msg);
}
</script>
