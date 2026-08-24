<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – User Create / Edit Form Template
 *
 * Variables available:
 *   array|null $user        – existing user row (null = create mode)
 *   array      $agents      – all agent rows for per-user restriction multi-select
 *   bool       $mailEnabled – whether SMTP is configured (controls invite option)
 *   array      $errors      – field => message map
 *   string     $csrf_token
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$isEdit      = isset($user) && is_array($user);
$uid         = $isEdit ? (int)    ($user['id']        ?? 0) : 0;
$uname       = $isEdit ? (string) ($user['username']  ?? '') : '';
$email       = $isEdit ? (string) ($user['email']     ?? '') : '';
$role        = $isEdit ? (string) ($user['role']      ?? 'viewer') : 'viewer';
$active      = $isEdit ? (int)    ($user['active']    ?? 1) : 1;
$isSSO       = $isEdit && !empty($user['oauth_sub']);
$selAgentIds = $isEdit && is_array($user['agent_ids'] ?? null) ? $user['agent_ids'] : [];
$agents      = is_array($agents ?? null) ? $agents : [];
$mailEnabled = (bool) ($mailEnabled ?? false);
$errors      = is_array($errors ?? null) ? $errors : [];
$csrf        = htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8');

$action = $isEdit ? '/users/' . $uid . '/edit' : '/users/new';
$title  = $isEdit ? $t('user_edit') : $t('user_create');
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $h($title) ?></h1>
</div>

<div class="max-w-2xl">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <form method="POST" action="<?= $h($action) ?>">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">

            <?php if (!empty($errors['db'])): ?>
                <div class="mb-4 p-3 bg-red-50 dark:bg-red-900/30 border border-red-300 dark:border-red-700 rounded-lg text-red-700 dark:text-red-300 text-sm">
                    <?= $h($errors['db']) ?>
                </div>
            <?php endif; ?>

            <!-- Username -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= $h($t('user_username')) ?> *
                </label>
                <?php if ($isEdit): ?>
                    <p class="text-sm text-gray-900 dark:text-gray-100 font-medium"><?= $h($uname) ?></p>
                    <?php if ($isSSO): ?>
                        <p class="text-xs text-gray-400 mt-0.5"><?= $h($t('user_type_sso')) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <input type="text" name="username" value="<?= $h($uname) ?>"
                           class="w-full px-3 py-2 rounded-lg border <?= isset($errors['username']) ? 'border-red-400' : 'border-gray-300 dark:border-gray-600' ?> bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           required autocomplete="username">
                    <?php if (isset($errors['username'])): ?>
                        <p class="mt-1 text-xs text-red-500"><?= $h($errors['username']) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Email -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= $h($t('user_email')) ?>
                    <?php if ($mailEnabled): ?>
                        <span class="text-xs text-gray-400">(<?= $h($t('user_email_invite_hint')) ?>)</span>
                    <?php endif; ?>
                </label>
                <?php if ($isSSO): ?>
                    <p class="text-sm text-gray-900 dark:text-gray-100"><?= $h($email) ?></p>
                    <p class="text-xs text-gray-400 mt-0.5"><?= $h($t('user_sso_profile_readonly')) ?></p>
                <?php else: ?>
                    <input type="email" name="email" value="<?= $h($email) ?>"
                           class="w-full px-3 py-2 rounded-lg border <?= isset($errors['email']) ? 'border-red-400' : 'border-gray-300 dark:border-gray-600' ?> bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           autocomplete="email">
                    <?php if (isset($errors['email'])): ?>
                        <p class="mt-1 text-xs text-red-500"><?= $h($errors['email']) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Role -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    <?= $h($t('user_role')) ?> *
                </label>
                <?php if ($isSSO): ?>
                    <?php
                        $ssoRoleLabel = match ($role) {
                            'admin'    => $t('role_admin'),
                            'operator' => $t('role_operator'),
                            'api-only' => $t('role_api_only'),
                            default    => $t('role_viewer'),
                        };
                    ?>
                    <input type="hidden" name="role" value="<?= $h($role) ?>">
                    <p class="text-sm text-gray-900 dark:text-gray-100"><?= $h($ssoRoleLabel) ?></p>
                <?php else: ?>
                    <select name="role"
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <?php foreach (['viewer' => $t('role_viewer'), 'operator' => $t('role_operator'), 'admin' => $t('role_admin'), 'api-only' => $t('role_api_only')] as $val => $label): ?>
                            <option value="<?= $h($val) ?>" <?= $role === $val ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['role'])): ?>
                        <p class="mt-1 text-xs text-red-500"><?= $h($errors['role']) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
                <p class="mt-1 text-xs text-gray-400"><?= $h($t('user_role_hint')) ?></p>
            </div>

            <!-- Password (only for local users) -->
            <?php if (!$isSSO): ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= $h($t('user_password')) ?>
                        <?php if ($isEdit): ?>
                            <span class="text-xs text-gray-400">(<?= $h($t('user_password_leave_blank')) ?>)</span>
                        <?php else: ?>
                            *
                        <?php endif; ?>
                    </label>
                    <input type="password" name="password"
                           class="w-full px-3 py-2 rounded-lg border <?= isset($errors['password']) ? 'border-red-400' : 'border-gray-300 dark:border-gray-600' ?> bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           autocomplete="new-password">
                    <?php if (isset($errors['password'])): ?>
                        <p class="mt-1 text-xs text-red-500"><?= $h($errors['password']) ?></p>
                    <?php endif; ?>

                    <?php if (!$isEdit && $mailEnabled): ?>
                        <label class="mt-2 flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="send_invite" value="1" checked
                                   class="rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-700 dark:text-gray-300"><?= $h($t('user_send_invite')) ?></span>
                        </label>
                        <p class="mt-0.5 ml-6 text-xs text-gray-400"><?= $h($t('user_send_invite_hint')) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Agent restrictions -->
            <?php if (!empty($agents)): ?>
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= $h($t('user_agent_restriction')) ?>
                    </label>
                    <p class="text-xs text-gray-400 mb-2"><?= $h($t('user_agent_restriction_hint')) ?></p>
                    <div class="space-y-1 max-h-40 overflow-y-auto border border-gray-200 dark:border-gray-600 rounded-lg p-2">
                        <?php foreach ($agents as $ag): ?>
                            <?php $agId = (int) $ag['id']; ?>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="agent_ids[]" value="<?= $agId ?>"
                                       <?= in_array($agId, array_map('intval', $selAgentIds), strict: true) ? 'checked' : '' ?>
                                       class="rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm text-gray-700 dark:text-gray-300"><?= $h((string) $ag['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Submit -->
            <div class="flex items-center gap-3">
                <button type="submit"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">
                    <?= $h($isEdit ? $t('form_save') : $t('user_create')) ?>
                </button>
                <a href="/users"
                   class="px-4 py-2 text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100 text-sm transition">
                    <?= $h($t('form_cancel')) ?>
                </a>
            </div>
        </form>
    </div>
</div>
