<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Export Options Template
 *
 * Presents a form where the user can select optional filters (user, tag) and
 * the export format (crontab plain text or JSON) before downloading.
 *
 * Variables available in this template:
 *   array $tags  – all known tags
 *   array $users – unique linux_users from all jobs
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

$tags  = isset($tags)  && is_array($tags)  ? $tags  : [];
$users = isset($users) && is_array($users) ? $users : [];
?>

<!-- ======================================================================
     Page header
     ====================================================================== -->
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
        <?= htmlspecialchars($t('export_title'), ENT_QUOTES, 'UTF-8') ?>
    </h1>
</div>

<div class="max-w-xl">

    <!-- Info box -->
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-5 py-4 mb-6 text-sm text-blue-800">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-blue-500" fill="none" viewBox="0 0 24 24"
                 stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p><?= htmlspecialchars($t('export_info'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>

    <!-- Export form (GET so parameters appear in the download URL) -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">

        <form method="GET" action="/export/download">

            <!-- User filter -->
            <div class="mb-4">
                <label for="export-user" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= htmlspecialchars($t('cron_linux_user'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select id="export-user" name="user"
                        class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                               bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                               focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value=""><?= htmlspecialchars($t('filter_all_users'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Tag filter -->
            <div class="mb-4">
                <label for="export-tag" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= htmlspecialchars($t('cron_tags'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select id="export-tag" name="tag"
                        class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                               bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                               focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value=""><?= htmlspecialchars($t('filter_all_tags'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($tags as $tag): ?>
                        <?php $tagName = (string) ($tag['name'] ?? $tag); ?>
                        <option value="<?= htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Format selection -->
            <fieldset class="mb-6">
                <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    <?= htmlspecialchars($t('export_format'), ENT_QUOTES, 'UTF-8') ?>
                </legend>

                <div class="flex flex-col gap-2">

                    <label class="flex items-center gap-3 cursor-pointer p-3 rounded-lg border border-gray-200 dark:border-gray-600
                                  hover:bg-gray-50 dark:hover:bg-gray-700 transition has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 dark:has-[:checked]:bg-blue-900/20">
                        <input type="radio" name="format" value="crontab" checked
                               class="text-blue-600 border-gray-300 focus:ring-blue-500">
                        <div>
                            <span class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                <?= htmlspecialchars($t('export_format_crontab'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                Standard crontab format – importable directly with <code>crontab</code>
                            </p>
                        </div>
                    </label>

                    <label class="flex items-center gap-3 cursor-pointer p-3 rounded-lg border border-gray-200 dark:border-gray-600
                                  hover:bg-gray-50 dark:hover:bg-gray-700 transition has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 dark:has-[:checked]:bg-blue-900/20">
                        <input type="radio" name="format" value="json"
                               class="text-blue-600 border-gray-300 focus:ring-blue-500">
                        <div>
                            <span class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                <?= htmlspecialchars($t('export_format_json'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                Structured JSON – includes all job metadata
                            </p>
                        </div>
                    </label>

                </div>
            </fieldset>

            <!-- Submit -->
            <button type="submit"
                    class="w-full inline-flex items-center justify-center gap-2 bg-blue-600
                           hover:bg-blue-700 text-white text-sm font-medium px-6 py-2.5 rounded-lg
                           transition focus:outline-none focus:ring-2 focus:ring-blue-500
                           focus:ring-offset-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                <?= htmlspecialchars($t('export_download'), ENT_QUOTES, 'UTF-8') ?>
            </button>

        </form>
    </div>
</div>
