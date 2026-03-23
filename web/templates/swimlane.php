<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Schedule Swimlane Template
 *
 * Renders an interactive swimlane diagram showing the planned fire times of
 * all managed cron jobs within a user-selected time-of-day window and
 * optional day-of-week filter.
 *
 * Variables injected by SwimlaneController:
 *   string  $swimlaneJobsJson  JSON-encoded array of job objects with pre-computed
 *                              fire-time patterns (byDay, allDays, activeDays).
 *   array   $tags              All tag objects [{id, name}] for the filter dropdown.
 *   array   $allTargets        Sorted unique target strings for the filter dropdown.
 *   bool    $debugMode         When true, a timing breakdown is rendered as an HTML comment.
 *   array   $timings           Server-side timing data (only used when $debugMode is true).
 *
 * Variables injected by BaseController::render():
 *   Translator $translator     For i18n strings.
 *   string     $csrf_token     Current session CSRF token.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

use Cronmanager\Web\I18n\Translator;

/** @var Translator $translator */
$t  = static fn(string $k, array $r = []): string =>
    htmlspecialchars($translator->t($k, $r), ENT_QUOTES, 'UTF-8');

// Ensure variables are defined even when template is included standalone
$swimlaneJobsJson = isset($swimlaneJobsJson) ? (string) $swimlaneJobsJson : '[]';
$tags             = isset($tags)             ? (array)  $tags             : [];
$allTargets       = isset($allTargets)       ? (array)  $allTargets       : [];
$debugMode        = isset($debugMode)        ? (bool)   $debugMode        : false;
$timings          = isset($timings)          ? (array)  $timings          : [];
?>

<style>
/* ── Swimlane-specific styles ─────────────────────────────────────────────── */
/* These cannot be expressed as Tailwind utilities because they involve        */
/* dynamic percentage positions set by JS and custom colour tokens.            */

/* Brand-token colours (dark-first; no light-mode overrides needed) */
.sw-labels      { background: var(--cm-bg-card); border-right-color: var(--cm-border); }
.sw-axis        { background: var(--cm-bg-dark);  border-bottom-color: var(--cm-border); }
.sw-axis-pad    { background: var(--cm-bg-card);  border-bottom-color: var(--cm-border); }
.sw-job-hdr-lbl { background: var(--cm-bg-card);  border-bottom-color: var(--cm-border); color: var(--cm-text); }
.sw-target-lbl  { background: var(--cm-bg-dark);  border-top-color: var(--cm-border);    color: var(--cm-muted); }
.sw-tl-row-even { background: var(--cm-bg-card);  border-top-color: var(--cm-border); }
.sw-tl-row-odd  { background: var(--cm-bg-dark);  border-top-color: var(--cm-border); }
.sw-tl-row:hover { background: rgba(129,140,248,.06) !important; }
.sw-job-tl-hdr  { background: var(--cm-bg-deep);  border-bottom-color: var(--cm-border); }
.sw-job-block   { border-bottom-color: var(--cm-border); }
.sw-tick-time   { color: var(--cm-faint); }
.sw-tick-major .sw-tick-time { color: var(--cm-muted); }
.sw-grid-major  { background: var(--cm-border); }
.sw-grid-minor  { background: rgba(30,30,64,.5); }

/* Dark mode — same as base since brand is always dark */
/* (no overrides needed; variables already use dark values) */

/* Layout */
.sw-wrapper {
    display: flex;
    overflow-x: auto;
    overflow-y: auto;
    max-height: calc(100vh - 320px);
    min-height: 320px;
}
.sw-labels {
    flex-shrink: 0;
    width: 240px;
    border-right-width: 1px;
    border-right-style: solid;
    position: sticky;
    left: 0;
    z-index: 20;
}
.sw-axis-pad {
    height: 44px;
    border-bottom-width: 1px;
    border-bottom-style: solid;
    position: sticky;
    top: 0;
    z-index: 21;
}
.sw-job-hdr-lbl {
    height: 30px;
    padding: 0 10px;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    border-bottom-width: 1px;
    border-bottom-style: solid;
    position: sticky;
    top: 44px;
    overflow: hidden;
}
.sw-job-hdr-lbl .sw-cron {
    margin-left: auto;
    font-size: 0.62rem;
    font-family: 'JetBrains Mono', 'Courier New', monospace;
    color: var(--cm-faint);
    flex-shrink: 0;
    white-space: nowrap;
}
.sw-tag-badge {
    font-size: 0.6rem;
    padding: 1px 6px;
    border-radius: 9px;
    font-weight: 600;
    flex-shrink: 0;
    white-space: nowrap;
    border-width: 1px;
    border-style: solid;
}
.sw-target-lbl {
    height: 26px;
    padding: 0 10px 0 22px;
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.7rem;
    border-top-width: 1px;
    border-top-style: solid;
}
.sw-job-block { border-bottom-width: 2px; border-bottom-style: solid; }

