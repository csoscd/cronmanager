<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Base Layout Template
 *
 * Variables available in this template:
 *   string       $title       – Page title (injected by the controller)
 *   string       $content     – Main page content HTML (injected by the controller)
 *   array|null   $user        – Authenticated user array from SessionManager, or null
 *   string       $currentPath – Current request path for active nav highlighting
 *   Translator   $translator  – Translator instance for i18n strings
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

use Cronmanager\Web\I18n\Translator;
use Cronmanager\Web\Session\SessionManager;

// Ensure variables have safe defaults when included directly
$title       = isset($title)       ? (string) $title       : 'Cronmanager';
$content     = isset($content)     ? (string) $content     : '';
$currentPath = isset($currentPath) ? (string) $currentPath : '/';
$user        = isset($user)        ? (array)  $user        : SessionManager::getUser();

/** @var Translator $translator */
$t = static fn(string $key, array $r = []): string => isset($translator) ? $translator->t($key, $r) : $key;

$lang = isset($translator) ? $translator->getLang() : 'en';

// The language to switch to is always the other one
$otherLang  = $lang === 'de' ? 'en' : 'de';
$langLabel  = $t('lang_switch');

// Build role badge label and colour
$roleLabel = '';
$roleBadge = '';
if ($user !== null) {
    $roleLabel = match ($user['role'] ?? '') {
        'admin' => $t('role_admin'),
        default => $t('role_view'),
    };
    $roleBadge = match ($user['role'] ?? '') {
        'admin' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        default => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    };
}

