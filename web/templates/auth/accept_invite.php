<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Accept Invite / Set Initial Password Template
 *
 * Variables available:
 *   string      $token    – plain token from URL
 *   string      $username – invitee's username
 *   string      $csrf_token
 *   string|null $error    – error translation key
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$csrf = htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $h($t('app_name')) ?> – <?= $h($t('auth_accept_invite')) ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md px-4">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100"><?= $h($t('app_name')) ?></h1>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-8">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-2"><?= $h($t('auth_accept_invite')) ?></h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
                <?= $h($t('auth_welcome_user', ['username' => $username ?? ''])) ?>
            </p>

            <?php if (!empty($error)): ?>
                <div class="mb-4 p-3 bg-red-50 dark:bg-red-900/30 border border-red-300 dark:border-red-700 rounded-lg text-red-700 dark:text-red-300 text-sm">
                    <?= $h($t($error)) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/auth/invite">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                <input type="hidden" name="token" value="<?= $h($token ?? '') ?>">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= $h($t('user_password')) ?></label>
                    <input type="password" name="password" required minlength="8"
                           class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           autocomplete="new-password">
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= $h($t('auth_password_confirm')) ?></label>
                    <input type="password" name="password_confirm" required minlength="8"
                           class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           autocomplete="new-password">
                </div>

                <button type="submit"
                        class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                    <?= $h($t('auth_set_password')) ?>
                </button>
            </form>
        </div>
    </div>
</body>
</html>
