<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Forgot Password Form
 *
 * Variables available:
 *   string $csrf_token
 *   string|null $error   – error translation key
 *   string|null $success – success translation key
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
    <title><?= $h($t('app_name')) ?> – <?= $h($t('auth_forgot_password')) ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md px-4">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100"><?= $h($t('app_name')) ?></h1>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-8">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-2"><?= $h($t('auth_forgot_password')) ?></h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6"><?= $h($t('auth_forgot_password_hint')) ?></p>

            <?php if (!empty($error)): ?>
                <div class="mb-4 p-3 bg-red-50 dark:bg-red-900/30 border border-red-300 dark:border-red-700 rounded-lg text-red-700 dark:text-red-300 text-sm">
                    <?= $h($t($error)) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="mb-4 p-3 bg-green-50 dark:bg-green-900/30 border border-green-300 dark:border-green-700 rounded-lg text-green-700 dark:text-green-300 text-sm">
                    <?= $h($t($success)) ?>
                </div>
            <?php else: ?>
                <form method="POST" action="/auth/forgot-password">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= $h($t('user_email')) ?></label>
                        <input type="email" name="email" required
                               class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                               autocomplete="email">
                    </div>

                    <button type="submit"
                            class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                        <?= $h($t('auth_send_reset_link')) ?>
                    </button>
                </form>
            <?php endif; ?>

            <div class="mt-4 text-center">
                <a href="/login" class="text-sm text-blue-600 hover:underline dark:text-blue-400"><?= $h($t('auth_back_to_login')) ?></a>
            </div>
        </div>
    </div>
</body>
</html>
