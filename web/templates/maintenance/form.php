<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Maintenance Window Create / Edit Form
 *
 * Variables available:
 *   array       $window     – current window data (for edit) or defaults (for new)
 *   bool        $isEdit     – true when editing, false when creating
 *   string|null $error      – error message to display, or null
 *   string      $formAction – URL to POST the form to
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

/** @var callable(string): string $h */
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$window     = isset($window)     && is_array($window) ? $window : [];
$isEdit     = isset($isEdit)     && (bool) $isEdit;
$error      = isset($error)      && is_string($error) && $error !== '' ? $error : null;
$formAction = isset($formAction) ? (string) $formAction : '/maintenance';

$schedule    = (string) ($window['cron_schedule']    ?? '');
$duration    = (string) ($window['duration_minutes'] ?? '60');
$description = (string) ($window['description']      ?? '');
$target      = (string) ($window['target']           ?? '');
$active      = !isset($window['active']) || (bool) $window['active'];

$isAgentTarget  = ($target === '_agent_');
$displayTarget  = $isAgentTarget ? $t('maintenance_agent_target_name') : $target;

/** @var string $csrf_token */
?>

<!-- Breadcrumb -->
<nav class="mb-4 text-sm text-gray-500 dark:text-gray-400">
    <a href="/maintenance" class="hover:text-gray-700 dark:hover:text-gray-200">
        <?= $h($t('nav_maintenance')) ?>
    </a>
    <span class="mx-2">›</span>
    <?= $h($t($isEdit ? 'target_window_edit' : 'target_window_new')) ?>
</nav>

<div class="max-w-xl">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">
        <?= $h($t($isEdit ? 'target_window_edit' : 'target_window_new')) ?>
    </h1>

    <?php if ($error !== null): ?>
        <div class="mb-4 p-4 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 text-sm text-red-700 dark:text-red-400">
            <?= $h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($isAgentTarget): ?>
        <div class="mb-5 flex items-start gap-2 p-4 rounded-lg border border-purple-200 dark:border-purple-700 bg-purple-50 dark:bg-purple-900/20 text-sm text-purple-800 dark:text-purple-300">
            <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span><?= $h($t('maintenance_agent_target_desc')) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= $h($formAction) ?>" class="space-y-5">
        <input type="hidden" name="_csrf" value="<?= $h($csrf_token) ?>">

        <!-- Target (always read-only; set via URL for new, from DB for edit) -->
        <div>
            <label for="target" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                <?= $h($t('targets_window_form_target')) ?>
            </label>
            <input type="hidden" name="target" value="<?= $h($target) ?>">
            <input type="text"
                   id="target"
                   value="<?= $h($displayTarget) ?>"
                   readonly
                   class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-3 py-2 text-sm cursor-not-allowed<?= $isAgentTarget ? ' font-medium text-purple-700 dark:text-purple-300' : '' ?>">
        </div>

        <!-- Cron schedule -->
        <div>
            <label for="cron_schedule" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                <?= $h($t('targets_window_form_schedule')) ?>
            </label>
            <input type="text"
                   id="cron_schedule"
                   name="cron_schedule"
                   value="<?= $h($schedule) ?>"
                   placeholder="0 2 * * *"
                   required
                   class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-400">
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Standard 5-field cron expression defining when the maintenance window starts.
                E.g. <code class="font-mono">0 2 * * *</code> = every day at 02:00.
            </p>
        </div>

        <!-- Duration -->
        <div>
            <label for="duration_minutes" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                <?= $h($t('targets_window_form_duration')) ?>
            </label>
            <input type="number"
                   id="duration_minutes"
                   name="duration_minutes"
                   value="<?= $h($duration) ?>"
                   min="1"
                   max="10080"
                   required
                   class="w-40 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-400">
        </div>

        <!-- Description -->
        <div>
            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                <?= $h($t('targets_window_form_desc')) ?>
            </label>
            <input type="text"
                   id="description"
                   name="description"
                   value="<?= $h($description) ?>"
                   class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-400">
        </div>

        <!-- Active toggle -->
        <div class="flex items-center gap-3">
            <input type="hidden" name="active" value="0">
            <input type="checkbox"
                   id="active"
                   name="active"
                   value="1"
                   <?= $active ? 'checked' : '' ?>
                   class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-400">
            <label for="active" class="text-sm text-gray-700 dark:text-gray-300">
                <?= $h($t('targets_window_form_active')) ?>
            </label>
        </div>

        <!-- Buttons -->
        <div class="flex items-center gap-3 pt-2">
            <button type="submit"
                    class="inline-flex items-center px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition-colors">
                <?= $h($t('targets_window_form_save')) ?>
            </button>
            <a href="/maintenance"
               class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors">
                <?= $h($t('targets_window_form_cancel')) ?>
            </a>
        </div>
    </form>
</div>
