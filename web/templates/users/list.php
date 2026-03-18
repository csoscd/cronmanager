<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – User Management Template
 *
 * Lists all user accounts with role badges and admin actions.
 *
 * Variables available in this template:
 *   array    $users         – all user rows from the database
 *   int|null $currentUserId – ID of the logged-in user (for disabling own-user actions)
 *   bool     $isAdmin       – whether the current user has admin role
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

/** @var callable(string): string $h */
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$users         = isset($users)         && is_array($users) ? $users : [];
$currentUserId = isset($currentUserId) ? (int) $currentUserId : null;
$isAdmin       = isset($isAdmin)       && (bool) $isAdmin;
?>

<!-- Page header -->
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
        <?= $h($t('nav_users')) ?>
    </h1>
</div>

<!-- User table -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">

    <?php if (empty($users)): ?>
        <div class="px-6 py-12 text-center text-gray-400 dark:text-gray-500 text-sm">
            <?= $h($t('no_results')) ?>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= $h($t('user_username')) ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= $h($t('user_type')) ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= $h($t('user_role')) ?>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?= $h($t('cron_created_at')) ?>
                        </th>
                        <?php if ($isAdmin): ?>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                <?= $h($t('actions')) ?>
                            </th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($users as $u): ?>
                        <?php
                            $uid       = (int)    ($u['id']         ?? 0);
                            $username  = (string) ($u['username']   ?? '');
                            $role      = (string) ($u['role']       ?? 'view');
                            $oauthSub  = isset($u['oauth_sub']) && $u['oauth_sub'] !== null;
                            $createdAt = (string) ($u['created_at'] ?? '');
                            $isSelf    = $uid === $currentUserId;
                        ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">

                            <!-- Username -->
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 font-medium">
                                <?= $h($username) ?>
                                <?php if ($isSelf): ?>
                                    <span class="ml-1 text-xs text-gray-400">(<?= $h($t('user_you')) ?>)</span>
                                <?php endif; ?>
                            </td>

                            <!-- Type: local or SSO -->
                            <td class="px-4 py-3 text-sm">
                                <?php if ($oauthSub): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">
                                        <?= $h($t('user_type_sso')) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        <?= $h($t('user_type_local')) ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Role badge -->
                            <td class="px-4 py-3 text-sm">
                                <?php if ($role === 'admin'): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                        <?= $h($t('role_admin')) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        <?= $h($t('role_view')) ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Created at -->
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                <?= $h($createdAt !== '' ? $createdAt : '—') ?>
                            </td>

                            <!-- Actions (admin only, not for own account) -->
                            <?php if ($isAdmin): ?>
                                <td class="px-4 py-3 text-sm">
                                    <?php if (!$isSelf): ?>
                                        <div class="flex items-center gap-2">

                                            <!-- Toggle role -->
                                            <form method="POST"
                                                  action="/users/<?= $uid ?>/role">
                                                <input type="hidden" name="role"
                                                       value="<?= $h($role === 'admin' ? 'view' : 'admin') ?>">
                                                <button type="submit"
                                                        class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-200 text-sm font-medium transition">
                                                    <?= $h($role === 'admin' ? $t('user_make_viewer') : $t('user_make_admin')) ?>
                                                </button>
                                            </form>

                                            <!-- Delete -->
                                            <form method="POST"
                                                  action="/users/<?= $uid ?>/delete"
                                                  onsubmit="return confirm('<?= $h($t('user_delete_confirm')) ?>')">
                                                <button type="submit"
                                                        class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-200 text-sm font-medium transition">
                                                    <?= $h($t('cron_delete')) ?>
                                                </button>
                                            </form>

                                        </div>
                                    <?php else: ?>
                                        <span class="text-gray-300 dark:text-gray-600 text-xs">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>

                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