// Helper to generate active nav class
$navClass = static function (string $path) use ($currentPath): string {
    $isActive = ($currentPath === $path)
        || ($path !== '/' && str_starts_with($currentPath, $path));

    return $isActive
        ? 'text-white bg-gray-700 dark:bg-gray-600 px-3 py-2 rounded-md text-sm font-medium'
        : 'text-gray-300 hover:text-white hover:bg-gray-700 dark:hover:bg-gray-600 px-3 py-2 rounded-md text-sm font-medium transition-colors';
};
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars($t('app_name'), ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Apply dark mode before first paint to prevent flash -->
    <script>
    (function () {
        var stored = localStorage.getItem('darkMode');
        var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (stored === 'true' || (stored === null && prefersDark)) {
            document.documentElement.classList.add('dark');
        }
        // Configure Tailwind dark mode (must be set before CDN initialises)
        window.tailwind = window.tailwind || {};
        window.tailwind.config = { darkMode: 'class' };
    })();
    </script>
    <script src="/assets/js/tailwind.min.js"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen transition-colors duration-200">

<?php if ($user !== null): ?>
    <!-- ------------------------------------------------------------------ -->
    <!-- Top Navigation Bar                                                  -->
    <!-- ------------------------------------------------------------------ -->
    <nav class="bg-gray-800 dark:bg-gray-950 shadow-md border-b border-gray-700 dark:border-gray-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">

                <!-- Left: App name + nav links -->
                <div class="flex items-center space-x-4">
                    <!-- App name / logo -->
                    <a href="/dashboard" class="flex-shrink-0 text-white font-bold text-lg tracking-wide">
                        <?= htmlspecialchars($t('app_name'), ENT_QUOTES, 'UTF-8') ?>
                    </a>

                    <!-- Nav links -->
                    <div class="hidden md:flex items-center space-x-1 ml-6">
                        <a href="/dashboard" class="<?= $navClass('/dashboard') ?>">
                            <?= htmlspecialchars($t('nav_dashboard'), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <a href="/crons" class="<?= $navClass('/crons') ?>">
                            <?= htmlspecialchars($t('nav_crons'), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <a href="/timeline" class="<?= $navClass('/timeline') ?>">
                            <?= htmlspecialchars($t('nav_timeline'), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <a href="/swimlane" class="<?= $navClass('/swimlane') ?>">
                            <?= htmlspecialchars($t('nav_swimlane'), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <a href="/export" class="<?= $navClass('/export') ?>">
                            <?= htmlspecialchars($t('nav_export'), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <?php if (SessionManager::hasRole('admin')): ?>
                            <a href="/users" class="<?= $navClass('/users') ?>">
                                <?= htmlspecialchars($t('nav_users'), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: dark mode toggle, language switch, username, role badge, logout -->
                <div class="flex items-center space-x-2">

                    <!-- Dark mode toggle button -->
                    <button id="dark-mode-btn"
                            onclick="toggleDarkMode()"
                            title="<?= htmlspecialchars($t('dark_mode_toggle'), ENT_QUOTES, 'UTF-8') ?>"
                            class="text-gray-300 hover:text-white hover:bg-gray-700 dark:hover:bg-gray-600
                                   p-2 rounded-md transition-colors focus:outline-none focus:ring-2
                                   focus:ring-gray-500">
                        <!-- Sun icon (shown in dark mode) -->
                        <svg id="icon-sun" class="w-5 h-5 hidden" fill="none" viewBox="0 0 24 24"
                             stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M12 3v1m0 16v1m8.66-9h-1M4.34 12h-1m15.07-6.07-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z"/>
                        </svg>
                        <!-- Moon icon (shown in light mode) -->
                        <svg id="icon-moon" class="w-5 h-5" fill="none" viewBox="0 0 24 24"
                             stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>
                        </svg>
                    </button>

                    <!-- Language switch -->
                    <a href="/lang/<?= htmlspecialchars($otherLang, ENT_QUOTES, 'UTF-8') ?>"
                       title="<?= htmlspecialchars($langLabel, ENT_QUOTES, 'UTF-8') ?>"
                       class="text-gray-300 hover:text-white hover:bg-gray-700 dark:hover:bg-gray-600
                              px-2.5 py-1.5 rounded-md text-xs font-semibold tracking-wider
                              border border-gray-600 transition-colors focus:outline-none
                              focus:ring-2 focus:ring-gray-500">
                        <?= htmlspecialchars($langLabel, ENT_QUOTES, 'UTF-8') ?>
                    </a>

                    <span class="text-gray-300 text-sm hidden sm:inline ml-1">
                        <?= htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $roleBadge ?>">
                        <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <a href="/logout"
                       class="text-gray-300 hover:text-white hover:bg-gray-700 dark:hover:bg-gray-600
                              px-3 py-2 rounded-md text-sm font-medium transition-colors">
                        <?= htmlspecialchars($t('logout'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Mobile menu (simple stack) -->
        <div class="md:hidden border-t border-gray-700 dark:border-gray-800 px-4 pb-3 pt-2 flex flex-wrap gap-2">
            <a href="/dashboard" class="<?= $navClass('/dashboard') ?>"><?= htmlspecialchars($t('nav_dashboard'), ENT_QUOTES, 'UTF-8') ?></a>
            <a href="/crons"     class="<?= $navClass('/crons') ?>"><?= htmlspecialchars($t('nav_crons'), ENT_QUOTES, 'UTF-8') ?></a>
            <a href="/timeline"  class="<?= $navClass('/timeline') ?>"><?= htmlspecialchars($t('nav_timeline'), ENT_QUOTES, 'UTF-8') ?></a>
            <a href="/swimlane"  class="<?= $navClass('/swimlane') ?>"><?= htmlspecialchars($t('nav_swimlane'), ENT_QUOTES, 'UTF-8') ?></a>
            <a href="/export"    class="<?= $navClass('/export') ?>"><?= htmlspecialchars($t('nav_export'), ENT_QUOTES, 'UTF-8') ?></a>
            <?php if (SessionManager::hasRole('admin')): ?>
                <a href="/users" class="<?= $navClass('/users') ?>"><?= htmlspecialchars($t('nav_users'), ENT_QUOTES, 'UTF-8') ?></a>
            <?php endif; ?>
        </div>
    </nav>
<?php endif; ?>

<!-- -------------------------------------------------------------------- -->
<!-- Main content area                                                      -->
<!-- -------------------------------------------------------------------- -->
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?= $content ?>
</main>

<script>
/**
 * Initialise the dark mode icon state to match the current HTML class.
 * Called once on page load and after each toggle.
 */
function updateDarkModeIcons() {
    var isDark = document.documentElement.classList.contains('dark');
    var sun  = document.getElementById('icon-sun');
    var moon = document.getElementById('icon-moon');
    if (sun)  sun.classList.toggle('hidden', !isDark);
    if (moon) moon.classList.toggle('hidden',  isDark);
}

/**
 * Toggle dark mode on/off, persist the preference in localStorage.
 */
function toggleDarkMode() {
    var html   = document.documentElement;
    var isDark = html.classList.toggle('dark');
    localStorage.setItem('darkMode', isDark ? 'true' : 'false');
    updateDarkModeIcons();
}

// Set correct icon on initial load
updateDarkModeIcons();
</script>

</body>
</html>