/* Timeline column */
.sw-timeline {
    flex: 1;
    min-width: 700px;
    position: relative;
}
.sw-axis {
    height: 44px;
    position: sticky;
    top: 0;
    z-index: 10;
    border-bottom-width: 2px;
    border-bottom-style: solid;
    overflow: hidden;
}
.sw-tick {
    position: absolute;
    top: 0;
    bottom: 0;
    border-left-width: 1px;
    border-left-style: solid;
    border-left-color: transparent;
    padding: 6px 0 0 4px;
    white-space: nowrap;
}
.sw-tick-time { display: block; font-size: 0.7rem; font-weight: 500; }

.sw-grid {
    position: absolute;
    inset: 44px 0 0 0;
    pointer-events: none;
    z-index: 0;
}
.sw-grid-line {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 1px;
}
.sw-now-line {
    position: absolute;
    top: 44px;
    bottom: 0;
    width: 2px;
    background: linear-gradient(to bottom, rgba(192,132,252,.55), var(--cm-violet));
    z-index: 8;
    pointer-events: none;
}
.sw-now-cap {
    position: absolute;
    top: -22px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 0.6rem;
    color: var(--cm-violet);
    font-weight: 700;
    white-space: nowrap;
    padding: 1px 4px;
    border-radius: 3px;
    border: 1px solid rgba(192,132,252,.3);
    background: var(--cm-bg-dark);
}

.sw-job-tl-hdr {
    height: 30px;
    border-bottom-width: 1px;
    border-bottom-style: solid;
}
.sw-tl-row {
    height: 26px;
    position: relative;
    border-top-width: 1px;
    border-top-style: solid;
}

/* Fire marker */
.sw-fire {
    position: absolute;
    top: 4px;
    width: 10px;
    height: 18px;
    margin-left: -5px;
    border-radius: 3px;
    cursor: pointer;
    z-index: 3;
    transition: transform 0.1s, filter 0.1s, opacity 0.1s;
}
.sw-fire:hover {
    transform: scaleY(1.18) scaleX(1.3);
    filter: brightness(1.25);
    z-index: 5;
}
</style>

<!-- ── Page title ──────────────────────────────────────────────────────────── -->
<div class="mb-4 flex items-center justify-between">
    <h1 class="text-2xl font-bold cm-gradient-text">
        <?= $t('swimlane_title') ?>
    </h1>
</div>

