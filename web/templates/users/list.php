<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – User Management List Template
 *
 * Variables available:
 *   array      $users          – all user rows (active, email, agent_ids included)
 *   array      $agents         – all agent rows for display of agent restrictions
 *   int|null   $currentUserId  – ID of the logged-in user
 *   bool       $isAdmin        – whether current user is admin
 *   bool       $mailEnabled    – whether SMTP mail is configured
 *   string     $csrf_token
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$users         = is_array($users ?? null) ? $users : [];
$agents        = is_array($agents ?? null) ? $agents : [];
$currentUserId = isset($currentUserId) ? (int) $currentUserId : null;
$isAdmin       = (bool) ($isAdmin ?? false);
$mailEnabled   = (bool) ($mailEnabled ?? false);
$csrf          = htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8');

$agentIndex = [];
foreach ($agents as $ag) {
    $agentIndex[(int) $ag['id']] = (string) $ag['name'];
}

$roleBadge = static function (string $role) use ($t, $h): string {
    $class = match ($role) {
        'admin'    => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        'operator' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'api-only' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        default    => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    };
    $label = match ($role) {
        'admin'    => $t('role_admin'),
        'operator' => $t('role_operator'),
        'api-only' => $t('role_api_only'),
        default    => $t('role_viewer'),
    };
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium '
        . $class . '">' . $h($label) . '</span>';
};
?>

<div class="mb-6 flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
        <?= $h($t('nav_users')) ?>
    </h1>
    <?php if ($isAdmin): ?>
        <a href="/users/new"
           class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">
            + <?= $h($t('user_create')) ?>
        </a>
    <?php endif; ?>
</div>

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
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= $h($t('user_username')) ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= $h($t('user_type')) ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= $h($t('user_role')) ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= $h($t('user_email')) ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= $h($t('user_status')) ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= $h($t('cron_created_at')) ?></th>
                        <?php if ($isAdmin): ?>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= $h($t('actions')) ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($users as $u): ?>
                        <?php
                            $uid      = (int)    ($u['id']       ?? 0);
                            $uname    = (string) ($u['username'] ?? '');
                            $role     = (string) ($u['role']     ?? 'viewer');
                            $active   = (int)    ($u['active']   ?? 1);
                            $email    = (string) ($u['email']    ?? '');
                            $creAt    = (string) ($u['created_at'] ?? '');
                            $isSSO    = !empty($u['oauth_sub']);
                            $isSelf   = $uid === $currentUserId;
                            $agentIds = is_array($u['agent_ids'] ?? null) ? $u['agent_ids'] : [];
                        ?>
                        <tr class="<?= $active ? '' : 'opacity-60' ?> hover:bg-gray-50 dark:hover:bg-gray-700">

                            <!-- Username -->
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 font-medium">
                                <?= $h($uname) ?>
                                <?php if ($isSelf): ?>
                                    <span class="ml-1 text-xs text-gray-400">(<?= $h($t('user_you')) ?>)</span>
                                <?php endif; ?>
                                <?php if (!empty($agentIds)): ?>
                                    <div class="mt-0.5 flex flex-wrap gap-1">
                                        <?php foreach ($agentIds as $aid): ?>
                                            <span class="text-xs px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-gray-500 dark:text-gray-400">
                                                <?= $h($agentIndex[(int) $aid] ?? '#' . (int) $aid) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Type -->
                            <td class="px-4 py-3 text-sm">
                                <?php if ($isSSO): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200"><?= $h($t('user_type_sso')) ?></span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"><?= $h($t('user_type_local')) ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Role -->
                            <td class="px-4 py-3 text-sm"><?= $roleBadge($role) ?></td>

                            <!-- Email -->
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                <?= $email !== '' ? $h($email) : '—' ?>
                            </td>

                            <!-- Status -->
                            <td class="px-4 py-3 text-sm">
                                <?php if ($active): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200"><?= $h($t('user_active')) ?></span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200"><?= $h($t('user_inactive')) ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Created at -->
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                <?= $h($creAt ?: '—') ?>
                            </td>

                            <?php if ($isAdmin): ?>
                                <td class="px-4 py-3 text-sm">
                                    <div class="flex items-center flex-wrap gap-2">

                                        <!-- Edit -->
                                        <a href="/users/<?= $uid ?>/edit"
                                           class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-200 text-sm font-medium transition">
                                            <?= $h($t('cron_edit')) ?>
                                        </a>

                                        <?php if (!$isSelf): ?>

                                            <!-- Activate / Deactivate -->
                                            <?php if ($active): ?>
                                                <form method="POST" action="/users/<?= $uid ?>/deactivate"
                                                      onsubmit="return confirm('<?= $h($t('user_deactivate_confirm')) ?>')">
                                                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                                    <button type="submit" class="text-yellow-600 hover:text-yellow-800 dark:text-yellow-400 dark:hover:text-yellow-200 text-sm font-medium transition">
                                                        <?= $h($t('user_deactivate')) ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="/users/<?= $uid ?>/activate">
                                                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                                    <button type="submit" class="text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-200 text-sm font-medium transition">
                                                        <?= $h($t('user_activate')) ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Resend invite (only if mail enabled and email set and not SSO) -->
                                            <?php if ($mailEnabled && $email !== '' && !$isSSO): ?>
                                                <form method="POST" action="/users/<?= $uid ?>/invite">
                                                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                                    <button type="submit" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-200 text-sm font-medium transition">
                                                        <?= $h($t('user_resend_invite')) ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Delete -->
                                            <form method="POST" action="/users/<?= $uid ?>/delete"
                                                  onsubmit="return confirm('<?= $h($t('user_delete_confirm')) ?>')">
                                                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-200 text-sm font-medium transition">
                                                    <?= $h($t('cron_delete')) ?>
                                                </button>
                                            </form>

                                        <?php else: ?>
                                            <span class="text-gray-300 dark:text-gray-600 text-xs">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>

                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
