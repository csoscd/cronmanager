<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Cron Job Create / Edit Form Template
 *
 * Used for both creating a new job (isEdit=false, job=null) and editing an
 * existing one (isEdit=true, job=array).
 *
 * Variables available in this template:
 *   array|null  $job    – existing job data (null for create)
 *   array       $tags   – all known tags (for hint hints below the tags input)
 *   string|null $error  – error message string to display, or null
 *   bool        $isEdit – true when editing an existing job
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

$job             = isset($job)             && is_array($job)             ? $job             : null;
$tags            = isset($tags)            && is_array($tags)            ? $tags            : [];
$sshHosts        = isset($sshHosts)        && is_array($sshHosts)        ? $sshHosts        : [];
$selectedTargets = isset($selectedTargets) && is_array($selectedTargets) ? $selectedTargets : ['local'];
$sshHostsByUser  = isset($sshHostsByUser)  && is_string($sshHostsByUser) ? $sshHostsByUser  : '{}';
$error           = isset($error)           && $error !== null            ? (string) $error  : null;
$isEdit          = isset($isEdit)          && (bool) $isEdit;
$isCopy          = isset($isCopy)          && (bool) $isCopy;
$returnUrl       = isset($returnUrl)       && is_string($returnUrl) ? $returnUrl : '';

// Pre-fill form values from job data or from re-submitted POST data
$val = static function (string $field, mixed $default = '') use ($job): string {
    if ($job !== null && isset($job[$field])) {
        $v = $job[$field];
        if (is_array($v)) {
            return implode(', ', $v);
        }
        return (string) $v;
    }
    return (string) $default;
};

// When copying, treat the form as a create (no job ID, action = POST /crons)
$jobId         = ($isEdit && !$isCopy) ? (string) ($job['id'] ?? '') : '';
$formAction    = ($isEdit && $jobId !== '') ? '/crons/' . rawurlencode($jobId) . '/edit' : '/crons';
$isActiveVal   = $job !== null ? !empty($job['active']) : true;
$isNotifyVal   = $job !== null ? !empty($job['notify_on_failure']) : true;
$isAutoKillVal = $job !== null ? !empty($job['auto_kill_on_limit']) : false;
$pageTitle     = $isEdit ? $t('cron_edit') : $t('cron_add');

// Collect existing tag names for click-to-insert hints
$tagHints = [];
foreach ($tags as $tag) {
    $name = (string) ($tag['name'] ?? $tag);
    if ($name !== '') {
        $tagHints[] = $name;
    }
}
?>

<!-- ======================================================================
     Page header
     ====================================================================== -->
