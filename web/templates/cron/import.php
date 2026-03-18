<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Import from Crontab Template
 *
 * Displays a user-selector and, once a Linux user is chosen, a table of
 * unmanaged crontab entries that can be bulk-imported as Cronmanager jobs.
 *
 * Variables available in this template:
 *   array       $users            – known linux_user strings (from managed jobs)
 *   string|null $selectedUser     – the currently selected Linux user, or null
 *   array       $unmanagedEntries – array of {'schedule', 'command'} maps
 *   array       $tags             – all known tag records from the agent
 *   bool        $isAdmin          – whether the current user has admin role
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

$users            = isset($users)            && is_array($users)            ? $users            : [];
$selectedUser     = isset($selectedUser)     && $selectedUser !== null      ? (string) $selectedUser : null;
$unmanagedEntries = isset($unmanagedEntries) && is_array($unmanagedEntries) ? $unmanagedEntries : [];
$tags             = isset($tags)             && is_array($tags)             ? $tags             : [];
$isAdmin          = isset($isAdmin)          && (bool) $isAdmin;

// Consume flash error if any
$flashSuccess = \Cronmanager\Web\Session\SessionManager::get('_flash_success');
if ($flashSuccess !== null) {
    \Cronmanager\Web\Session\SessionManager::remove('_flash_success');
}
?>

<!-- ======================================================================
     Page header
     ====================================================================== -->
