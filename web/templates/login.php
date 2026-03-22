<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Login Page Template
 *
 * Standalone page – does NOT extend layout.php (no navigation bar).
 *
 * Variables available in this template:
 *   bool                $oidcEnabled – Whether the SSO button should be shown
 *   string|null         $error       – Translation key of an error message, or null
 *   Noodlehaus\Config   $config      – Application configuration
 *   Translator          $translator  – Translator instance for i18n strings
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

use Cronmanager\Web\I18n\Translator;

// Safe defaults
$oidcEnabled = isset($oidcEnabled) && (bool) $oidcEnabled;
$errorKey    = isset($error) ? (string) $error : null;

/** @var Translator $translator */
$t    = static fn(string $key, array $r = []): string => isset($translator) ? $translator->t($key, $r) : $key;
$lang = isset($translator) ? $translator->getLang() : 'en';

// Resolve the human-readable error text from the key stored in the session flash.
// The 'login_error_locked' key requires the remaining lockout minutes as a placeholder.
$errorMessage = null;
if ($errorKey !== null && $errorKey !== '') {
    if ($errorKey === 'login_error_locked') {
        $minutes = (string) (isset($lockoutMinutes) ? (int) $lockoutMinutes : 15);
        $errorMessage = $t($errorKey, ['minutes' => $minutes]);
    } else {
        $errorMessage = $t($errorKey);
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($t('login_title'), ENT_QUOTES, 'UTF-8') ?></title>
    <script>window.tailwind = window.tailwind || {}; window.tailwind.config = { darkMode: 'class' };</script>
    <script src="/assets/js/tailwind.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="/assets/css/brand.css">
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen flex items-center justify-center px-4 transition-colors duration-200">

    <!-- Login card -->
    <div class="w-full max-w-md">

        <!-- App title -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold cm-gradient-text">
                <?= htmlspecialchars($t('app_name'), ENT_QUOTES, 'UTF-8') ?>
            </h1>
            <p class="mt-2 text-gray-500 dark:text-gray-400 text-sm">
                <?= htmlspecialchars($t('login_title'), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <!-- Card -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8">

            <!-- Error message -->
            <?php if ($errorMessage !== null): ?>
                <div class="mb-5 flex items-start gap-3 bg-red-50 border border-red-300 text-red-700 rounded-lg px-4 py-3 text-sm" role="alert">
                    <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                    <span><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <!-- Local login form -->
            <form method="POST" action="/login" novalidate>
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

                <!-- Username -->
                <div class="mb-4">
                    <label for="username" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= htmlspecialchars($t('login_username'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        autocomplete="username"
                        required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm text-sm
                               bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                               focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                               placeholder-gray-400 dark:placeholder-gray-500 transition"
                        placeholder="<?= htmlspecialchars($t('login_username'), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <!-- Password -->
                <div class="mb-6">
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= htmlspecialchars($t('login_password'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        required
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
                    class="w-full cm-btn-primary justify-center py-2.5">
                    <?= htmlspecialchars($t('login_submit'), ENT_QUOTES, 'UTF-8') ?>
                </button>

            </form>

            <?php if ($oidcEnabled): ?>
                <!-- Divider -->
                <div class="relative my-6">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-700"></div>
                    </div>
                    <div class="relative flex justify-center text-xs uppercase">
                        <span class="px-3 bg-white text-gray-400 font-medium">
                            <?= htmlspecialchars($t('login_or'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                </div>

                <!-- SSO button -->
                <a href="/auth/oidc"
                   class="cm-btn-ghost w-full justify-center py-2.5">
                    <!-- Key icon -->
                    <svg class="w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                    <?= htmlspecialchars($t('login_sso'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endif; ?>

        </div><!-- /card -->

    </div><!-- /max-w-md -->

</body>
</html>
