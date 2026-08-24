# Troubleshooting

---

## "Agent unavailable" error in the web UI

**Host-agent mode:**
1. Check the agent is running:
   ```bash
   sudo systemctl status cronmanager-agent
   curl -k https://127.0.0.1:8865/health
   ```
2. Verify `agent.url` in the web config points to `https://host.docker.internal:8865`
   and `ssl_verify` is `false`
3. Verify the HMAC secret matches in both config files
4. Inspect agent logs:
   ```bash
   sudo journalctl -u cronmanager-agent -n 100
   # or
   tail -f /opt/cronmanager/agent/log/cronmanager-agent.log
   ```

**Docker mode:**
1. Check the agent container is running and healthy:
   ```bash
   docker ps | grep cronmanager-agent
   docker exec cronmanager-agent curl -sk https://localhost:8865/health
   ```
2. Verify `agent.url` points to `https://cronmanager-agent:8865` and `ssl_verify` is `false`
3. Verify the HMAC secret matches in both config files
4. Inspect agent container logs:
   ```bash
   docker logs cronmanager-agent
   ```

---

## Jobs are not executing

**Host-agent mode:**
1. Verify the wrapper script is executable:
   ```bash
   chmod +x /opt/cronmanager/agent/bin/cron-wrapper.sh
   ```
2. Check the crontab for the affected user:
   ```bash
   crontab -u <linux-user> -l
   ```
3. Check the system cron log:
   ```bash
   grep CRON /var/log/syslog | tail -50
   ```
4. Test the wrapper manually:
   ```bash
   /opt/cronmanager/agent/bin/cron-wrapper.sh <job-id> local
   ```

**Docker mode:**
1. Verify the container crontab has entries (use **Settings → Crontab Sync** if empty):
   ```bash
   docker exec cronmanager-agent crontab -l
   ```
2. Verify jobs have `linux_user = root` (required in docker mode)
3. Check the cron log inside the container:
   ```bash
   docker exec cronmanager-agent grep CRON /var/log/syslog 2>/dev/null | tail -50
   # or check the agent log for execution events:
   docker logs cronmanager-agent | tail -50
   ```
4. Test the wrapper manually inside the container:
   ```bash
   docker exec cronmanager-agent /opt/cronmanager/agent/bin/cron-wrapper.sh <job-id> local
   ```

> **Multi-agent:** verify that the **active agent** in the sidebar is set to the correct
> host before checking the job list or timeline.

---

## Remote SSH jobs are not executing

