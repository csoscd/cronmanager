/**
 * Cronmanager Web UI – Shared AJAX Utilities
 *
 * Provides three globals used by all AJAX-enabled pages:
 *   cmFetch(url, options)   – fetch wrapper with credentials
 *   cmToast(message, type)  – lightweight toast notification
 *   cmPoll(fn, intervalMs)  – polling loop that pauses on hidden tab
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */
(function (window) {
    'use strict';

    // ── Fetch wrapper ─────────────────────────────────────────────────────────
    // Always sends credentials (session cookie). Rejects on non-2xx responses.
    window.cmFetch = function (url, options) {
        var opts = Object.assign({ credentials: 'same-origin' }, options || {});
        return fetch(url, opts).then(function (response) {
            if (!response.ok) {
                return Promise.reject(new Error('HTTP ' + response.status));
            }
            return response;
        });
    };

    // ── Toast notification ────────────────────────────────────────────────────
    // type: 'success' | 'error' | 'info'
    window.cmToast = function (message, type) {
        type = type || 'info';

        var container = document.getElementById('cm-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'cm-toast-container';
            container.style.cssText = [
                'position:fixed', 'bottom:20px', 'right:20px', 'z-index:9999',
                'display:flex', 'flex-direction:column', 'gap:8px', 'pointer-events:none',
            ].join(';');
            document.body.appendChild(container);
        }

        var palette = {
            success: { bg: 'rgba(52,211,153,.12)',  border: 'rgba(52,211,153,.3)',  text: '#34d399' },
            error:   { bg: 'rgba(248,113,113,.12)', border: 'rgba(248,113,113,.3)', text: '#f87171' },
            info:    { bg: 'rgba(129,140,248,.12)', border: 'rgba(129,140,248,.3)', text: '#818cf8' },
        };
        var c = palette[type] || palette.info;

        var toast = document.createElement('div');
        toast.style.cssText = [
            'background:' + c.bg,
            'border:1px solid ' + c.border,
            'color:' + c.text,
            'padding:10px 16px',
            'border-radius:8px',
            'font-size:.82rem',
            'font-weight:500',
            'font-family:Outfit,sans-serif',
            'max-width:320px',
            'opacity:0',
            'transition:opacity .2s ease',
            'pointer-events:auto',
        ].join(';');
        toast.textContent = message;
        container.appendChild(toast);

        // Fade in
        requestAnimationFrame(function () { toast.style.opacity = '1'; });

        // Fade out and remove after 3.5 s
        setTimeout(function () {
            toast.style.opacity = '0';
            setTimeout(function () {
                if (toast.parentNode) { toast.parentNode.removeChild(toast); }
            }, 250);
        }, 3500);
    };

    // ── Polling helper ────────────────────────────────────────────────────────
    // Pauses automatically when the browser tab becomes hidden (Page Visibility
    // API) and resumes — with an immediate call — when the tab regains focus.
    // Returns an object with a stop() method to cancel the loop.
    window.cmPoll = function (fn, intervalMs) {
        var timer  = null;
        var active = true;

        function schedule() {
            if (!active || document.visibilityState === 'hidden') { return; }
            timer = setTimeout(function () {
                fn();
                schedule();
            }, intervalMs);
        }

        function onVisibility() {
            if (document.visibilityState === 'hidden') {
                if (timer) { clearTimeout(timer); timer = null; }
            } else if (active) {
                fn();          // immediate call on tab focus
                schedule();
            }
        }

        document.addEventListener('visibilitychange', onVisibility);
        schedule();

        return {
            stop: function () {
                active = false;
                if (timer) { clearTimeout(timer); timer = null; }
                document.removeEventListener('visibilitychange', onVisibility);
            },
        };
    };

}(window));
