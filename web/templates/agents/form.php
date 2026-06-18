<?php

declare(strict_types=1);

/**
 * Cronmanager – Agent create/edit form template
 *
 * Variables injected by AgentController::create() / edit():
 *   array|null  $agent    Existing agent row (null when creating)
 *   string|null $error    Translation key for a validation error (or null)
 *   bool        $isEdit   True when editing an existing agent
 *   string      $csrf_token CSRF token
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

$agentId  = $isEdit ? (int) ($agent['id'] ?? 0) : 0;
$action   = $isEdit ? "/settings/agents/{$agentId}" : '/settings/agents';

$val = fn(string $key, mixed $default = ''): string =>
    htmlspecialchars((string) ($agent[$key] ?? $default), ENT_QUOTES, 'UTF-8');
?>

<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <!-- Page title -->
    <div class="flex items-center gap-3">
        <a href="/settings"
           class="inline-flex items-center gap-1 text-sm font-medium hover:underline"
           style="color:var(--cm-primary)">
            ← <?= htmlspecialchars($t('back'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <h1 class="text-2xl font-bold" style="color:var(--cm-text)">
            <?= htmlspecialchars($t($isEdit ? 'agent_edit_title' : 'agent_create_title'), ENT_QUOTES, 'UTF-8') ?>
        </h1>
    </div>

    <!-- Error banner -->
    <?php if ($error !== null): ?>
        <div class="rounded-lg px-4 py-3 text-sm font-medium"
             style="background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.2)">
            <?= htmlspecialchars($t($error), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>"
          class="cm-card rounded-xl p-6 space-y-5">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

        <!-- Name -->
        <div class="space-y-1">
            <label for="agent-name" class="block text-sm font-medium" style="color:var(--cm-text)">
                <?= htmlspecialchars($t('agent_name'), ENT_QUOTES, 'UTF-8') ?>
                <span style="color:#dc2626">*</span>
            </label>
            <input id="agent-name" type="text" name="name" required maxlength="100"
                   value="<?= $val('name') ?>"
                   class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                   style="background:var(--cm-input-bg);border-color:var(--cm-border);color:var(--cm-text)">
        </div>

        <!-- Description -->
        <div class="space-y-1">
            <label for="agent-desc" class="block text-sm font-medium" style="color:var(--cm-text)">
                <?= htmlspecialchars($t('agent_description'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input id="agent-desc" type="text" name="description" maxlength="255"
                   value="<?= $val('description') ?>"
                   class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                   style="background:var(--cm-input-bg);border-color:var(--cm-border);color:var(--cm-text)">
        </div>

        <!-- URL -->
        <div class="space-y-1">
            <label for="agent-url" class="block text-sm font-medium" style="color:var(--cm-text)">
                <?= htmlspecialchars($t('agent_url'), ENT_QUOTES, 'UTF-8') ?>
                <span style="color:#dc2626">*</span>
            </label>
            <input id="agent-url" type="url" name="url" required maxlength="500"
                   placeholder="https://host.example.com:8865"
                   value="<?= $val('url') ?>"
                   class="w-full rounded-lg border px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
                   style="background:var(--cm-input-bg);border-color:var(--cm-border);color:var(--cm-text)">
        </div>

        <!-- HMAC Secret -->
        <div class="space-y-1">
            <label for="agent-secret" class="block text-sm font-medium" style="color:var(--cm-text)">
                <?= htmlspecialchars($t('agent_hmac_secret'), ENT_QUOTES, 'UTF-8') ?>
                <?php if (!$isEdit): ?>
                    <span style="color:#dc2626">*</span>
                <?php else: ?>
                    <span class="ml-1 text-xs font-normal" style="color:var(--cm-text-muted)">
                        (<?= htmlspecialchars($t('agent_secret_leave_blank'), ENT_QUOTES, 'UTF-8') ?>)
                    </span>
                <?php endif; ?>
            </label>
            <div class="relative">
                <input id="agent-secret" type="password" name="hmac_secret"
                       <?= !$isEdit ? 'required' : '' ?>
                       autocomplete="new-password"
                       class="w-full rounded-lg border px-3 py-2 pr-10 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
                       style="background:var(--cm-input-bg);border-color:var(--cm-border);color:var(--cm-text)">
                <button type="button" id="toggle-secret"
                        class="absolute right-2 top-1/2 -translate-y-1/2 text-xs px-1 py-0.5 rounded"
                        style="color:var(--cm-text-muted)"
                        title="<?= htmlspecialchars($t('agent_secret_toggle'), ENT_QUOTES, 'UTF-8') ?>">
                    👁
                </button>
            </div>
        </div>

        <!-- Timeout + SSL row -->
        <div class="grid grid-cols-2 gap-4">
            <!-- Timeout -->
            <div class="space-y-1">
                <label for="agent-timeout" class="block text-sm font-medium" style="color:var(--cm-text)">
                    <?= htmlspecialchars($t('agent_timeout'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <div class="flex items-center gap-2">
                    <input id="agent-timeout" type="number" name="timeout" min="1" max="120"
                           value="<?= $val('timeout', '10') ?>"
                           class="w-24 rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           style="background:var(--cm-input-bg);border-color:var(--cm-border);color:var(--cm-text)">
                    <span class="text-sm" style="color:var(--cm-text-muted)">s</span>
                </div>
            </div>

            <!-- Sort order -->
            <div class="space-y-1">
                <label for="agent-sort" class="block text-sm font-medium" style="color:var(--cm-text)">
                    <?= htmlspecialchars($t('agent_sort_order'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input id="agent-sort" type="number" name="sort_order" min="0"
                       value="<?= $val('sort_order', '0') ?>"
                       class="w-24 rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                       style="background:var(--cm-input-bg);border-color:var(--cm-border);color:var(--cm-text)">
            </div>
        </div>

        <!-- SSL Verify checkbox -->
        <div class="flex items-center gap-2">
            <input id="agent-ssl-verify" type="checkbox" name="ssl_verify" value="1"
                   <?= ($agent['ssl_verify'] ?? 1) ? 'checked' : '' ?>
                   class="rounded cursor-pointer">
            <label for="agent-ssl-verify" class="text-sm" style="color:var(--cm-text)">
                <?= htmlspecialchars($t('agent_ssl_verify'), ENT_QUOTES, 'UTF-8') ?>
            </label>
        </div>

        <!-- CA Bundle -->
        <div class="space-y-1">
            <label for="agent-ca" class="block text-sm font-medium" style="color:var(--cm-text)">
                <?= htmlspecialchars($t('agent_ssl_ca_bundle'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input id="agent-ca" type="text" name="ssl_ca_bundle" maxlength="500"
                   placeholder="/path/to/ca-bundle.pem"
                   value="<?= $val('ssl_ca_bundle') ?>"
                   class="w-full rounded-lg border px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
                   style="background:var(--cm-input-bg);border-color:var(--cm-border);color:var(--cm-text)">
        </div>

        <!-- Enabled checkbox -->
        <div class="flex items-center gap-2">
            <input id="agent-enabled" type="checkbox" name="enabled" value="1"
                   <?= ($agent['enabled'] ?? 1) ? 'checked' : '' ?>
                   class="rounded cursor-pointer">
            <label for="agent-enabled" class="text-sm" style="color:var(--cm-text)">
                <?= htmlspecialchars($t('agent_enabled'), ENT_QUOTES, 'UTF-8') ?>
            </label>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-3 pt-2">
            <button type="submit"
                    class="px-5 py-2 rounded-lg text-sm font-semibold transition"
                    style="background:var(--cm-primary);color:#fff">
                <?= htmlspecialchars($t('save'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <a href="/settings"
               class="px-4 py-2 rounded-lg text-sm font-medium transition cm-btn-secondary">
                <?= htmlspecialchars($t('cancel'), ENT_QUOTES, 'UTF-8') ?>
            </a>

            <?php if ($isEdit): ?>
                <!-- Connectivity test button (only on edit form – agent ID is known) -->
                <button type="button" id="agent-test-btn"
                        data-agent-id="<?= $agentId ?>"
                        data-csrf="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>"
                        class="ml-auto px-4 py-2 rounded-lg text-sm font-semibold transition"
                        style="background:rgba(59,130,246,.1);color:var(--cm-primary);border:1px solid rgba(59,130,246,.2)">
                    <?= htmlspecialchars($t('agent_test_connection'), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <span id="agent-test-result" class="hidden text-sm px-2 py-1 rounded"></span>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($isEdit): ?>
<script>
(function () {
    const btn    = document.getElementById('agent-test-btn');
    const result = document.getElementById('agent-test-result');
    if (!btn) return;

    const i18n = {
        ok:      <?= json_encode($t('agent_test_ok')) ?>,
        failed:  <?= json_encode($t('agent_test_failed')) ?>,
        testing: '…',
    };

    btn.addEventListener('click', function () {
        const agentId = btn.dataset.agentId;
        const csrf    = btn.dataset.csrf;

        btn.disabled    = true;
        btn.textContent = i18n.testing;
        result.classList.add('hidden');

        fetch('/settings/agents/' + agentId + '/test', {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:        '_csrf=' + encodeURIComponent(csrf),
        })
        .then(r => r.json())
        .catch(() => null)
        .then(function (data) {
            btn.disabled    = false;
            btn.textContent = <?= json_encode($t('agent_test_connection')) ?>;

            if (!data) {
                result.textContent = i18n.failed;
                result.style.cssText = 'background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.2)';
            } else if (data.success) {
                result.textContent = i18n.ok + (data.version ? ' (v' + data.version + ')' : '');
                result.style.cssText = 'background:rgba(34,197,94,.12);color:#16a34a;border:1px solid rgba(34,197,94,.25)';
            } else {
                result.textContent = i18n.failed + (data.message ? ' — ' + data.message : '');
                result.style.cssText = 'background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.2)';
            }

            result.classList.remove('hidden');
        });
    });

    // Password field visibility toggle
    const secretInput = document.getElementById('agent-secret');
    const toggleBtn   = document.getElementById('toggle-secret');
    if (secretInput && toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            secretInput.type = secretInput.type === 'password' ? 'text' : 'password';
        });
    }
}());
</script>
<?php else: ?>
<script>
(function () {
    const secretInput = document.getElementById('agent-secret');
    const toggleBtn   = document.getElementById('toggle-secret');
    if (secretInput && toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            secretInput.type = secretInput.type === 'password' ? 'text' : 'password';
        });
    }
}());
</script>
<?php endif; ?>
