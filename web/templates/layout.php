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

// Always read the user directly from the session here.
// Sub-templates can define a loop variable named $user (e.g. foreach ($users as $user))
// which would contaminate the shared variable scope and cause the wrong role to display.
// Using SessionManager::getUser() directly guarantees we always show the real session user.
$user = SessionManager::getUser();

/** @var Translator $translator */
$t = static fn(string $key, array $r = []): string => isset($translator) ? $translator->t($key, $r) : $key;

$lang = isset($translator) ? $translator->getLang() : 'en';

// The language to switch to is always the other one
$otherLang  = $lang === 'de' ? 'en' : 'de';
$langLabel  = $t('lang_switch');

// Build role badge label and colour – always from the session user
$roleLabel = '';
$roleBadge = '';
if ($user !== null) {
    $roleLabel = match ($user['role'] ?? '') {
        'admin' => $t('role_admin'),
        default => $t('role_view'),
    };
    $roleBadge = match ($user['role'] ?? '') {
        'admin' => 'cm-badge cm-badge-danger',
        default => 'cm-badge cm-badge-running',
    };
}

// Helper to generate active nav class
$navClass = static function (string $path) use ($currentPath): string {
    $isActive = ($currentPath === $path)
        || ($path !== '/' && str_starts_with($currentPath, $path));

    return $isActive
        ? 'cm-nav-active px-3 py-2 rounded-md text-sm font-medium'
        : 'cm-nav-link px-3 py-2 rounded-md text-sm font-medium';
};
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars($t('app_name'), ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Theme IIFE: must run before any CSS paints to prevent flash -->
    <script>(function(){var s=localStorage.getItem('cm-theme'),p=window.matchMedia('(prefers-color-scheme:dark)').matches,t=s||(p?'dark':'light');document.documentElement.classList.add('theme-'+t);})();</script>
    <script src="/assets/js/tailwind.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="/assets/css/brand.css">
</head>
<body class="bg-gray-100 min-h-screen">

<?php if ($user !== null): ?>
    <!-- ------------------------------------------------------------------ -->
    <!-- Top Navigation Bar                                                  -->
    <!-- ------------------------------------------------------------------ -->
    <nav class="bg-gray-800 border-b border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">

                <!-- Left: App name + nav links -->
                <div class="flex items-center space-x-4">
                    <!-- App name / logo -->
                    <a href="/dashboard" class="flex-shrink-0">
                        <span class="cm-app-name"><?= htmlspecialchars($t('app_name'), ENT_QUOTES, 'UTF-8') ?></span>
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
                            <a href="/targets" class="<?= $navClass('/targets') ?>">
                                <?= htmlspecialchars($t('nav_targets'), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <a href="/maintenance" class="<?= $navClass('/maintenance') ?>">
                                <?= htmlspecialchars($t('nav_maintenance'), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: theme toggle, language switch, username, role badge, logout -->
                <div class="flex items-center space-x-2">

                    <!-- Theme toggle -->
                    <button onclick="cmToggleTheme()" aria-label="Theme wechseln" class="cm-theme-toggle">
                        <span class="icon-sun">☀️</span>
                        <span class="icon-moon">🌙</span>
                    </button>

                    <!-- Language switch -->
                    <a href="/lang/<?= htmlspecialchars($otherLang, ENT_QUOTES, 'UTF-8') ?>"
                       title="<?= htmlspecialchars($langLabel, ENT_QUOTES, 'UTF-8') ?>"
                       class="cm-nav-btn px-2.5 py-1.5 text-xs font-semibold tracking-wider">
                        <?= htmlspecialchars($langLabel, ENT_QUOTES, 'UTF-8') ?>
                    </a>

                    <span class="text-gray-300 text-sm hidden sm:inline ml-1">
                        <?= htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="<?= $roleBadge ?>">
                        <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <a href="/logout" class="cm-nav-link px-3 py-2 rounded-md text-sm font-medium">
                        <?= htmlspecialchars($t('logout'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Mobile menu (simple stack) -->
        <div class="md:hidden border-t border-gray-700 px-4 pb-3 pt-2 flex flex-wrap gap-2">
            <a href="/dashboard" class="<?= $navClass('/dashboard') ?>"><?= htmlspecialchars($t('nav_dashboard'), ENT_QUOTES, 'UTF-8') ?></a>
            <a href="/crons"     class="<?= $navClass('/crons') ?>"><?= htmlspecialchars($t('nav_crons'), ENT_QUOTES, 'UTF-8') ?></a>
            <a href="/timeline"  class="<?= $navClass('/timeline') ?>"><?= htmlspecialchars($t('nav_timeline'), ENT_QUOTES, 'UTF-8') ?></a>
            <a href="/swimlane"  class="<?= $navClass('/swimlane') ?>"><?= htmlspecialchars($t('nav_swimlane'), ENT_QUOTES, 'UTF-8') ?></a>
            <a href="/export"    class="<?= $navClass('/export') ?>"><?= htmlspecialchars($t('nav_export'), ENT_QUOTES, 'UTF-8') ?></a>
            <?php if (SessionManager::hasRole('admin')): ?>
                <a href="/users"       class="<?= $navClass('/users') ?>"><?= htmlspecialchars($t('nav_users'), ENT_QUOTES, 'UTF-8') ?></a>
                <a href="/targets"     class="<?= $navClass('/targets') ?>"><?= htmlspecialchars($t('nav_targets'), ENT_QUOTES, 'UTF-8') ?></a>
                <a href="/maintenance" class="<?= $navClass('/maintenance') ?>"><?= htmlspecialchars($t('nav_maintenance'), ENT_QUOTES, 'UTF-8') ?></a>
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
function cmToggleTheme() {
    var el = document.documentElement;
    var isDark = el.classList.contains('theme-dark');
    el.classList.toggle('theme-dark',  !isDark);
    el.classList.toggle('theme-light',  isDark);
    localStorage.setItem('cm-theme', isDark ? 'light' : 'dark');
}
</script>

<!-- -------------------------------------------------------------------- -->
<!-- Footer                                                                 -->
<!-- -------------------------------------------------------------------- -->
<footer class="mt-8 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex flex-wrap items-center justify-end gap-x-6 gap-y-1
                text-xs text-gray-400 dark:text-gray-500">
        <span>Web: <?= htmlspecialchars((string) ($webVersion ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?>, Container: <?= htmlspecialchars((string) ($webContainerVersion ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?></span>
        <span>Agent: <?= htmlspecialchars((string) ($agentVersion ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?>, Container: <?= htmlspecialchars((string) ($agentContainerVersion ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?></span>
    </div>
</footer>
</body>
</html>