<div class="mb-6 flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
        <?= htmlspecialchars($t('import_title'), ENT_QUOTES, 'UTF-8') ?>
    </h1>
    <a href="/crons"
       class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 underline">
        &larr; <?= htmlspecialchars($t('import_back'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<?php if ($flashSuccess !== null): ?>
    <div class="mb-5 flex items-start gap-3 bg-green-50 border border-green-300 text-green-700 rounded-lg px-4 py-3 text-sm" role="alert">
        <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <span><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
<?php endif; ?>

<!-- ======================================================================
     User selector card
     ====================================================================== -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 mb-6">
    <form method="GET" action="/crons/import" class="flex flex-wrap items-end gap-3">

        <!-- Known-user dropdown -->
        <div class="flex-1 min-w-48">
            <label for="import-user-select" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                <?= htmlspecialchars($t('import_select_user'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="import-user-select" name="user"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                           focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">— <?= htmlspecialchars($t('import_select_user'), ENT_QUOTES, 'UTF-8') ?> —</option>
                <?php foreach ($users as $knownUser): ?>
                    <?php $sel = ($selectedUser === $knownUser) ? ' selected' : ''; ?>
                    <option value="<?= htmlspecialchars($knownUser, ENT_QUOTES, 'UTF-8') ?>"<?= $sel ?>>
                        <?= htmlspecialchars($knownUser, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Manual user name text input (for users without managed jobs yet) -->
        <div class="flex-1 min-w-48">
            <label for="import-user-text" class="block text-xs font-medium text-gray-600 mb-1">
                &nbsp;
            </label>
            <input type="text"
                   id="import-user-text"
                   placeholder="<?= htmlspecialchars($t('import_select_user'), ENT_QUOTES, 'UTF-8') ?>…"
                   class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                          bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                          focus:outline-none focus:ring-2 focus:ring-blue-500 placeholder-gray-400 dark:placeholder-gray-500"
                   oninput="document.getElementById('import-user-select').value = '';"
                   onchange="if(this.value.trim() !== '') {
                       var s = document.createElement('option');
                       s.value = this.value.trim();
                       s.text  = this.value.trim();
                       s.selected = true;
                       var sel = document.getElementById('import-user-select');
                       var found = false;
                       for (var i = 0; i < sel.options.length; i++) {
                           if (sel.options[i].value === s.value) {
                               sel.options[i].selected = true;
                               found = true;
                               break;
                           }
                       }
                       if (!found) sel.add(s);
                       this.value = '';
                   }">
        </div>

        <!-- Load button -->
        <div>
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium
                           px-5 py-2 rounded-lg transition focus:outline-none
                           focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <?= htmlspecialchars($t('import_load'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>

    </form>
</div>

<!-- ======================================================================
     Unmanaged entries table (shown only when a user was selected)
     ====================================================================== -->
<?php if ($selectedUser !== null): ?>

    <?php if (empty($unmanagedEntries)): ?>
        <!-- Empty state -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 px-6 py-10 text-center text-gray-400 dark:text-gray-500 text-sm">
            <?= htmlspecialchars($t('import_none_found'), ENT_QUOTES, 'UTF-8') ?>
            (<code class="font-mono"><?= htmlspecialchars($selectedUser, ENT_QUOTES, 'UTF-8') ?></code>)
        </div>

    <?php else: ?>

        <form method="POST" action="/crons/import">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <!-- Pass the user to the controller -->
            <input type="hidden" name="user" value="<?= htmlspecialchars($selectedUser, ENT_QUOTES, 'UTF-8') ?>">

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden mb-4">

                <!-- Table toolbar -->
                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        <?= count($unmanagedEntries) ?> <?= htmlspecialchars($t('import_none_found') !== $t('import_none_found') ? '' : 'entries', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <!-- Select-all toggle -->
                    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer select-none">
                        <input type="checkbox" id="select-all"
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                               onchange="document.querySelectorAll('.entry-checkbox').forEach(cb => cb.checked = this.checked)">
                        <?= htmlspecialchars($t('import_select_all'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-10">
                                    &nbsp;
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <?= htmlspecialchars($t('import_schedule'), ENT_QUOTES, 'UTF-8') ?>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <?= htmlspecialchars($t('import_command'), ENT_QUOTES, 'UTF-8') ?>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <?= htmlspecialchars($t('import_description'), ENT_QUOTES, 'UTF-8') ?>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <?= htmlspecialchars($t('import_tags'), ENT_QUOTES, 'UTF-8') ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <?php foreach ($unmanagedEntries as $i => $entry): ?>
                                <?php
                                    $schedule = (string) ($entry['schedule'] ?? '');
                                    $command  = (string) ($entry['command']  ?? '');
                                    // Truncate command for display only
                                    $cmdDisplay = mb_strlen($command) > 60
                                        ? mb_substr($command, 0, 57) . '…'
                                        : $command;
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">

                                    <!-- Select checkbox -->
                                    <td class="px-4 py-3 text-center">
                                        <input type="checkbox"
                                               class="entry-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                               name="selected[]"
                                               value="<?= htmlspecialchars((string) $i, ENT_QUOTES, 'UTF-8') ?>">
                                        <!-- Hidden inputs carry the original values to the POST handler -->
                                        <input type="hidden"
                                               name="schedule[<?= htmlspecialchars((string) $i, ENT_QUOTES, 'UTF-8') ?>]"
                                               value="<?= htmlspecialchars($schedule, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden"
                                               name="command[<?= htmlspecialchars((string) $i, ENT_QUOTES, 'UTF-8') ?>]"
                                               value="<?= htmlspecialchars($command, ENT_QUOTES, 'UTF-8') ?>">
                                    </td>

                                    <!-- Schedule -->
                                    <td class="px-4 py-3 text-sm font-mono text-gray-700 dark:text-gray-200 whitespace-nowrap">
                                        <?= htmlspecialchars($schedule, ENT_QUOTES, 'UTF-8') ?>
                                    </td>

                                    <!-- Command (truncated display) -->
                                    <td class="px-4 py-3 text-sm font-mono text-gray-600 dark:text-gray-300" title="<?= htmlspecialchars($command, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($cmdDisplay, ENT_QUOTES, 'UTF-8') ?>
                                    </td>

                                    <!-- Description input -->
                                    <td class="px-4 py-3 text-sm">
                                        <input type="text"
                                               name="description[<?= htmlspecialchars((string) $i, ENT_QUOTES, 'UTF-8') ?>]"
                                               placeholder="<?= htmlspecialchars($t('import_description'), ENT_QUOTES, 'UTF-8') ?>"
                                               class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-sm
                                                      bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                                      focus:outline-none focus:ring-2 focus:ring-blue-500
                                                      placeholder-gray-400 dark:placeholder-gray-500">
                                    </td>

                                    <!-- Tags input -->
                                    <td class="px-4 py-3 text-sm">
                                        <input type="text"
                                               name="tags[<?= htmlspecialchars((string) $i, ENT_QUOTES, 'UTF-8') ?>]"
                                               placeholder="<?= htmlspecialchars($t('import_tags'), ENT_QUOTES, 'UTF-8') ?>"
                                               list="tag-suggestions-<?= htmlspecialchars((string) $i, ENT_QUOTES, 'UTF-8') ?>"
                                               class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-sm
                                                      bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                                      focus:outline-none focus:ring-2 focus:ring-blue-500
                                                      placeholder-gray-400 dark:placeholder-gray-500">
                                        <?php if (!empty($tags)): ?>
                                            <datalist id="tag-suggestions-<?= htmlspecialchars((string) $i, ENT_QUOTES, 'UTF-8') ?>">
                                                <?php foreach ($tags as $tag): ?>
                                                    <?php $tagName = (string) ($tag['name'] ?? $tag); ?>
                                                    <option value="<?= htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') ?>">
                                                <?php endforeach; ?>
                                            </datalist>
                                        <?php endif; ?>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Submit -->
            <div class="flex justify-end">
                <button type="submit"
                        class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white
                               text-sm font-medium px-6 py-2.5 rounded-lg transition focus:outline-none
                               focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    <?= htmlspecialchars($t('import_submit'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>

        </form>

    <?php endif; ?>

<?php endif; ?>
