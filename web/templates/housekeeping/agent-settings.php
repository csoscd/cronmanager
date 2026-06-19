<?php

declare(strict_types=1);

/**
 * Cronmanager – Agent Notification & Integration Settings
 *
 * Variables injected by MaintenanceController::agentSettings():
 *   array  $settings     Effective settings array (mail, telegram, influxdb, notifications)
 *   array  $otherAgents  Other enabled agents for the copy dropdown
 *   bool   $prefilled    Whether the form was pre-filled from a source agent
 *   int    $sourceId     Source agent ID (0 if not pre-filling)
 *   string $csrf_token   CSRF token
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

$settings    = isset($settings)    && is_array($settings)    ? $settings    : [];
$otherAgents = isset($otherAgents) && is_array($otherAgents) ? $otherAgents : [];
$prefilled   = isset($prefilled)   && $prefilled;

$mail   = is_array($settings['mail']          ?? null) ? $settings['mail']          : [];
$tg     = is_array($settings['telegram']      ?? null) ? $settings['telegram']      : [];
$influx = is_array($settings['influxdb']      ?? null) ? $settings['influxdb']      : [];
$notif  = is_array($settings['notifications'] ?? null) ? $settings['notifications'] : [];

$sv = static fn(string $section, string $key, mixed $default = ''): mixed
    => $settings[$section][$key] ?? $default;

/** Helper: checked attribute when bool is true */
$checked = static fn(mixed $v): string => $v ? ' checked' : '';
/** Helper: selected attribute for encryption <select> */
$selEnc  = static fn(string $val, string $current): string
    => ($val === $current) ? ' selected' : '';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-8">

    <!-- ── Page header ────────────────────────────────────────────────────────── -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold" style="color:var(--cm-text)">
                <?= htmlspecialchars($t('agent_settings_title'), ENT_QUOTES, 'UTF-8') ?>
            </h1>
            <p class="mt-1 text-sm" style="color:var(--cm-text-muted)">
                <?= htmlspecialchars($t('agent_settings_desc'), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
        <a href="/settings" class="text-sm font-medium cm-btn-secondary px-3 py-1.5 rounded-lg">
            &larr; <?= htmlspecialchars($t('cancel'), ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>

    <!-- ── Flash: success ─────────────────────────────────────────────────────── -->
    <?php
    $flashSuccess = \Cronmanager\Web\Session\SessionManager::get('_flash_success');
    if ($flashSuccess !== null):
        \Cronmanager\Web\Session\SessionManager::remove('_flash_success');
    ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(34,197,94,.12);color:#16a34a;border:1px solid rgba(34,197,94,.25)">
            <?= htmlspecialchars((string) $flashSuccess, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- ── Flash: error ───────────────────────────────────────────────────────── -->
    <?php
    $flashError = \Cronmanager\Web\Session\SessionManager::get('_flash_error');
    if ($flashError !== null):
        \Cronmanager\Web\Session\SessionManager::remove('_flash_error');
    ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.2)">
            <?= htmlspecialchars((string) $flashError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- ── Pre-fill notice ────────────────────────────────────────────────────── -->
    <?php if ($prefilled): ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(245,158,11,.1);color:#d97706;border:1px solid rgba(245,158,11,.25)">
            <?= htmlspecialchars($t('agent_settings_prefilled_notice'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Copy settings from another agent
         ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($otherAgents !== []): ?>
    <section class="cm-card rounded-xl p-6 space-y-4">
        <h2 class="text-lg font-semibold" style="color:var(--cm-text)">
            <?= htmlspecialchars($t('agent_settings_copy_title'), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p class="text-sm" style="color:var(--cm-text-muted)">
            <?= htmlspecialchars($t('agent_settings_copy_desc'), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <div class="flex flex-wrap gap-3 items-end">

            <!-- Pre-fill form (GET) – fills the form for review -->
            <form method="GET" action="/settings/agent-config" class="flex items-center gap-2">
                <select name="source_id"
                        class="border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                               bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                               focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <?php foreach ($otherAgents as $oa): ?>
                        <option value="<?= (int) $oa['id'] ?>"
                            <?= ((int) $oa['id'] === $sourceId) ? ' selected' : '' ?>>
                            <?= htmlspecialchars((string) $oa['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"
                        class="px-4 py-2 rounded-lg text-sm font-semibold transition cm-btn-secondary">
                    <?= htmlspecialchars($t('agent_settings_prefill_btn'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>

            <!-- Direct copy form (POST) – copies immediately without review -->
            <form method="POST" action="/settings/agent-config/copy"
                  onsubmit="return confirm(<?= htmlspecialchars(json_encode($t('agent_settings_copy_confirm')), ENT_QUOTES, 'UTF-8') ?>)">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <select name="source_agent_id"
                        class="border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                               bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                               focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <?php foreach ($otherAgents as $oa): ?>
                        <option value="<?= (int) $oa['id'] ?>">
                            <?= htmlspecialchars((string) $oa['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"
                        class="ml-2 px-4 py-2 rounded-lg text-sm font-semibold transition"
                        style="background:rgba(59,130,246,.1);color:var(--cm-primary);border:1px solid rgba(59,130,246,.2)">
                    <?= htmlspecialchars($t('agent_settings_copy_btn'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>

        </div>
    </section>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Settings form
         ═══════════════════════════════════════════════════════════════════════ -->
    <form method="POST" action="/settings/agent-config" class="space-y-6">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

        <!-- ── General / Notifications ──────────────────────────────────────── -->
        <section class="cm-card rounded-xl p-6 space-y-4">
            <h2 class="text-lg font-semibold" style="color:var(--cm-text)">
                <?= htmlspecialchars($t('settings_notifications_title'), ENT_QUOTES, 'UTF-8') ?>
            </h2>

            <div>
                <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                    <?= htmlspecialchars($t('settings_web_url'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input type="url" name="notifications_web_url"
                       value="<?= htmlspecialchars((string) ($notif['web_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="https://cronmanager.example.com"
                       class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                              bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                              focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </section>

        <!-- ── E-Mail / SMTP ─────────────────────────────────────────────────── -->
        <section class="cm-card rounded-xl p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold" style="color:var(--cm-text)">
                    <?= htmlspecialchars($t('settings_mail_title'), ENT_QUOTES, 'UTF-8') ?>
                </h2>
                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--cm-text-muted)">
                    <input type="checkbox" name="mail_enabled" value="1"
                           <?= $checked((bool) ($mail['enabled'] ?? false)) ?>
                           class="rounded accent-blue-600">
                    <?= htmlspecialchars($t('settings_mail_enabled'), ENT_QUOTES, 'UTF-8') ?>
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_mail_host'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text" name="mail_host"
                           value="<?= htmlspecialchars((string) ($mail['host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="smtp.example.com"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_mail_port'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="number" name="mail_port" min="1" max="65535"
                           value="<?= (int) ($mail['port'] ?? 587) ?>"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_mail_encryption'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <select name="mail_encryption"
                            class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                   bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                   focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <?php $enc = strtolower((string) ($mail['encryption'] ?? 'tls')); ?>
                        <option value="tls"  <?= $selEnc('tls',  $enc) ?>>STARTTLS (587)</option>
                        <option value="ssl"  <?= $selEnc('ssl',  $enc) ?>>SSL/TLS (465)</option>
                        <option value="none" <?= $selEnc('none', $enc) ?>>Keine</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_mail_username'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text" name="mail_username"
                           value="<?= htmlspecialchars((string) ($mail['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_mail_password'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <div class="relative">
                        <input type="password" name="mail_password" id="mail_password"
                               value="<?= htmlspecialchars((string) ($mail['password'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="new-password"
                               class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 pr-10 text-sm
                                      bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                      focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <button type="button" onclick="cmTogglePw('mail_password')"
                                class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_mail_from'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="email" name="mail_from"
                           value="<?= htmlspecialchars((string) ($mail['from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="cronmanager@example.com"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_mail_from_name'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text" name="mail_from_name"
                           value="<?= htmlspecialchars((string) ($mail['from_name'] ?? 'Cronmanager'), ENT_QUOTES, 'UTF-8') ?>"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_mail_to'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="email" name="mail_to"
                           value="<?= htmlspecialchars((string) ($mail['to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="admin@example.com"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

            </div>
        </section>

        <!-- ── Telegram ──────────────────────────────────────────────────────── -->
        <section class="cm-card rounded-xl p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold" style="color:var(--cm-text)">
                    <?= htmlspecialchars($t('settings_telegram_title'), ENT_QUOTES, 'UTF-8') ?>
                </h2>
                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--cm-text-muted)">
                    <input type="checkbox" name="telegram_enabled" value="1"
                           <?= $checked((bool) ($tg['enabled'] ?? false)) ?>
                           class="rounded accent-blue-600">
                    <?= htmlspecialchars($t('settings_telegram_enabled'), ENT_QUOTES, 'UTF-8') ?>
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_telegram_bot_token'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <div class="relative">
                        <input type="password" name="telegram_bot_token" id="telegram_bot_token"
                               value="<?= htmlspecialchars((string) ($tg['bot_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="new-password"
                               class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 pr-10 text-sm
                                      bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                      focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <button type="button" onclick="cmTogglePw('telegram_bot_token')"
                                class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_telegram_chat_id'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text" name="telegram_chat_id"
                           value="<?= htmlspecialchars((string) ($tg['chat_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="-100123456789"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_telegram_timeout'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="number" name="telegram_timeout" min="1" max="60"
                           value="<?= (int) ($tg['timeout'] ?? 10) ?>"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

            </div>
        </section>

        <!-- ── InfluxDB ───────────────────────────────────────────────────────── -->
        <section class="cm-card rounded-xl p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold" style="color:var(--cm-text)">
                    <?= htmlspecialchars($t('settings_influxdb_title'), ENT_QUOTES, 'UTF-8') ?>
                </h2>
                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--cm-text-muted)">
                    <input type="checkbox" name="influxdb_enabled" value="1"
                           <?= $checked((bool) ($influx['enabled'] ?? false)) ?>
                           class="rounded accent-blue-600">
                    <?= htmlspecialchars($t('settings_influxdb_enabled'), ENT_QUOTES, 'UTF-8') ?>
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_influxdb_url'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="url" name="influxdb_url"
                           value="<?= htmlspecialchars((string) ($influx['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="http://influxdb:8086"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_influxdb_token'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <div class="relative">
                        <input type="password" name="influxdb_token" id="influxdb_token"
                               value="<?= htmlspecialchars((string) ($influx['token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="new-password"
                               class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 pr-10 text-sm
                                      bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                      focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <button type="button" onclick="cmTogglePw('influxdb_token')"
                                class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_influxdb_org'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text" name="influxdb_org"
                           value="<?= htmlspecialchars((string) ($influx['org'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_influxdb_bucket'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text" name="influxdb_bucket"
                           value="<?= htmlspecialchars((string) ($influx['bucket'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--cm-text-muted)">
                        <?= htmlspecialchars($t('settings_influxdb_timeout'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="number" name="influxdb_timeout" min="1" max="60"
                           value="<?= (int) ($influx['timeout'] ?? 10) ?>"
                           class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

            </div>
        </section>

        <!-- ── Submit ─────────────────────────────────────────────────────────── -->
        <div class="flex justify-end gap-3">
            <a href="/settings"
               class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-300 dark:border-gray-600
                      text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                <?= htmlspecialchars($t('cancel'), ENT_QUOTES, 'UTF-8') ?>
            </a>
            <button type="submit"
                    class="px-5 py-2 rounded-lg text-sm font-semibold bg-blue-600 hover:bg-blue-700 text-white transition shadow-sm">
                <?= htmlspecialchars($t('agent_settings_save_btn'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>

    </form><!-- /settings form -->

</div>

<script>
function cmTogglePw(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.type = el.type === 'password' ? 'text' : 'password';
}
</script>