<!-- ── Filter bar ─────────────────────────────────────────────────────────── -->
<div class="bg-white dark:bg-gray-800 rounded-lg shadow px-4 py-3 mb-3
            flex flex-wrap gap-x-4 gap-y-2 items-center"
     style="background:var(--cm-bg-card);border-color:var(--cm-border)">

    <!-- From hour -->
    <div class="flex items-center gap-2">
        <label for="selStart" class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
            <?= $t('swimlane_from') ?>
        </label>
        <select id="selStart"
                class="bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600
                       text-gray-800 dark:text-gray-200 text-sm rounded-md px-2 py-1.5 cursor-pointer
                       focus:outline-none focus:ring-2 focus:ring-blue-500">
        </select>
    </div>

    <!-- To hour -->
    <div class="flex items-center gap-2">
        <label for="selEnd" class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
            <?= $t('swimlane_to') ?>
        </label>
        <select id="selEnd"
                class="bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600
                       text-gray-800 dark:text-gray-200 text-sm rounded-md px-2 py-1.5 cursor-pointer
                       focus:outline-none focus:ring-2 focus:ring-blue-500">
        </select>
    </div>

    <!-- Divider -->
    <div class="w-px h-6 bg-gray-200 dark:bg-gray-600 hidden sm:block"></div>

    <!-- Day of week -->
    <div class="flex items-center gap-2">
        <label for="selDay" class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
            <?= $t('swimlane_day') ?>
        </label>
        <select id="selDay"
                class="bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600
                       text-gray-800 dark:text-gray-200 text-sm rounded-md px-2 py-1.5 cursor-pointer
                       focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="-1"><?= $t('swimlane_all_days') ?></option>
            <option value="1"><?= $t('day_monday') ?></option>
            <option value="2"><?= $t('day_tuesday') ?></option>
            <option value="3"><?= $t('day_wednesday') ?></option>
            <option value="4"><?= $t('day_thursday') ?></option>
            <option value="5"><?= $t('day_friday') ?></option>
            <option value="6"><?= $t('day_saturday') ?></option>
            <option value="0"><?= $t('day_sunday') ?></option>
        </select>
    </div>

    <!-- Divider -->
    <div class="w-px h-6 bg-gray-200 dark:bg-gray-600 hidden sm:block"></div>

    <!-- Tag filter -->
    <div class="flex items-center gap-2">
        <label for="selTag" class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
            <?= $t('swimlane_tag') ?>
        </label>
        <select id="selTag"
                class="bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600
                       text-gray-800 dark:text-gray-200 text-sm rounded-md px-2 py-1.5 cursor-pointer
                       focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value=""><?= $t('filter_all_tags') ?></option>
            <?php foreach ($tags as $tag): ?>
                <option value="<?= htmlspecialchars((string) ($tag['name'] ?? $tag), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) ($tag['name'] ?? $tag), ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Target filter -->
    <?php if (count($allTargets) > 1): ?>
    <div class="flex items-center gap-2">
        <label for="selTarget" class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
            <?= $t('swimlane_target') ?>
        </label>
        <select id="selTarget"
                class="bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600
                       text-gray-800 dark:text-gray-200 text-sm rounded-md px-2 py-1.5 cursor-pointer
                       focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value=""><?= $t('filter_all_targets') ?></option>
            <?php foreach ($allTargets as $target): ?>
                <option value="<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <!-- Reset all filters -->
    <div class="ml-auto">
        <button type="button" id="btnResetFilters"
                class="text-sm text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 underline">
            &times; <?= $t('cancel') ?>
        </button>
    </div>
</div>

<!-- ── Swimlane card ──────────────────────────────────────────────────────── -->
<div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden"
     style="background:var(--cm-bg-card);border-color:var(--cm-border)">

    <!-- Info bar -->
    <div class="px-4 py-2 text-xs border-b border-gray-200 dark:border-gray-700
                flex gap-5 flex-wrap items-center
                text-gray-500 dark:text-gray-400">
        <span id="lblRange"></span>
        <span id="lblDay"></span>
        <span id="lblCount" class="text-gray-400 dark:text-gray-500"></span>
        <span id="lblNext" class="ml-auto font-medium" style="color:var(--cm-indigo)"></span>
    </div>

    <!-- Swimlane -->
    <div class="sw-wrapper" id="swWrapper">

        <!-- Labels column -->
        <div class="sw-labels" id="colLabels">
            <div class="sw-axis-pad"></div>
        </div>

        <!-- Timeline column -->
        <div class="sw-timeline" id="colTimeline">
            <div class="sw-axis" id="swAxis"></div>
            <div class="sw-grid" id="swGrid"></div>
            <div class="sw-now-line" id="swNow" style="display:none">
                <div class="sw-now-cap">now</div>
            </div>
            <div id="tlRows"></div>
            <div id="swEmpty"
                 class="hidden text-center py-16 text-gray-400 dark:text-gray-500 text-sm">
                <?= $t('swimlane_no_results') ?><br>
                <span class="text-xs text-gray-300 dark:text-gray-600">
                    <?= $t('swimlane_no_results_hint') ?>
                </span>
            </div>
        </div>

    </div>
</div>

<!-- ── Tooltip ────────────────────────────────────────────────────────────── -->
<div id="swTip"
     class="fixed z-50 hidden
            border rounded-lg shadow-xl px-4 py-3 text-sm
            pointer-events-none min-w-[210px] max-w-[280px]"
     style="background:var(--cm-bg-card);border-color:var(--cm-border);color:var(--cm-muted)">
</div>