See [MULTI-HOST.md – Troubleshooting SSH](MULTI-HOST.md#troubleshooting-ssh).

---

## OIDC / SSO login fails

See [SSO.md – Troubleshooting SSO](SSO.md#troubleshooting-sso).

---

## Database connection fails

1. Check the MariaDB container health:
   ```bash
   docker inspect --format='{{.State.Health.Status}}' cronmanager-db
   ```
2. Test connectivity:
   ```bash
   docker exec cronmanager-db mariadb -u cronmanager -p<password> -e "SELECT 1"
   ```
3. Confirm passwords match across `db.credentials`, agent config, and web config

---

## 403 Forbidden for non-admin / non-operator users

Actions like creating, editing, or deleting jobs require the **operator** or **admin** role.
An existing admin must update the user's role at **Users → Edit user**.

---

## Execution limit checker produces no output / auto-kill never fires

The limit checker (`check-limits.php`) runs every minute via a system cron entry. If it
never produces any log output the script may be crashing silently.

**Diagnose:**
```bash
# Host-agent mode: run manually and look for PHP errors
sudo php /opt/cronmanager/agent/bin/check-limits.php

# Docker mode
docker exec cronmanager-agent php /opt/cronmanager/agent/bin/check-limits.php
```

**Verify the cron entry exists:**
```bash
# Host-agent mode
cat /etc/cron.d/cronmanager-limits

# Docker mode (written by the entrypoint)
docker exec cronmanager-agent cat /etc/cron.d/cronmanager-limits
```

Expected output:
```
* * * * * root /usr/bin/php /opt/cronmanager/agent/bin/check-limits.php >> /dev/null 2>&1
```

If missing, reinstall or run `simple_debian_setup.sh` again (idempotent).

**Verify the checker runs and logs:**
```bash
grep "check-limits" /opt/cronmanager/agent/log/cronmanager-agent.log | tail -20
# Docker:
docker exec cronmanager-agent grep "check-limits" \
    /opt/cronmanager/agent/log/cronmanager-agent.log | tail -20
```

---

## Auto-kill fires the notification but the job keeps running

This means the notification was sent (exit code `-3`) but the `kill` call failed.

**1. The job process is not a process-group leader**

`kill -TERM -$PID` sends SIGTERM to the entire process group — only works when the job
was launched with `setsid`. Check:

```bash
grep -n "setsid" /opt/cronmanager/agent/bin/cron-wrapper.sh
```

If `setsid` does not appear, redeploy the agent.

**2. Remote SSH auto-kill: process group not created on the remote host**

```bash
grep -A3 "REMOTE_PID_FILE" /opt/cronmanager/agent/bin/cron-wrapper.sh | grep setsid
```

Redeploy if `setsid` is absent.

**3. PID file not written / not found**

```bash
grep "auto-kill\|pid_file\|PID" /opt/cronmanager/agent/log/cronmanager-agent.log | tail -30
```

---

## Job shows exit code 0 (or 143) after being auto-killed

The wrapper script's `wait` call returns after SIGTERM (exit 143) and calls
`POST /execution/finish`. If the execution row was already closed by the auto-killer
with exit code `-2`, the finish endpoint ignores the second update
(`AND finished_at IS NULL` guard). Seeing `0` or `143` means the agent code predates
this fix.

Redeploy `agent/src/Endpoints/ExecutionFinishEndpoint.php` and restart the agent.

---

## Jobs stuck in "running" state

Executions stay open when the wrapper script is interrupted before calling
`POST /execution/finish` (e.g. agent restart, container recreation).

**Clean up via the UI:**
1. Go to **Settings → Stuck Executions**
2. Adjust the lookback threshold if needed
3. Use **Mark Finished** (exit code `-1`) or **Delete** per row, or bulk-select all

**Clean up via SQL (emergency):**
```sql
UPDATE execution_log
   SET finished_at = NOW(),
       exit_code   = -1,
       output      = CONCAT(COALESCE(output, ''), '\n[Marked finished manually]')
 WHERE finished_at IS NULL
   AND started_at < DATE_SUB(NOW(), INTERVAL 2 HOUR);
```

---

## Email alerts are not being sent

See [ALERTS.md – Troubleshooting Alerts](ALERTS.md#troubleshooting-alerts).

---

## Singleton mode does not prevent duplicate runs

1. **Verify the `singleton` column exists:**
   ```sql
   DESCRIBE cronjobs;
   -- Should show a 'singleton' column
   ```
   If missing, apply migration `005_singleton.sql`:
   ```bash
   docker exec -i cronmanager-db mariadb -u cronmanager -p<password> cronmanager \
       < /opt/cronmanager/agent/sql/migrations/005_singleton.sql
   ```

2. **Verify the flag was saved** — re-open the job edit form and confirm the
   Singleton checkbox is ticked.

3. **Check the agent log** for `409 Conflict` responses (singleton guard working):
   ```bash
   grep "singleton\|409\|already running" /opt/cronmanager/agent/log/cronmanager-agent.log | tail -20
   ```

---

## Viewing live agent activity

```bash
# Host-agent mode
tail -f /opt/cronmanager/agent/log/cronmanager-agent.log

# Docker mode
docker exec cronmanager-agent tail -f /opt/cronmanager/agent/log/cronmanager-agent.log
# or via docker logs:
docker logs -f cronmanager-agent
```

To temporarily increase verbosity (requires restart):
```json
{ "logging": { "level": "debug" } }
```
```bash
sudo systemctl restart cronmanager-agent   # host-agent
docker restart cronmanager-agent           # docker mode
```

---

## Checking what the execution-limit checker last did

```bash
grep "check-limits" /opt/cronmanager/agent/log/cronmanager-agent.log | tail -50
# Docker:
docker exec cronmanager-agent grep "check-limits" \
    /opt/cronmanager/agent/log/cronmanager-agent.log | tail -50
```

Key log lines:

| Message | Meaning |
|---|---|
| `check-limits: starting execution-limit check` | Checker ran successfully |
| `check-limits: no executions exceeding their limit` | No jobs over limit at that minute |
| `check-limits: found executions exceeding limit` | At least one job exceeded its limit |
| `check-limits: auto-killed execution` | Kill succeeded |
| `check-limits: auto-kill did not succeed` | Kill attempt failed — see `error` field |
| `check-limits: limit-exceeded notification dispatched` | Alert email/Telegram queued |
