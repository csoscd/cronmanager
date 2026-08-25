# Multi-Agent Setup

Since v4.0.0, Cronmanager can manage cron jobs across **multiple agents** running on
different hosts — all from a single web UI. Each agent is an independent Cronmanager
agent container (or host-agent service) with its own MariaDB and crontab.

---

## Architecture

```
Browser
  │
  ▼
┌──────────────────────────┐
│  Web UI (single instance) │
│  agents table (registry) │
└───┬───────────┬───────────┘
    │ HMAC      │ HMAC
    ▼           ▼
┌───────────┐ ┌───────────┐
│  Agent A  │ │  Agent B  │
│  host-1   │ │  host-2   │
│  MariaDB  │ │  MariaDB  │
└───────────┘ └───────────┘
```

- The `agents` table lives in the **web UI's database** (same MariaDB as users and sessions).
- Each agent has its **own** MariaDB storing cronjobs, tags, and execution history.
- When you switch the active agent, the web UI sends all subsequent API calls to that
  agent's URL. The dashboard, job list, timeline, monitor, and export all reflect the
  selected agent's data.
- Each user's selection is stored in their PHP session — different users can view
  different agents simultaneously.

---

## Step 1 — Deploy a Second Agent

Set up a Cronmanager agent on the second host using the same procedure as the primary
agent (Docker Hub image or manual deployment). The second agent needs:

- Its own MariaDB container (or a separate database on a shared MariaDB instance)
- Its own HMAC secret (generate with `openssl rand -hex 32`)
- A port reachable from the web UI container (default: 8865 / HTTPS)

Make sure the web UI container can reach the second agent's URL. If the agent is on
another host in your network, verify firewall rules allow TCP 8865 from the web
container's host.

**Test connectivity from the web container:**
```bash
docker exec cronmanager-web curl -sk https://<second-host>:8865/health
# → {"status":"ok","timestamp":"...","version":"..."}
```

---

## Step 2 — Register the Agent in the Web UI

1. Log in as **admin**
2. Go to **Settings** (`/settings`)
3. In the **Agents** section click **+ Add agent**
4. Fill in the form:

   | Field | Example | Notes |
   |---|---|---|
   | **Name** | `host-2` | Display name in the sidebar switcher |
   | **Description** | `Production server 2` | Optional |
   | **URL** | `https://192.168.1.20:8865` | Must be reachable from the web container |
   | **HMAC secret** | _(generated)_ | Must match the second agent's `agent.hmac_secret` |
   | **Timeout** | `10` | HTTP timeout in seconds |
   | **Verify SSL certificate** | unchecked | Uncheck for self-signed certs (default) |
   | **Sort order** | `1` | Controls the dropdown order (0 = first) |
   | **Enabled** | checked | Uncheck to hide without deleting |

5. Click **Save** — the agent appears in the Agents table with a live status badge

> **Tip:** Use the **Test connection** button on the edit form to verify URL and
> HMAC secret before saving.

---

## Step 3 — Switch Between Agents

Once two or more agents are configured, a **dropdown selector** appears at the top of
the sidebar. Select the desired agent — the page reloads and all data reflects the
newly selected agent.

The selection is per-user and per-session. It does not affect other logged-in users.

---

## Per-User Agent Restrictions

Each user (and each API key) can be restricted to a specific subset of agents.
When a restriction is set, the sidebar switcher only shows the permitted agents
and API calls to other agents are rejected.

Configure per-user restrictions on the **Users → Edit user** form (admin only).

---

## Notes

- **The last agent cannot be deleted.** At least one agent must remain.
- **Disabling an agent** (`Enabled = unchecked`) removes it from the switcher but keeps
  its configuration. Re-enable at any time.
- **HMAC secrets are independent** per agent. Rotating a secret requires updating it in
  both the agent's config and the web UI's agent record.
- **Existing installs upgrade automatically.** On first start after upgrading to v4.0.0,
  the web UI creates the `agents` table and seeds a "Default" entry from the existing
  `agent.*` values in `config.json`. No manual migration step is required.
