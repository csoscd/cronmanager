<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Job Transfer Template
 *
 * Preview and confirmation page for transferring cron jobs between agents.
 *
 * Variables available in this template:
 *   array  $jobs         – job arrays keyed by job ID (from source agent)
 *   array  $destAgents   – enabled agents excluding the source (id, name, url)
 *   array  $warnings     – map of job-ID string → array of missing target names
 *   array  $sourceAgent  – current (source) agent row
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

$jobs        = isset($jobs)        && is_array($jobs)        ? $jobs        : [];
$destAgents  = isset($destAgents)  && is_array($destAgents)  ? $destAgents  : [];
$warnings    = isset($warnings)    && is_array($warnings)    ? $warnings    : [];
$sourceAgent = isset($sourceAgent) && is_array($sourceAgent) ? $sourceAgent : [];

$jobIds      = array_map('intval', array_keys($jobs));
$firstDest   = $destAgents[0] ?? null;

?>

<!-- Page header -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
            <?= htmlspecialchars($t('transfer_title'), ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            <?= htmlspecialchars(
                str_replace('{n}', (string) count($jobs), $t('transfer_jobs_selected')),
                ENT_QUOTES, 'UTF-8'
            ) ?>
            &mdash;
            <?= htmlspecialchars($t('transfer_source'), ENT_QUOTES, 'UTF-8') ?>:
            <span class="font-medium"><?= htmlspecialchars((string) ($sourceAgent['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
        </p>
    </div>
    <a href="/crons"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium
              border border-gray-300 dark:border-gray-600
              text-gray-700 dark:text-gray-300
              hover:bg-gray-50 dark:hover:bg-gray-700 transition">
        &larr; <?= htmlspecialchars($t('cancel'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<!-- Transfer form -->
<form method="POST" action="/crons/transfer" id="cm-transfer-form">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
    <?php foreach ($jobIds as $jid): ?>
        <input type="hidden" name="ids[]" value="<?= $jid ?>">
    <?php endforeach; ?>

    <!-- Options row -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">

        <!-- Destination agent selector -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <label for="destination_agent_id"
                   class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                <?= htmlspecialchars($t('transfer_destination'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="destination_agent_id" name="destination_agent_id"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                           focus:outline-none focus:ring-2 focus:ring-blue-500">
                <?php foreach ($destAgents as $da): ?>
                    <option value="<?= (int) $da['id'] ?>">
                        <?= htmlspecialchars((string) $da['name'], ENT_QUOTES, 'UTF-8') ?>
                        (<?= htmlspecialchars((string) $da['url'], ENT_QUOTES, 'UTF-8') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Source action selector -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                <?= htmlspecialchars($t('transfer_source_action'), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <div class="flex flex-col gap-2">
                <?php foreach (['keep' => 'transfer_keep', 'deactivate' => 'transfer_deactivate', 'delete' => 'transfer_delete'] as $val => $key): ?>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                        <input type="radio" name="source_action" value="<?= $val ?>"
                               <?= $val === 'keep' ? 'checked' : '' ?>
                               class="accent-blue-600">
                        <?= htmlspecialchars($t($key), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Jobs table -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900/30">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        <?= htmlspecialchars($t('cron_description'), ENT_QUOTES, 'UTF-8') ?>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        <?= htmlspecialchars($t('cron_schedule'), ENT_QUOTES, 'UTF-8') ?>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        <?= htmlspecialchars($t('cron_targets'), ENT_QUOTES, 'UTF-8') ?>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        <?= htmlspecialchars($t('transfer_compatibility'), ENT_QUOTES, 'UTF-8') ?>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($jobs as $jobId => $job): ?>
                    <?php
                    $missing   = $warnings[(string) $jobId] ?? [];
                    $hasWarn   = $missing !== [];
                    $targets   = is_array($job['targets'] ?? null) ? $job['targets'] : [];
                    $tags      = is_array($job['tags']    ?? null) ? $job['tags']    : [];
                    ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition"
                        id="cm-transfer-row-<?= $jobId ?>">

                        <!-- Description + command -->
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900 dark:text-gray-100">
                                <?= htmlspecialchars((string) ($job['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 font-mono truncate max-w-xs" title="<?= htmlspecialchars((string) ($job['command'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars((string) ($job['command'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <?php if ($tags !== []): ?>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <?php foreach ($tags as $tag): ?>
                                        <span class="px-1.5 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                            <?= htmlspecialchars((string) $tag, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Schedule -->
                        <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-300 whitespace-nowrap">
                            <?= htmlspecialchars((string) ($job['schedule'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <!-- Targets -->
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <?php foreach ($targets as $tgt): ?>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                                 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                                        <?= htmlspecialchars((string) $tgt, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>

                        <!-- Compatibility status -->
                        <td class="px-4 py-3" id="cm-compat-<?= $jobId ?>">
                            <?php if (!$hasWarn): ?>
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-green-700 dark:text-green-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    <?= htmlspecialchars($t('transfer_target_ok'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php else: ?>
                                <div class="text-xs text-amber-700 dark:text-amber-400">
                                    <div class="font-medium mb-1">
                                        <?= htmlspecialchars($t('transfer_warning_targets'), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <?php foreach ($missing as $m): ?>
                                        <div class="flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12A9 9 0 113 12a9 9 0 0118 0z"/>
                                            </svg>
                                            <?= htmlspecialchars(
                                                str_replace('{target}', (string) $m, $t('transfer_target_missing')),
                                                ENT_QUOTES, 'UTF-8'
                                            ) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Action buttons -->
    <div class="flex items-center justify-end gap-3">
        <a href="/crons"
           class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-300 dark:border-gray-600
                  text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
            <?= htmlspecialchars($t('cancel'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <button type="submit"
                class="px-5 py-2 rounded-lg text-sm font-semibold bg-blue-600 hover:bg-blue-700 text-white transition shadow-sm">
            <?= htmlspecialchars($t('transfer_btn'), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
</form>

<script>
(function () {
    'use strict';

    var destSelect = document.getElementById('destination_agent_id');
    var jobIds     = <?= json_encode($jobIds, JSON_UNESCAPED_UNICODE) ?>;
    var csrfToken  = <?= json_encode($csrf_token) ?>;

    var LABEL_OK      = <?= json_encode($t('transfer_target_ok')) ?>;
    var LABEL_WARN    = <?= json_encode($t('transfer_warning_targets')) ?>;
    var LABEL_MISSING = <?= json_encode($t('transfer_target_missing')) ?>;

    function renderCompat(cell, missing) {
        if (!missing || missing.length === 0) {
            cell.innerHTML =
                '<span class="inline-flex items-center gap-1 text-xs font-medium text-green-700 dark:text-green-400">'
                + '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                + '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>'
                + '</svg>'
                + escHtml(LABEL_OK)
                + '</span>';
            return;
        }

        var html = '<div class="text-xs text-amber-700 dark:text-amber-400">'
                 + '<div class="font-medium mb-1">' + escHtml(LABEL_WARN) + '</div>';

        missing.forEach(function (m) {
            html += '<div class="flex items-center gap-1">'
                  + '<svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                  + '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12A9 9 0 113 12a9 9 0 0118 0z"/>'
                  + '</svg>'
                  + escHtml(LABEL_MISSING.replace('{target}', m))
                  + '</div>';
        });

        html += '</div>';
        cell.innerHTML = html;
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    if (destSelect) {
        destSelect.addEventListener('change', function () {
            var destId = parseInt(this.value, 10);
            if (!destId) { return; }

            // Build form-encoded body so the Router's CSRF check passes
            var params = new URLSearchParams();
            params.append('_csrf', csrfToken);
            params.append('destination_agent_id', String(destId));
            jobIds.forEach(function (id) { params.append('job_ids[]', String(id)); });

            cmFetch('/crons/transfer/validate', {
                method:      'POST',
                credentials: 'same-origin',
                body:        params,
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var warnings = data.warnings || {};
                jobIds.forEach(function (id) {
                    var cell = document.getElementById('cm-compat-' + id);
                    if (cell) {
                        renderCompat(cell, warnings[String(id)] || []);
                    }
                });
            })
            .catch(function () {
                // Silently ignore validation errors
            });
        });
    }
}());
</script>
