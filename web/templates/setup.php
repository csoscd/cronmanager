<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Initial Setup Page Template
 *
 * Standalone page – does NOT extend layout.php (no navigation bar).
 *
 * Shown only on the very first run when no user accounts exist and OIDC is
 * disabled.  Allows creating the first administrator account.
 *
 * Variables available in this template:
 *   string|null        $error      – Translation key of an error message, or null
 *   Translator         $translator – Translator instance for i18n strings
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

use Cronmanager\Web\I18n\Translator;

// Safe defaults
$errorKey = isset($error) ? (string) $error : null;

/** @var Translator $translator */
$t    = static fn(string $key, array $r = []): string => isset($translator) ? $translator->t($key, $r) : $key;
$lang = isset($translator) ? $translator->getLang() : 'en';

// Resolve the human-readable error text from the flash key
$errorMessage = null;
if ($errorKey !== null && $errorKey !== '') {
    $errorMessage = $t($errorKey);
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($t('setup_title'), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars($t('app_name'), ENT_QUOTES, 'UTF-8') ?></title>
    <script>
    (function () {
        var stored = localStorage.getItem('darkMode');
        var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (stored === 'true' || (stored === null && prefersDark)) {
            document.documentElement.classList.add('dark');
        }
        window.tailwind = window.tailwind || {};
        window.tailwind.config = { darkMode: 'class' };
    })();
    </script>
    <script src="/assets/js/tailwind.min.js"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen flex items-center justify-center px-4 transition-colors duration-200">

    <!-- Setup card -->
    <div class="w-full max-w-md">

        <!-- App title -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">
                <?= htmlspecialchars($t('app_name'), ENT_QUOTES, 'UTF-8') ?>
            </h1>
            <p class="mt-2 text-gray-500 dark:text-gray-400 text-sm">
                <?= htmlspecialchars($t('setup_subtitle'), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <!-- Card -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8">

            <!-- Info box (blue) -->
            <div class="mb-5 flex items-start gap-3 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg px-4 py-3 text-sm" role="note">
                <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 110 20A10 10 0 0112 2z"/>
                </svg>
                <span><?= htmlspecialchars($t('setup_info'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <!-- Error message -->
            <?php if ($errorMessage !== null): ?>
                <div class="mb-5 flex items-start gap-3 bg-red-50 border border-red-300 text-red-700 rounded-lg px-4 py-3 text-sm" role="alert">
                    <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                    <span><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <!-- Setup form -->
            <form method="POST" action="/setup" novalidate>
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

                <!-- Username -->
                <div class="mb-4">
                    <label for="username" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= htmlspecialchars($t('setup_username'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        autocomplete="username"
                        required
                        minlength="3"
                        maxlength="128"
                        pattern="[a-zA-Z0-9._\-]+"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm text-sm
                               bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                               focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                               placeholder-gray-400 dark:placeholder-gray-500 transition"
                        placeholder="<?= htmlspecialchars($t('setup_username'), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <!-- Password -->
                <div class="mb-4">
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= htmlspecialchars($t('setup_password'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="new-password"
                        required
                        minlength="8"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm text-sm
                               bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                               focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                               placeholder-gray-400 dark:placeholder-gray-500 transition"
                        placeholder="••••••••"
                    >
                </div>

                <!-- Confirm Password -->
                <div class="mb-6">
                    <label for="password_confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= htmlspecialchars($t('setup_password_confirm'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input
                        type="password"
                        id="password_confirm"
                        name="password_confirm"
                        autocomplete="new-password"
                        required
                        minlength="8"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm text-sm
                               bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                               focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                               placeholder-gray-400 dark:placeholder-gray-500 transition"
                        placeholder="••••••••"
                    >
                </div>

                <!-- Submit button -->
                <button
                    type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white
                           font-medium py-2.5 px-4 rounded-lg text-sm transition focus:outline-none
                           focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <?= htmlspecialchars($t('setup_submit'), ENT_QUOTES, 'UTF-8') ?>
                </button>

            </form>

        </div><!-- /card -->

    </div><!-- /max-w-md -->

</body>
</html>
