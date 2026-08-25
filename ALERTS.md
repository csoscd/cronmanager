# Failure Alerts & Notifications

Cronmanager dispatches notifications for three categories of events:

| Event | Trigger |
|---|---|
| **Failure** | A job exits with a non-zero status code |
| **Limit exceeded** | A job is still running past its `execution_limit_seconds` (alert and/or auto-kill) |
| **Recovery** | A job succeeds again after a failure streak that met the alert threshold |
| **Silence** | A job has not run at all within its expected schedule interval (opt-in per job) |

Both **Email** and **Telegram** channels can be enabled independently and fire in parallel.

---

## Configuration via the Web UI (Recommended)

The recommended way to configure notifications is the **Agent Settings** page at
**Settings → Agent Settings** (`/settings/agent-config`). It provides a form for
all four sections (General, Email, Telegram, InfluxDB) and writes the values directly
to the agent's database — settings persist across container restarts without changing
environment variables.

The page also supports **copying settings from one agent to another**, which is useful
when running multiple agents that share the same SMTP or Telegram configuration.

> **Encryption at rest:** if you set `AGENT_SETTINGS_KEY` on the agent container,
> passwords and tokens are encrypted with AES-256-CBC before being stored.
> Use at least 32 random characters (`openssl rand -hex 32`).
> Removing the key after setting it makes stored credentials unreadable until re-saved.

Alternatively, settings can be supplied through environment variables in Docker Compose.
When both exist, the **database value takes precedence**.

---

## Email Alerts

### Enable

1. Set `MAIL_ENABLED=true` and the other `MAIL_*` variables in `.env`, **or**
   configure them via **Settings → Agent Settings** in the web UI
2. When using environment variables: restart the agent (`docker restart cronmanager-agent`)
3. Per job: check **"Notify on failure"** when creating or editing the job

Alerts are dispatched asynchronously after the job completes — SMTP runs in a background
process so a slow or unreachable server cannot block the agent.

### Environment variables (agent container)

| Variable | Default | Description |
|---|---|---|
| `MAIL_ENABLED` | `false` | Enable email alerts |
| `MAIL_HOST` | `smtp.example.com` | SMTP server hostname |
| `MAIL_PORT` | `587` | SMTP port |
| `MAIL_USERNAME` | _(empty)_ | SMTP username |
| `MAIL_PASSWORD` | _(empty)_ | SMTP password |
| `MAIL_FROM` | `alerts@example.com` | Sender address |
| `MAIL_FROM_NAME` | `Cronmanager` | Sender display name |
| `MAIL_TO` | `admin@example.com` | Alert recipient address |
| `MAIL_ENCRYPTION` | `tls` | `tls` (STARTTLS, port 587) or `ssl` (SMTPS, port 465) |

### Encryption settings

| Port | Protocol | `MAIL_ENCRYPTION` |
|---|---|---|
| 587 | STARTTLS | `tls` |
| 465 | SMTPS / implicit TLS | `ssl` |

Mixing port and encryption type causes the connection to hang until the SMTP timeout.

---

## Telegram Alerts

### Prerequisites

