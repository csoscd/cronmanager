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
$otherLang = $lang === 'de' ? 'en' : 'de';
$langLabel = $t('lang_switch');

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

// Agent-aware URL suffix (?agent_id=X) for sidebar navigation links.
// Job IDs are only unique per agent, so links must carry the agent context
// to avoid showing the wrong job when a URL is shared or session expires.
$agentId  = isset($agentId)        && is_int($agentId)        ? $agentId        : 0;
$agSuffix = $agentId > 0 ? '?agent_id=' . $agentId : '';

// Returns the sidebar nav-item CSS classes for a given path
$navClass = static function (string $path) use ($currentPath): string {
    $isActive = ($currentPath === $path)
        || ($path !== '/' && str_starts_with($currentPath, $path));

    return $isActive
        ? 'cm-sidebar-item cm-sidebar-item--active'
        : 'cm-sidebar-item';
};

// Renders an inline SVG icon (20×20, stroke-based, Heroicons-style)
$icon = static function (string $name): string {
    $paths = [
        'dashboard' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v9a1 1 0 001 1h3m10-10l2 2m-2-2v9a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',
        'list'      => '<rect x="3" y="3" width="14" height="14" rx="2"/><line x1="7" y1="7" x2="13" y2="7"/><line x1="7" y1="10" x2="13" y2="10"/><line x1="7" y1="13" x2="11" y2="13"/>',
        'plus'      => '<circle cx="10" cy="10" r="7"/><line x1="10" y1="7" x2="10" y2="13"/><line x1="7" y1="10" x2="13" y2="10"/>',
        'import'    => '<line x1="10" y1="3" x2="10" y2="12"/><polyline points="6,8 10,12 14,8"/><line x1="4" y1="15" x2="16" y2="15"/>',
        'calendar'  => '<rect x="3" y="4" width="14" height="13" rx="2"/><line x1="3" y1="8" x2="17" y2="8"/><line x1="7" y1="4" x2="7" y2="8"/><line x1="13" y1="4" x2="13" y2="8"/>',
        'swimlane'  => '<line x1="3" y1="5" x2="17" y2="5"/><line x1="3" y1="10" x2="17" y2="10"/><line x1="3" y1="15" x2="17" y2="15"/><line x1="7" y1="3" x2="7" y2="17"/><line x1="13" y1="3" x2="13" y2="17"/>',
        'clock'     => '<circle cx="10" cy="10" r="7"/><polyline points="10,7 10,10 12.5,12.5"/>',
        'cog'       => '<circle cx="10" cy="10" r="3"/><path stroke-linecap="round" d="M10 2v1.5M10 16.5V18M2 10h1.5M16.5 10H18M4.22 4.22l1.06 1.06M14.72 14.72l1.06 1.06M4.22 15.78l1.06-1.06M14.72 5.28l1.06-1.06"/>',
        'users'     => '<circle cx="7" cy="7" r="3"/><path stroke-linecap="round" d="M1 18c0-3.3 2.7-6 6-6"/><circle cx="15" cy="9" r="2.5"/><path stroke-linecap="round" d="M11 18c0-2.8 1.8-5 4-5s4 2.2 4 5"/>',
        'export'    => '<line x1="10" y1="12" x2="10" y2="3"/><polyline points="6,7 10,3 14,7"/><line x1="4" y1="15" x2="16" y2="15"/>',
        'key'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" transform="scale(0.83) translate(0,0)"/>',
        'shield'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" transform="scale(0.83) translate(0,0)"/>',
        'user'      => '<circle cx="10" cy="7" r="3"/><path stroke-linecap="round" d="M3 18c0-3.9 3.1-7 7-7s7 3.1 7 7"/>',
    ];
    $d = $paths[$name] ?? '';
    return sprintf(
        '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">%s</svg>',
        $d
    );
};
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars($t('app_name'), ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Theme IIFE: must run before any CSS paints to prevent flash -->
    <script>(function(){var s=localStorage.getItem('cm-theme'),p=window.matchMedia('(prefers-color-scheme:dark)').matches,t=s||(p?'dark':'light');document.documentElement.classList.add('theme-'+t);})();</script>
    <?php
    // Cache-busting: assets change only with releases, so the web version is a
    // stable fingerprint. Lets browsers cache aggressively yet pick up new
    // assets immediately after a deployment.
    $assetVer = rawurlencode((string) ($webVersion ?? ''));
    ?>
    <!-- Pre-built, purged Tailwind stylesheet (web/tailwind.config.js) –
         replaces the former ~400 KB Play-CDN runtime compiler that re-scanned
         the DOM and regenerated all CSS on every page load -->
    <link rel="stylesheet" href="/assets/css/tailwind.css?v=<?= $assetVer ?>">
    <link rel="stylesheet" href="/assets/css/brand.css?v=<?= $assetVer ?>">
    <!-- cm-fetch.js defines cmFetch/cmPoll, which the pages' inline IIFEs call
         during body parsing – it must therefore load synchronously BEFORE the
         content (previously it sat at the end of the body, so every inline
         cmPoll()/cmFetch() call threw a ReferenceError and the AJAX
         auto-refresh silently never ran). At 4.7 KB from the same origin the
         blocking cost is negligible. -->
    <script src="/assets/js/cm-fetch.js?v=<?= $assetVer ?>"></script>
</head>
<body class="bg-gray-100">

<?php if ($user !== null): ?>

<div class="cm-app-shell">

    <!-- Mobile: backdrop closes sidebar when tapped.                      -->
    <!-- Must be inside cm-app-shell so it shares the same stacking        -->
    <!-- context as the sidebar (z-index 40 > backdrop z-index 35).        -->
    <div id="cm-sidebar-backdrop" onclick="cmCloseSidebar()"></div>

    <!-- ================================================================ -->
    <!-- Sidebar                                                           -->
    <!-- ================================================================ -->
    <aside id="cm-sidebar" class="cm-sidebar"
           aria-label="<?= htmlspecialchars($t('app_name'), ENT_QUOTES, 'UTF-8') ?> navigation">

        <!-- Logo / app name -->
        <div class="cm-sidebar-logo">
            <a href="/dashboard<?= $agSuffix ?>" class="cm-sidebar-logo-link">
                <svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true">
                    <rect width="28" height="28" rx="8" fill="url(#cm-logo-grad)"/>
                    <defs>
                        <linearGradient id="cm-logo-grad" x1="0" y1="0" x2="28" y2="28">
                            <stop offset="0%"   stop-color="#c084fc"/>
                            <stop offset="50%"  stop-color="#818cf8"/>
                            <stop offset="100%" stop-color="#38bdf8"/>
                        </linearGradient>
                    </defs>
                    <text x="5" y="20" font-size="13" font-weight="800" fill="white" font-family="monospace">CM</text>
                </svg>
                <span class="cm-app-name"><?= htmlspecialchars($t('app_name'), ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        </div>

        <!-- Agent switcher: visible only when 2+ enabled agents exist -->
        <?php
        $enabledAgents  = isset($enabledAgents)  ? (array) $enabledAgents  : [];
        $selectedAgent  = isset($selectedAgent)   ? (array) $selectedAgent  : [];
        $selectedAgId   = (int) ($selectedAgent['id'] ?? 0);
        ?>
        <?php if (count($enabledAgents) > 1): ?>
        <div class="px-3 py-2.5 border-b" style="border-color:var(--cm-border)">
            <div class="text-xs font-medium mb-1.5" style="color:var(--cm-text-muted)">
                <?= htmlspecialchars($t('agent_switch'), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <form method="POST" action="/agent/select">
                <input type="hidden" name="_csrf"
                       value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <select name="agent_id" onchange="this.form.submit()"
                        class="w-full rounded-lg border text-sm px-2 py-1.5 focus:outline-none"
                        style="background:var(--cm-input-bg);border-color:var(--cm-border);color:var(--cm-text)">
                    <?php foreach ($enabledAgents as $ag): ?>
                        <option value="<?= (int) $ag['id'] ?>"
                            <?= ((int) $ag['id'] === $selectedAgId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $ag['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php elseif (!empty($selectedAgent)): ?>
        <div class="px-3 py-2 border-b" style="border-color:var(--cm-border)">
            <div class="text-xs font-medium truncate" style="color:var(--cm-text-muted)">
                <?= htmlspecialchars((string) ($selectedAgent['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Navigation -->
        <nav class="cm-sidebar-nav">

            <!-- OVERVIEW -->
            <div class="cm-sidebar-group">
                <div class="cm-sidebar-group-label">
                    <?= htmlspecialchars($t('nav_section_overview'), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <a href="/dashboard<?= $agSuffix ?>" class="<?= $navClass('/dashboard') ?>">
                    <?= $icon('dashboard') ?>
                    <?= htmlspecialchars($t('nav_dashboard'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>

            <div class="cm-sidebar-divider"></div>

            <!-- JOBS -->
            <div class="cm-sidebar-group">
                <div class="cm-sidebar-group-label">
                    <?= htmlspecialchars($t('nav_section_jobs'), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <a href="/crons<?= $agSuffix ?>" class="<?= $navClass('/crons') ?>">
                    <?= $icon('list') ?>
                    <?= htmlspecialchars($t('nav_crons'), ENT_QUOTES, 'UTF-8') ?>
                </a>
                <?php if (SessionManager::hasRole('admin')): ?>
                    <a href="/crons/new<?= $agSuffix ?>" class="<?= $navClass('/crons/new') ?>">
                        <?= $icon('plus') ?>
                        <?= htmlspecialchars($t('nav_new_job'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <a href="/crons/import<?= $agSuffix ?>" class="<?= $navClass('/crons/import') ?>">
                        <?= $icon('import') ?>
                        <?= htmlspecialchars($t('nav_import'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="cm-sidebar-divider"></div>

            <!-- MONITORING -->
            <div class="cm-sidebar-group">
                <div class="cm-sidebar-group-label">
                    <?= htmlspecialchars($t('nav_section_monitoring'), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <a href="/timeline<?= $agSuffix ?>" class="<?= $navClass('/timeline') ?>">
                    <?= $icon('calendar') ?>
                    <?= htmlspecialchars($t('nav_timeline'), ENT_QUOTES, 'UTF-8') ?>
                </a>
                <a href="/swimlane<?= $agSuffix ?>" class="<?= $navClass('/swimlane') ?>">
                    <?= $icon('swimlane') ?>
                    <?= htmlspecialchars($t('nav_swimlane'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>

            <?php if (SessionManager::hasRole('admin')): ?>

                <div class="cm-sidebar-divider"></div>

                <!-- CONFIGURATION -->
                <div class="cm-sidebar-group">
                    <div class="cm-sidebar-group-label">
                        <?= htmlspecialchars($t('nav_section_configuration'), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <a href="/maintenance<?= $agSuffix ?>" class="<?= $navClass('/maintenance') ?>">
                        <?= $icon('clock') ?>
                        <?= htmlspecialchars($t('nav_maintenance'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <a href="/settings" class="<?= $navClass('/settings') ?>">
                        <?= $icon('cog') ?>
                        <?= htmlspecialchars($t('nav_housekeeping'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>

                <div class="cm-sidebar-divider"></div>

                <!-- ADMINISTRATION -->
                <div class="cm-sidebar-group">
                    <div class="cm-sidebar-group-label">
                        <?= htmlspecialchars($t('nav_section_admin'), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <a href="/users" class="<?= $navClass('/users') ?>">
                        <?= $icon('users') ?>
                        <?= htmlspecialchars($t('nav_users'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <a href="/audit<?= $agSuffix ?>" class="<?= $navClass('/audit') ?>">
                        <?= $icon('shield') ?>
                        <?= htmlspecialchars($t('nav_audit_log'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>

            <?php endif; ?>

            <div class="cm-sidebar-divider"></div>

            <!-- TOOLS -->
            <div class="cm-sidebar-group">
                <div class="cm-sidebar-group-label">
                    <?= htmlspecialchars($t('nav_section_tools'), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <a href="/export<?= $agSuffix ?>" class="<?= $navClass('/export') ?>">
                    <?= $icon('export') ?>
                    <?= htmlspecialchars($t('nav_export'), ENT_QUOTES, 'UTF-8') ?>
                </a>
                <a href="/api-keys" class="<?= $navClass('/api-keys') ?>">
                    <?= $icon('key') ?>
                    API Keys
                </a>
                <a href="/profile" class="<?= $navClass('/profile') ?>">
                    <?= $icon('user') ?>
                    <?= htmlspecialchars($t('nav_profile'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>

        </nav>

        <!-- Footer: user info, theme toggle, language, logout -->
        <div class="cm-sidebar-footer">
            <div class="cm-sidebar-user">
                <span class="cm-sidebar-username">
                    <?= htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="<?= $roleBadge ?>">
                    <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <div class="cm-sidebar-footer-actions">
                <button onclick="cmToggleTheme()"
                        aria-label="<?= htmlspecialchars($t('dark_mode_toggle'), ENT_QUOTES, 'UTF-8') ?>"
                        class="cm-theme-toggle">
                    <span class="icon-sun">☀️</span>
                    <span class="icon-moon">🌙</span>
                </button>
                <a href="/lang/<?= htmlspecialchars($otherLang, ENT_QUOTES, 'UTF-8') ?>"
                   title="<?= htmlspecialchars($langLabel, ENT_QUOTES, 'UTF-8') ?>"
                   class="cm-nav-btn px-2.5 py-1.5 text-xs font-semibold tracking-wider">
                    <?= htmlspecialchars($langLabel, ENT_QUOTES, 'UTF-8') ?>
                </a>
                <a href="/logout" class="cm-nav-btn px-2.5 py-1.5 text-xs font-semibold tracking-wider">
                    <?= htmlspecialchars($t('logout'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </div>

    </aside>

    <!-- ================================================================ -->
    <!-- Main column                                                       -->
    <!-- ================================================================ -->
    <div class="cm-main-col">

        <!-- Topbar: hamburger (mobile only) + page title -->
        <div class="cm-topbar">
            <button id="cm-hamburger"
                    class="cm-hamburger"
                    onclick="cmOpenSidebar()"
                    aria-label="Open navigation"
                    aria-expanded="false"
                    aria-controls="cm-sidebar">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none"
                     stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <line x1="1.5" y1="4"  x2="16.5" y2="4"/>
                    <line x1="1.5" y1="9"  x2="16.5" y2="9"/>
                    <line x1="1.5" y1="14" x2="16.5" y2="14"/>
                </svg>
            </button>
            <span class="cm-topbar-title">
                <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <!-- Page content (templates render inside max-w-7xl for readability) -->
        <main class="cm-content">
            <div class="max-w-7xl mx-auto">
                <?= $content ?>
            </div>
        </main>

        <!-- Footer -->
        <footer class="cm-layout-footer">
            <span>Web: <?= htmlspecialchars((string) ($webVersion ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?>, Container: <?= htmlspecialchars((string) ($webContainerVersion ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?></span>
            <span>Agent: <?= htmlspecialchars((string) ($agentVersion ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?>, Container: <?= htmlspecialchars((string) ($agentContainerVersion ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?></span>
            <?php if (isset($perfData) && is_array($perfData)): ?>
            <span title="<?= htmlspecialchars($translator->t('perf_footer_title'), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($translator->t('perf_footer_label', [
                    'api'     => number_format((float) ($perfData['request_ms'] ?? 0), 1),
                    'db'      => number_format((float) ($perfData['db_ms']      ?? 0), 1),
                    'queries' => (int) ($perfData['db_queries'] ?? 0),
                ]), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <?php endif; ?>
        </footer>

    </div><!-- /cm-main-col -->

</div><!-- /cm-app-shell -->

<?php else: ?>

<!-- Unauthenticated fallback (setup page, error pages) -->
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?= $content ?>
</main>

<?php endif; ?>

<script>
function cmToggleTheme() {
    var el = document.documentElement;
    var isDark = el.classList.contains('theme-dark');
    el.classList.toggle('theme-dark',  !isDark);
    el.classList.toggle('theme-light',  isDark);
    localStorage.setItem('cm-theme', isDark ? 'light' : 'dark');
}

function cmOpenSidebar() {
    document.getElementById('cm-sidebar').classList.add('cm-sidebar--open');
    document.getElementById('cm-sidebar-backdrop').classList.add('cm-backdrop--visible');
    document.getElementById('cm-hamburger').setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
}

function cmCloseSidebar() {
    var sidebar = document.getElementById('cm-sidebar');
    if (!sidebar) return;
    sidebar.classList.remove('cm-sidebar--open');
    document.getElementById('cm-sidebar-backdrop').classList.remove('cm-backdrop--visible');
    document.getElementById('cm-hamburger').setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) { if (e.key === 'Escape') cmCloseSidebar(); });
</script>

</body>
</html>