<div class="mb-6">
    <a href="<?= $isEdit && $jobId !== '' ? '/crons/' . htmlspecialchars(rawurlencode($jobId), ENT_QUOTES, 'UTF-8') : '/crons' ?>"
       class="inline-flex items-center text-sm text-blue-600 hover:underline mb-3">
        &larr; <?= htmlspecialchars($t('back'), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
        <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>
    </h1>
</div>

<div class="max-w-2xl">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">

        <!-- Copy notice -->
        <?php if ($isCopy): ?>
            <div class="mb-5 flex items-start gap-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 text-blue-700 dark:text-blue-300
                        rounded-lg px-4 py-3 text-sm" role="note">
                <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-blue-500" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-4 10h6a2 2 0 002-2v-8a2 2 0 00-2-2h-6a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
                <span><?= htmlspecialchars($t('cron_copy_notice'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <!-- Error message -->
        <?php if ($error !== null): ?>
            <div class="mb-5 flex items-start gap-3 bg-red-50 border border-red-300 text-red-700
                        rounded-lg px-4 py-3 text-sm" role="alert">
                <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-red-500" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" novalidate>
            <input type="hidden" name="_csrf"   value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($returnUrl !== ''): ?>
            <input type="hidden" name="_return" value="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <!-- Linux User -->
            <div class="mb-4">
                <label for="linux_user"
                       class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= htmlspecialchars($t('cron_linux_user'), ENT_QUOTES, 'UTF-8') ?>
                    <span class="text-red-500">*</span>
                </label>
                <input type="text" id="linux_user" name="linux_user" required
                       value="<?= htmlspecialchars($val('linux_user'), ENT_QUOTES, 'UTF-8') ?>"
                       class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                              bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                              focus:outline-none focus:ring-2 focus:ring-blue-500
                              focus:border-blue-500 transition"
                       placeholder="root">
            </div>

            <!-- Execution Targets -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= htmlspecialchars($t('cron_targets'), ENT_QUOTES, 'UTF-8') ?>
                    <span class="text-red-500">*</span>
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">
                    <?= htmlspecialchars($t('cron_targets_hint'), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <div id="targets-container" class="flex flex-wrap gap-4">

                    <!-- Local (always visible) -->
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="targets[]" value="local"
                               id="target-local"
                               <?= in_array('local', $selectedTargets, true) ? 'checked' : '' ?>
                               class="w-4 h-4 text-gray-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer">
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            <?= htmlspecialchars($t('cron_exec_local'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </label>

                    <!-- SSH host checkboxes (pre-rendered for edit form, built by JS for create) -->
                    <?php foreach ($sshHosts as $host): ?>
                        <?php $hostStr = (string) $host; ?>
                        <label class="flex items-center gap-2 cursor-pointer ssh-target-label">
                            <input type="checkbox" name="targets[]"
                                   value="<?= htmlspecialchars($hostStr, ENT_QUOTES, 'UTF-8') ?>"
                                   <?= in_array($hostStr, $selectedTargets, true) ? 'checked' : '' ?>
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer">
                            <span class="text-sm font-mono text-gray-700 dark:text-gray-300">
                                <?= htmlspecialchars($hostStr, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </label>
                    <?php endforeach; ?>

                </div>
            </div>

            <!-- Schedule -->
            <div class="mb-4">
                <label for="schedule"
                       class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= htmlspecialchars($t('cron_schedule'), ENT_QUOTES, 'UTF-8') ?>
                    <span class="text-red-500">*</span>
                </label>
                <input type="text" id="schedule" name="schedule" required
                       value="<?= htmlspecialchars($val('schedule'), ENT_QUOTES, 'UTF-8') ?>"
                       class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm font-mono
                              bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                              focus:outline-none focus:ring-2 focus:ring-blue-500
                              focus:border-blue-500 transition"
                       placeholder="*/5 * * * *">
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    Minute Hour Day Month Weekday &ndash; e.g. <code>0 3 * * *</code> = daily at 03:00
                </p>
                <!-- Live human-readable preview – populated by JS below -->
                <p id="schedule-preview" class="mt-1 text-xs text-blue-500 dark:text-blue-400 min-h-[1.25rem]"></p>
            </div>

            <!-- Command -->
            <div class="mb-4">
                <label for="command"
                       class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= htmlspecialchars($t('cron_command'), ENT_QUOTES, 'UTF-8') ?>
                    <span class="text-red-500">*</span>
                </label>
                <textarea id="command" name="command" required rows="3"
                          class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm font-mono
                                 bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                 focus:outline-none focus:ring-2 focus:ring-blue-500
                                 focus:border-blue-500 transition resize-y"
                          placeholder="/usr/local/bin/my-script.sh"><?= htmlspecialchars($val('command'), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <!-- Description -->
            <div class="mb-4">
                <label for="description"
                       class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= htmlspecialchars($t('cron_description'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input type="text" id="description" name="description"
                       value="<?= htmlspecialchars($val('description'), ENT_QUOTES, 'UTF-8') ?>"
                       class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                              bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                              focus:outline-none focus:ring-2 focus:ring-blue-500
                              focus:border-blue-500 transition"
                       placeholder="<?= htmlspecialchars($t('cron_description'), ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <!-- Tags -->
            <div class="mb-4">
                <label for="tags"
                       class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= htmlspecialchars($t('cron_tags'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input type="text" id="tags" name="tags"
                       value="<?= htmlspecialchars($val('tags'), ENT_QUOTES, 'UTF-8') ?>"
                       class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                              bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                              focus:outline-none focus:ring-2 focus:ring-blue-500
                              focus:border-blue-500 transition"
                       placeholder="backup, nightly, important">
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    Comma-separated list of tags.
                </p>
                <!-- Clickable tag hints -->
                <?php if (!empty($tagHints)): ?>
                    <div class="mt-2 flex flex-wrap gap-1">
                        <?php foreach ($tagHints as $hint): ?>
                            <button type="button"
                                    onclick="addTag('<?= htmlspecialchars(addslashes($hint), ENT_QUOTES, 'UTF-8') ?>')"
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                           bg-purple-100 text-purple-700 hover:bg-purple-200 cursor-pointer transition">
                                + <?= htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Execution Limit -->
            <div class="mb-4">
                <label for="execution_limit_seconds"
                       class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= htmlspecialchars($t('cron_execution_limit'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <div class="flex items-center gap-2">
                    <input type="number" id="execution_limit_seconds" name="execution_limit_seconds"
                           min="1" step="1"
                           value="<?= htmlspecialchars($val('execution_limit_seconds'), ENT_QUOTES, 'UTF-8') ?>"
                           class="w-36 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500
                                  focus:border-blue-500 transition"
                           placeholder="e.g. 300">
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        <?= htmlspecialchars($t('cron_execution_limit_seconds'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    <?= htmlspecialchars($t('cron_execution_limit_hint'), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

            <!-- Checkboxes row -->
            <div class="mb-6 flex flex-wrap gap-6">

                <!-- Active -->
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="active" value="1"
                           <?= $isActiveVal ? 'checked' : '' ?>
                           class="w-4 h-4 text-blue-600 border-gray-300 rounded
                                  focus:ring-blue-500 cursor-pointer">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        <?= htmlspecialchars($t('cron_active'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </label>

                <!-- Notify on failure / limit exceeded -->
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="notify_on_failure" value="1"
                           <?= $isNotifyVal ? 'checked' : '' ?>
                           class="w-4 h-4 text-blue-600 border-gray-300 rounded
                                  focus:ring-blue-500 cursor-pointer">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        <?= htmlspecialchars($t('cron_notify_on_failure'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </label>

                <!-- Auto-kill on limit exceeded -->
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="auto_kill_on_limit" value="1"
                           id="auto_kill_on_limit"
                           <?= $isAutoKillVal ? 'checked' : '' ?>
                           class="w-4 h-4 text-orange-500 border-gray-300 rounded
                                  focus:ring-orange-400 cursor-pointer">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        <?= htmlspecialchars($t('cron_auto_kill'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </label>

            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-3">
                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium
                               px-6 py-2.5 rounded-lg transition focus:outline-none
                               focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <?= htmlspecialchars($isEdit ? $t('save') : $t('cron_add'), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <a href="/crons"
                   class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 underline">
                    <?= htmlspecialchars($t('cancel'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>

        </form>
    </div>
</div>

<script>
/**
 * Add a tag hint to the comma-separated tags input field.
 * Avoids duplicates and trims surrounding whitespace.
 *
 * @param {string} tagName Tag to insert.
 */
function addTag(tagName) {
    const input = document.getElementById('tags');
    if (!input) return;

    const existing = input.value
        .split(',')
        .map(s => s.trim())
        .filter(s => s !== '');

    if (!existing.includes(tagName)) {
        existing.push(tagName);
        input.value = existing.join(', ');
    }
}

/**
 * SSH hosts map, keyed by linux_user.
 * Used on the create form to rebuild the target checkboxes when the user changes.
 *
 * @type {Object.<string, string[]>}
 */
const sshHostsByUser = <?= $sshHostsByUser ?>;

/**
 * Rebuild the SSH host target checkboxes in #targets-container based on the
 * selected linux_user.  Existing SSH-host checkboxes are replaced; the
 * "local" checkbox is always kept and never touched.
 *
 * On the edit form sshHostsByUser is always {} (SSH hosts already rendered
 * server-side), so this function is effectively a no-op in that case.
 *
 * @param {string} user Linux username.
 */
function updateTargetCheckboxes(user) {
    const container = document.getElementById('targets-container');
    if (!container) return;

    // Remove existing SSH-host checkboxes (keep "local")
    container.querySelectorAll('.ssh-target-label').forEach(function(el) {
        el.remove();
    });

    const hosts = sshHostsByUser[user] || [];

    hosts.forEach(function(host) {
        const label = document.createElement('label');
        label.className = 'flex items-center gap-2 cursor-pointer ssh-target-label';

        const input = document.createElement('input');
        input.type      = 'checkbox';
        input.name      = 'targets[]';
        input.value     = host;
        input.className = 'w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer';

        const span = document.createElement('span');
        span.className   = 'text-sm font-mono text-gray-700 dark:text-gray-300';
        span.textContent = host;

        label.appendChild(input);
        label.appendChild(span);
        container.appendChild(label);
    });
}

// Wire up linux_user input → rebuild SSH-host checkboxes (create form only)
(function() {
    const linuxUserInput = document.getElementById('linux_user');
    if (!linuxUserInput) return;

    linuxUserInput.addEventListener('change', function() {
        updateTargetCheckboxes(this.value.trim());
    });
    linuxUserInput.addEventListener('blur', function() {
        updateTargetCheckboxes(this.value.trim());
    });
})();

/**
 * Live human-readable preview for the schedule input.
 *
 * Fetches /crons/translate?expr=<expression> on every change (debounced 350 ms)
 * and renders the result below the input field.  Shows nothing when the field
 * is empty or the expression is invalid (the server falls back to the raw string
 * in that case, which we suppress to avoid showing a pointless mirror).
 */
(function() {
    const input   = document.getElementById('schedule');
    const preview = document.getElementById('schedule-preview');
    if (!input || !preview) return;

    let debounceTimer = null;

    /**
     * Fetch the human-readable translation for expr and update the preview.
     * @param {string} expr
     */
    function fetchTranslation(expr) {
        expr = expr.trim();
        if (expr === '') {
            preview.textContent = '';
            return;
        }

        fetch('/crons/translate?expr=' + encodeURIComponent(expr), {
            credentials: 'same-origin'
        })
        .then(function(res) { return res.ok ? res.json() : null; })
        .then(function(data) {
            if (!data) { preview.textContent = ''; return; }
            // Only show when the translation differs from the raw expression
            // (CronTranslator returns the raw string for unsupported expressions)
            preview.textContent = (data.human && data.human !== expr) ? data.human : '';
        })
        .catch(function() { preview.textContent = ''; });
    }

    // Debounce input events so we don't spam the server on every keystroke
    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            fetchTranslation(input.value);
        }, 350);
    });

    // Fetch immediately on page load when editing an existing job
    if (input.value.trim() !== '') {
        fetchTranslation(input.value);
    }
})();
</script>