1. Create a bot via [@BotFather](https://t.me/BotFather) — it gives you a **Bot API token**
2. Start a conversation with your bot (or add it to a group/channel) and retrieve the **chat ID**:
   ```
   https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates
   ```
   Send any message to the bot first, then call the URL — look for `"chat":{"id":...}`.

### Enable

1. Set `TELEGRAM_ENABLED=true`, `TELEGRAM_BOT_TOKEN`, and `TELEGRAM_CHAT_ID` in `.env`, **or**
   configure them via **Settings → Agent Settings** in the web UI
2. Restart the agent when using environment variables
3. Per job: check **"Notify on failure"** — the same flag controls both email and Telegram

### Environment variables (agent container)

| Variable | Default | Description |
|---|---|---|
| `TELEGRAM_ENABLED` | `false` | Enable Telegram alerts |
| `TELEGRAM_BOT_TOKEN` | _(empty)_ | Bot API token from @BotFather |
| `TELEGRAM_CHAT_ID` | _(empty)_ | Target chat, channel, or group ID |
| `TELEGRAM_TIMEOUT` | `15` | HTTP request timeout in seconds |

### Docker Compose example (`.env`)

```dotenv
TELEGRAM_ENABLED=true
TELEGRAM_BOT_TOKEN=123456789:AABBccDDeeFFggHHiiJJkkLLmmNNoo...
TELEGRAM_CHAT_ID=-1001234567890
```

Messages are sent in HTML parse mode and include job ID, description, user, schedule,
exit code, start/notification time, and captured output (truncated to 2 000 characters).
Jobs still running at notification time show "N/A – job still running" as exit code.

---

## Recovery Notifications

When a job succeeds again after a failure streak that triggered alerts, Cronmanager can
send a recovery notification (opt-in per job, disabled by default).

**To enable:** check **"Notify on recovery"** on the job edit form.

**Behaviour:**
- Only fires when the job recovers after ≥ N consecutive failures (same threshold as failure alerts)
- If the job recovers before reaching the alert threshold, no recovery notification is sent
- Both email and Telegram channels are used if configured

---

## Silence Detection

Silence detection alerts when a job has **stopped running entirely** — no executions within
its expected schedule interval plus a configurable grace period.

**To enable per job:** check **"Notify on silence"** on the job edit form.

**How it works:**
1. `check-limits.php` runs every minute and calculates when the job last should have run
   using `CronExpression::getPreviousRunDate()`
2. It compares that with the most recent real execution (maintenance sentinel runs are excluded)
3. If the job is overdue beyond the grace period, an alert is sent (max once per hour)
4. Three maintenance-window guards prevent false positives:
   - Agent-wide maintenance active → skip the entire silence check
   - All job targets in maintenance → skip this job
   - Most recent event was a maintenance sentinel → job was just skipped, not silent

**Grace period** (per job, falls back to global `silence.grace_minutes`, default 10 min):
```json
{
    "silence": {
        "grace_minutes": 10
    }
}
```

The `GET /health` endpoint exposes a `silent_jobs` counter for external monitors
(Uptime Kuma, Grafana, etc.) without per-job configuration.

---

## Troubleshooting Alerts

### Email alerts are not being sent

1. **Verify mail is enabled:**
   ```bash
   grep -A10 '"mail"' /opt/cronmanager/agent/config/config.json
   # mail.enabled must be true
   ```

2. **Per-job:** confirm "Notify on failure / limit exceeded" is checked on the job edit page.

3. **Test SMTP connectivity:**
   ```bash
   nc -zv smtp.example.com 587
   ```

4. **Check the agent log for SMTP errors:**
   ```bash
   grep -i "mail\|smtp\|notification" /opt/cronmanager/agent/log/cronmanager-agent.log | tail -30
   # Docker mode:
   docker exec cronmanager-agent grep -i "mail\|smtp\|notification" \
       /opt/cronmanager/agent/log/cronmanager-agent.log | tail -30
   ```

5. **Test the notification script directly** (it runs as a background process):
   ```bash
   echo '{"job_id":1,"description":"Test","linux_user":"root","schedule":"* * * * *","exit_code":1,"output":"test","started_at":"2026-01-01 00:00:00","finished_at":"2026-01-01 00:01:00"}' \
       > /tmp/test_notify.json
   php /opt/cronmanager/agent/bin/send-notification.php /tmp/test_notify.json
   ```

### Silence alerts fire too early / too often

- Increase the grace period: set `silence_grace_minutes` on the job, or raise the global
  `silence.grace_minutes` in the agent config
- Check for maintenance windows that might be causing executions to be skipped
  (silence detection ignores maintenance sentinels, so this should not cause false positives)
- Check `last_silence_alert_at` in the `cronjobs` table — it deduplicates alerts to once per hour
