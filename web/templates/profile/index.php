<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – User Profile Template
 *
 * Variables available:
 *   array       $user        – current user row
 *   bool        $mailEnabled – whether password reset via mail is possible
 *   string|null $success     – success message translation key
 *   array       $errors      – field => message map
 *   string      $csrf_token
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$user   = is_array($user ?? null) ? $user : [];
$isSSO  = !empty($user['oauth_sub']);
$email  = (string) ($user['email'] ?? '');
$role   = (string) ($user['role']  ?? 'viewer');
$errors = is_array($errors ?? null) ? $errors : [];
$csrf   = htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8');

$roleLabel = match ($role) {
    'admin'    => $t('role_admin'),
    'operator' => $t('role_operator'),
    'api-only' => $t('role_api_only'),
    default    => $t('role_viewer'),
};
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $h($t('nav_profile')) ?></h1>
</div>

<div class="max-w-2xl space-y-6">

    <?php if (!empty($success)): ?>
        <div class="p-3 bg-green-50 dark:bg-green-900/30 border border-green-300 dark:border-green-700 rounded-lg text-green-700 dark:text-green-300 text-sm">
            <?= $h($t($success)) ?>
        </div>
    <?php endif; ?>

    <!-- Account info (read-only) -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-4"><?= $h($t('profile_account_info')) ?></h2>
        <dl class="space-y-3 text-sm">
            <div class="flex gap-4">
                <dt class="w-32 text-gray-500 dark:text-gray-400 shrink-0"><?= $h($t('user_username')) ?></dt>
                <dd class="text-gray-900 dark:text-gray-100 font-medium"><?= $h((string) ($user['username'] ?? '')) ?></dd>
            </div>
            <div class="flex gap-4">
                <dt class="w-32 text-gray-500 dark:text-gray-400 shrink-0"><?= $h($t('user_role')) ?></dt>
                <dd class="text-gray-900 dark:text-gray-100"><?= $h($roleLabel) ?></dd>
            </div>
            <div class="flex gap-4">
                <dt class="w-32 text-gray-500 dark:text-gray-400 shrink-0"><?= $h($t('user_type')) ?></dt>
                <dd>
                    <?php if ($isSSO): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200"><?= $h($t('user_type_sso')) ?></span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"><?= $h($t('user_type_local')) ?></span>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>
    </div>

    <!-- Edit form -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-4"><?= $h($t('profile_edit')) ?></h2>

        <form method="POST" action="/profile">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">

            <!-- Email -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= $h($t('user_email')) ?></label>
                <input type="email" name="email" value="<?= $h($email) ?>"
                       class="w-full px-3 py-2 rounded-lg border <?= isset($errors['email']) ? 'border-red-400' : 'border-gray-300 dark:border-gray-600' ?> bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                       autocomplete="email">
                <?php if (isset($errors['email'])): ?>
                    <p class="mt-1 text-xs text-red-500"><?= $h($errors['email']) ?></p>
                <?php endif; ?>
            </div>

            <?php if (!$isSSO): ?>
                <!-- New password -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= $h($t('auth_new_password')) ?> <span class="text-xs text-gray-400">(<?= $h($t('user_password_leave_blank')) ?>)</span>
                    </label>
                    <input type="password" name="password"
                           class="w-full px-3 py-2 rounded-lg border <?= isset($errors['password']) ? 'border-red-400' : 'border-gray-300 dark:border-gray-600' ?> bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           autocomplete="new-password">
                    <?php if (isset($errors['password'])): ?>
                        <p class="mt-1 text-xs text-red-500"><?= $h($errors['password']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= $h($t('auth_password_confirm')) ?></label>
                    <input type="password" name="password_confirm"
                           class="w-full px-3 py-2 rounded-lg border <?= isset($errors['password_confirm']) ? 'border-red-400' : 'border-gray-300 dark:border-gray-600' ?> bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           autocomplete="new-password">
                    <?php if (isset($errors['password_confirm'])): ?>
                        <p class="mt-1 text-xs text-red-500"><?= $h($errors['password_confirm']) ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="mb-6">
                    <p class="text-xs text-gray-400"><?= $h($t('user_sso_profile_readonly')) ?></p>
                </div>
            <?php endif; ?>

            <button type="submit"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">
                <?= $h($t('form_save')) ?>
            </button>
        </form>
    </div>

</div>