<!-- ── Swimlane JavaScript ────────────────────────────────────────────────── -->
<script>
(function () {
"use strict";

/* ─── Job data from PHP ──────────────────────────────────────────────────── */
/** @type {Array} */
const JOBS = <?= $swimlaneJobsJson ?>;

/* ─── Translations injected from PHP ────────────────────────────────────── */
const I18N = <?= json_encode([
    'time_prefix'      => $translator->t('swimlane_time_prefix'),
    'day_prefix'       => $translator->t('swimlane_day_prefix'),
    'all_days'         => $translator->t('swimlane_all_days'),
    'job_singular'     => $translator->t('swimlane_job_singular'),
    'job_plural'       => $translator->t('swimlane_job_plural'),
    'shown'            => $translator->t('swimlane_shown'),
    'next_prefix'      => $translator->t('swimlane_next_prefix'),
    'at'               => $translator->t('swimlane_at'),
    'in_prefix'        => $translator->t('swimlane_in_prefix'),
    'min'              => $translator->t('swimlane_min'),
    'hour_abbr'        => $translator->t('swimlane_hour_abbr'),
    'inactive_warning' => $translator->t('swimlane_inactive_warning'),
    'sw_schedule'      => $translator->t('sw_schedule'),
    'sw_meaning'       => $translator->t('sw_meaning'),
    'sw_fires_at'      => $translator->t('sw_fires_at'),
    'sw_target'        => $translator->t('sw_target'),
    'sw_user'          => $translator->t('sw_user'),
    'sw_tags'          => $translator->t('sw_tags'),
    // Day names indexed as JS getDay() (0 = Sun … 6 = Sat)
    'days' => [
        $translator->t('day_sunday'),
        $translator->t('day_monday'),
        $translator->t('day_tuesday'),
        $translator->t('day_wednesday'),
        $translator->t('day_thursday'),
        $translator->t('day_friday'),
        $translator->t('day_saturday'),
    ],
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;

/* ─── Cookie helpers ─────────────────────────────────────────────────────── */
/**
 * Read a cookie value by name. Returns null when absent or when the browser
 * does not expose document.cookie (e.g. strict blocking).
 * @param {string} name
 * @returns {string|null}
 */
function getCookie(name) {
    try {
        const match = document.cookie.match(
            new RegExp('(?:^|; )' + name.replace(/([.*+?^=!:${}()|[\]/\\])/g, '\\$1') + '=([^;]*)')
        );
        return match ? decodeURIComponent(match[1]) : null;
    } catch (_) { return null; }
}

/**
 * Persist a cookie for 30 days. Silently fails when the browser blocks cookies.
 * @param {string} name
 * @param {string} value
 */
function setCookie(name, value) {
    try {
        const exp = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value)
            + '; expires=' + exp + '; path=/; SameSite=Lax';
    } catch (_) { /* cookies blocked – degrade gracefully */ }
}

/* ─── Colour palette for tags (assigned on first encounter) ─────────────── */
const PALETTE = [
    '#0284c7', '#059669', '#7c3aed', '#b45309',
    '#0d9488', '#db2777', '#ea580c', '#64748b',
    '#0891b2', '#65a30d', '#9333ea', '#dc2626',
];
const tagColorMap = {};
function tagColor(tag) {
    if (!tagColorMap[tag]) {
        tagColorMap[tag] = PALETTE[Object.keys(tagColorMap).length % PALETTE.length];
    }
    return tagColorMap[tag];
}
// Pre-assign colours in stable order so colours are consistent across renders
JOBS.forEach(j => j.tags.forEach(t => tagColor(t)));

/* ─── State ──────────────────────────────────────────────────────────────── */
let startH = 0;    // inclusive hour (0–23)
let endH   = 24;   // exclusive end, where 24 means 24:00 = end of day
let selDay = -1;   // -1 = all days; 0–6 = JS getDay() value

const startMin = () => startH * 60;
const endMin   = () => endH   * 60;
const rangeMin = () => endMin() - startMin();

/** Map a fire time {h,m} to an x-position percentage. */
function toX(h, m) {
    return ((h * 60 + m - startMin()) / rangeMin()) * 100;
}

function padH(n) { return String(n).padStart(2, '0') + ':00'; }
function padT(h, m) {
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
}

/* ─── Dark mode helper ───────────────────────────────────────────────────── */
const isDark = () => document.documentElement.classList.contains('dark');

/* ─── Build time axis ────────────────────────────────────────────────────── */
function buildAxis() {
    const el = document.getElementById('swAxis');
    el.innerHTML = '';
    const rh   = endH - startH;
    const step = rh <= 2 ? 0.5 : rh <= 6 ? 1 : rh <= 12 ? 2 : 3;

    for (let offset = 0; offset <= rh; offset += step) {
        const h = startH + offset;
        if (h > endH) break;

        const x   = (offset / rh) * 100;
        const hh  = Math.floor(h) % 24;
        const mm  = Math.round((h % 1) * 60);
        const div = document.createElement('div');
        div.className = 'sw-tick' + (offset % 3 === 0 ? ' sw-tick-major' : '');
        div.style.left = x + '%';
        const span = document.createElement('span');
        span.className = 'sw-tick-time';
        span.textContent = String(hh).padStart(2, '0') + ':' + String(mm).padStart(2, '0');
        div.appendChild(span);
        el.appendChild(div);
    }
}

/* ─── Build grid lines ───────────────────────────────────────────────────── */
function buildGrid() {
    const el   = document.getElementById('swGrid');
    el.innerHTML = '';
    const rh   = endH - startH;
    const step = rh <= 4 ? 0.5 : 1;

    for (let offset = 0; offset <= rh; offset += step) {
        const x   = (offset / rh) * 100;
        const div = document.createElement('div');
        div.className = 'sw-grid-line ' + (offset % 3 === 0 ? 'sw-grid-major' : 'sw-grid-minor');
        div.style.left = x + '%';
        el.appendChild(div);
    }
}

/* ─── Now line ───────────────────────────────────────────────────────────── */
function buildNowLine() {
    const el  = document.getElementById('swNow');
    const now = new Date();
    const h   = now.getHours();
    const m   = now.getMinutes();
    const nowMin = h * 60 + m;

    const dayOk = selDay === -1 || selDay === now.getDay();
    if (nowMin >= startMin() && nowMin < endMin() && dayOk) {
        el.style.left    = toX(h, m) + '%';
        el.style.display = 'block';
    } else {
        el.style.display = 'none';
    }
}

/* ─── Info bar ───────────────────────────────────────────────────────────── */
function updateInfoBar(visibleCount) {
    const endLabel = endH === 24 ? '24:00' : padH(endH);
    document.getElementById('lblRange').textContent =
        I18N.time_prefix + ' ' + padH(startH) + ' – ' + endLabel;

    document.getElementById('lblDay').textContent =
        I18N.day_prefix + ' ' + (selDay === -1 ? I18N.all_days : I18N.days[selDay]);

    document.getElementById('lblCount').textContent =
        visibleCount + ' ' + (visibleCount !== 1 ? I18N.job_plural : I18N.job_singular) + ' ' + I18N.shown;

    // Find the next upcoming fire time today (within the selected range)
    const now = new Date();
    const curMin = now.getHours() * 60 + now.getMinutes();
    let nextJob = null, nextMin = Infinity;

    JOBS.forEach(job => {
        const fireTimes = selDay === -1 ? job.allDays : (job.byDay[selDay] || []);
        fireTimes.forEach(({ h, m }) => {
            const tm = h * 60 + m;
            if (tm >= startMin() && tm < endMin() && tm > curMin && tm < nextMin) {
                nextMin = tm;
                nextJob = job.name;
            }
        });
    });

    const lblNext = document.getElementById('lblNext');
    if (nextJob) {
        const hh = Math.floor(nextMin / 60), mm = nextMin % 60;
        const diff = nextMin - curMin;
        const diffStr = diff < 60 ? diff + ' ' + I18N.min : (diff / 60).toFixed(1) + ' ' + I18N.hour_abbr;
        lblNext.textContent = I18N.next_prefix + ' ' + nextJob + ' ' + I18N.at + ' ' + padT(hh, mm) + ' (' + I18N.in_prefix + ' ' + diffStr + ')';
    } else {
        lblNext.textContent = '';
    }
}

/* ─── Main render ────────────────────────────────────────────────────────── */
function render() {
    const tagFilter = document.getElementById('selTag').value;
    const tgtFilter = document.getElementById('selTarget') ? document.getElementById('selTarget').value : '';

    buildAxis();
    buildGrid();
    buildNowLine();

    const colLabels = document.getElementById('colLabels');
    const tlRows    = document.getElementById('tlRows');

    // Reset (keep the axis-pad spacer)
    colLabels.innerHTML = '<div class="sw-axis-pad"></div>';
    tlRows.innerHTML    = '';

    let visibleCount = 0;

    JOBS.forEach(job => {
        // ── Tag filter
        if (tagFilter && !job.tags.includes(tagFilter)) return;

        // ── Target filter
        const visibleTargets = job.targets.filter(t => !tgtFilter || t === tgtFilter);
        if (!visibleTargets.length) return;

        // ── Day filter: select the right fire-time array
        const fireTimes = selDay === -1 ? job.allDays : (job.byDay[selDay] || []);

        // ── Hour range filter: keep only fires within [startMin, endMin)
        const inRange = fireTimes.filter(({ h, m }) => {
            const tm = h * 60 + m;
            return tm >= startMin() && tm < endMin();
        });
        if (!inRange.length) return;

        visibleCount++;

        // Primary colour: first tag of the job, or grey if no tags
        const primaryTag   = job.tags[0] || '';
        const color        = primaryTag ? tagColor(primaryTag) : '#64748b';
        const colorAlpha22 = color + '22';
        const colorAlpha55 = color + '55';
        const inactive     = !job.active;

        /* ── Labels column entry ── */
        const lblBlock = document.createElement('div');
        lblBlock.className = 'sw-job-block';

        const lhdr = document.createElement('div');
        lhdr.className = 'sw-job-hdr-lbl';
        lhdr.title = job.cronHuman || job.cron;
        if (inactive) lhdr.style.opacity = '0.55';

        // Job name (truncated via overflow:hidden on parent)
        const nameSpan = document.createElement('span');
        nameSpan.textContent = job.name;
        nameSpan.style.overflow = 'hidden';
        nameSpan.style.textOverflow = 'ellipsis';
        nameSpan.style.whiteSpace = 'nowrap';
        lhdr.appendChild(nameSpan);

        // First tag badge
        if (primaryTag) {
            const badge = document.createElement('span');
            badge.className = 'sw-tag-badge';
            badge.textContent = primaryTag;
            badge.style.background = colorAlpha22;
            badge.style.color = color;
            badge.style.borderColor = colorAlpha55;
            lhdr.appendChild(badge);
        }

        // Cron expression
        const cronSpan = document.createElement('span');
        cronSpan.className = 'sw-cron';
        cronSpan.textContent = job.cron;
        lhdr.appendChild(cronSpan);

        lblBlock.appendChild(lhdr);

        visibleTargets.forEach(target => {
            const tRow = document.createElement('div');
            tRow.className = 'sw-target-lbl';
            tRow.innerHTML = '<span style="opacity:.65">' +
                (target === 'local' ? '🖥' : '🌐') +
                '</span>' + escHtml(target);
            lblBlock.appendChild(tRow);
        });
        colLabels.appendChild(lblBlock);

        /* ── Timeline column entry ── */
        const tlBlock = document.createElement('div');
        tlBlock.className = 'sw-job-block';

        // Blank header row to align with label header
        const hdrRow = document.createElement('div');
        hdrRow.className = 'sw-job-tl-hdr';
        tlBlock.appendChild(hdrRow);

        const now = new Date();
        const curMin = now.getHours() * 60 + now.getMinutes();
        const todayDow = now.getDay();

        visibleTargets.forEach((target, rowIdx) => {
            const row = document.createElement('div');
            row.className = 'sw-tl-row ' + (rowIdx % 2 === 0 ? 'sw-tl-row-even' : 'sw-tl-row-odd');

            inRange.forEach(({ h, m }) => {
                const x = toX(h, m);
                if (x < -0.5 || x > 100.5) return;

                // Dim fires that are already past today (only when showing today)
                const fireMin = h * 60 + m;
                const isPast  = (selDay === -1 || selDay === todayDow) && fireMin <= curMin;

                const marker = document.createElement('div');
                marker.className = 'sw-fire';
                marker.style.left       = Math.max(0, Math.min(100, x)) + '%';
                marker.style.background = color;
                marker.style.opacity    = inactive ? '0.25' : (isPast ? '0.28' : '0.88');
                marker.style.boxShadow  = (isPast || inactive)
                    ? 'none'
                    : '0 0 5px 1px ' + color + '66';

                // Dataset for tooltip
                marker.dataset.job       = job.name;
                marker.dataset.command   = job.command;
                marker.dataset.cronHuman = job.cronHuman || job.cron;
                marker.dataset.cron      = job.cron;
                marker.dataset.target    = target;
                marker.dataset.user      = job.linuxUser;
                marker.dataset.tags      = job.tags.join(', ');
                marker.dataset.color     = color;
                marker.dataset.h         = String(h);
                marker.dataset.m         = String(m);
                marker.dataset.active    = job.active ? '1' : '0';

                marker.addEventListener('mouseenter', showTip);
                marker.addEventListener('mousemove',  moveTip);
                marker.addEventListener('mouseleave', hideTip);

                row.appendChild(marker);
            });

            tlBlock.appendChild(row);
        });

        tlRows.appendChild(tlBlock);
    });

    updateInfoBar(visibleCount);

    const empty = document.getElementById('swEmpty');
    empty.classList.toggle('hidden', visibleCount > 0);
}

/* ─── HTML escape helper ─────────────────────────────────────────────────── */
function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/* ─── Tooltip ────────────────────────────────────────────────────────────── */
const tip = document.getElementById('swTip');

function showTip(e) {
    const d    = e.currentTarget.dataset;
    const h    = Number(d.h), m = Number(d.m);
    const time = padT(h, m);
    const isActive = d.active === '1';

    tip.innerHTML =
        '<div style="font-weight:700;font-size:.9rem;color:' + escHtml(d.color) + ';margin-bottom:6px">' +
            escHtml(d.job) +
        '</div>' +
        row(I18N.sw_schedule, '<span style="color:#38bdf8;font-family:monospace">' + escHtml(d.cron) + '</span>') +
        row(I18N.sw_meaning,  escHtml(d.cronHuman)) +
        row(I18N.sw_fires_at, '<strong>' + time + '</strong>') +
        row(I18N.sw_target,   escHtml(d.target)) +
        row(I18N.sw_user,     escHtml(d.user)) +
        (d.tags ? row(I18N.sw_tags, escHtml(d.tags)) : '') +
        (!isActive ? '<div style="margin-top:6px;color:#f97316;font-size:.75rem">' + escHtml(I18N.inactive_warning) + '</div>' : '');

    tip.classList.remove('hidden');
    moveTip(e);
}
function row(lbl, val) {
    return '<div style="display:flex;justify-content:space-between;gap:12px;margin-top:3px">' +
               '<span style="color:var(--cm-faint)">' + lbl + '</span>' +
               '<span>' + val + '</span>' +
           '</div>';
}
function moveTip(e) {
    const pad = 14, w = tip.offsetWidth, h = tip.offsetHeight;
    let x = e.clientX + pad, y = e.clientY + pad;
    if (x + w > window.innerWidth)  x = e.clientX - w - pad;
    if (y + h > window.innerHeight) y = e.clientY - h - pad;
    tip.style.left = x + 'px';
    tip.style.top  = y + 'px';
}
function hideTip() { tip.classList.add('hidden'); }

/* ─── Build hour selects ─────────────────────────────────────────────────── */
function buildHourSelects() {
    const ss = document.getElementById('selStart');
    const es = document.getElementById('selEnd');

    for (let h = 0; h <= 23; h++) {
        const o = document.createElement('option');
        o.value = h; o.textContent = padH(h);
        if (h === 0) o.selected = true;
        ss.appendChild(o);
    }
    for (let h = 1; h <= 24; h++) {
        const o = document.createElement('option');
        o.value = h;
        o.textContent = h === 24 ? '24:00' : padH(h);
        if (h === 24) o.selected = true;
        es.appendChild(o);
    }

    ss.addEventListener('change', () => {
        startH = Number(ss.value);
        if (endH <= startH) { endH = Math.min(24, startH + 1); es.value = endH; }
        setCookie('cronmgr_sl_start', String(startH));
        setCookie('cronmgr_sl_end',   String(endH));
        render();
    });
    es.addEventListener('change', () => {
        endH = Number(es.value);
        if (startH >= endH) { startH = Math.max(0, endH - 1); ss.value = startH; }
        setCookie('cronmgr_sl_start', String(startH));
        setCookie('cronmgr_sl_end',   String(endH));
        render();
    });
}

/* ─── Cookie-backed state restore ────────────────────────────────────────── */
/**
 * After the hour selects have been built, restore all filter values from
 * cookies (if present). State variables (startH, endH, selDay) are updated
 * to match so the first render() call reflects the restored state.
 */
function restoreFromCookies() {
    const ss = document.getElementById('selStart');
    const es = document.getElementById('selEnd');

    const savedStart = getCookie('cronmgr_sl_start');
    const savedEnd   = getCookie('cronmgr_sl_end');
    const savedDay   = getCookie('cronmgr_sl_day');
    const savedTag   = getCookie('cronmgr_sl_tag');
    const savedTgt   = getCookie('cronmgr_sl_target');

    if (savedStart !== null) {
        const v = Math.max(0, Math.min(23, Number(savedStart)));
        if (!isNaN(v)) { startH = v; ss.value = v; }
    }
    if (savedEnd !== null) {
        const v = Math.max(1, Math.min(24, Number(savedEnd)));
        if (!isNaN(v) && v > startH) { endH = v; es.value = v; }
    }
    if (savedDay !== null) {
        const v = Number(savedDay);
        if (!isNaN(v) && v >= -1 && v <= 6) {
            selDay = v;
            document.getElementById('selDay').value = v;
        }
    }
    if (savedTag !== null) {
        const el = document.getElementById('selTag');
        if ([...el.options].some(o => o.value === savedTag)) el.value = savedTag;
    }
    const selTargetEl = document.getElementById('selTarget');
    if (selTargetEl && savedTgt !== null) {
        if ([...selTargetEl.options].some(o => o.value === savedTgt)) selTargetEl.value = savedTgt;
    }
}

/* ─── Init ───────────────────────────────────────────────────────────────── */
buildHourSelects();
restoreFromCookies();
render();

document.getElementById('selDay').addEventListener('change', e => {
    selDay = Number(e.target.value);
    setCookie('cronmgr_sl_day', String(selDay));
    render();
});
document.getElementById('selTag').addEventListener('change', e => {
    setCookie('cronmgr_sl_tag', e.target.value);
    render();
});
const selTarget = document.getElementById('selTarget');
if (selTarget) {
    selTarget.addEventListener('change', e => {
        setCookie('cronmgr_sl_target', e.target.value);
        render();
    });
}

// Reset button – clears all swimlane filter cookies and resets controls
document.getElementById('btnResetFilters').addEventListener('click', () => {
    const ss = document.getElementById('selStart');
    const es = document.getElementById('selEnd');

    startH = 0; endH = 24; selDay = -1;
    ss.value = 0; es.value = 24;
    document.getElementById('selDay').value = -1;
    document.getElementById('selTag').value = '';
    const selTargetEl = document.getElementById('selTarget');
    if (selTargetEl) selTargetEl.value = '';

    ['cronmgr_sl_start','cronmgr_sl_end','cronmgr_sl_day',
     'cronmgr_sl_tag','cronmgr_sl_target'].forEach(n => setCookie(n, ''));

    render();
});

// Re-render when dark mode is toggled so grid/axis colours update
const origToggle = window.toggleDarkMode;
if (typeof origToggle === 'function') {
    window.toggleDarkMode = function () {
        origToggle();
        render();
    };
}

})();
</script>

<?php if ($debugMode && !empty($timings)): ?>
<!--
  Swimlane – Server-side timing (debug=1)
  ─────────────────────────────────────────────────────────────────
  Agent HTTP calls : <?= number_format((float) ($timings['agent_ms']   ?? 0), 2) ?> ms
  Schedule compute : <?= number_format((float) ($timings['compute_ms'] ?? 0), 2) ?> ms
  JSON encode      : <?= number_format((float) ($timings['json_ms']    ?? 0), 2) ?> ms
  Total (server)   : <?= number_format((float) ($timings['total_ms']   ?? 0), 2) ?> ms
  ─────────────────────────────────────────────────────────────────
  Jobs rendered    : <?= (int) ($timings['jobs']       ?? 0) ?>

  APCu available   : <?= !empty($timings['apcu']) ? 'yes' : 'no' ?>

  Cache hits       : <?= (int) ($timings['cache_hits'] ?? 0) ?>

  Cache misses     : <?= (int) ($timings['cache_miss'] ?? 0) ?>

  JSON payload     : <?= number_format((int) ($timings['payload_b']  ?? 0)) ?> bytes
  ─────────────────────────────────────────────────────────────────
-->
<?php endif; ?>
