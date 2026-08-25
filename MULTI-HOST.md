# Multi-Host Execution & SSH Setup

Cronmanager supports running cron jobs on **multiple targets** simultaneously — the local
host and any number of remote hosts reachable via SSH. This document covers SSH key
setup, reaching the Docker host from inside the agent container, and how multi-host
execution works.

---

## Table of Contents

1. [How Multi-Host Execution Works](#how-multi-host-execution-works)
2. [SSH Key Setup](#ssh-key-setup)
3. [Alternative: Agent-Specific SSH Directory](#alternative-agent-specific-ssh-directory)
4. [Reaching the Docker Host Itself](#reaching-the-docker-host-itself)
5. [SSH Connectivity Test](#ssh-connectivity-test)
6. [Troubleshooting SSH](#troubleshooting-ssh)

---

## How Multi-Host Execution Works

A single cron job can run on multiple targets simultaneously:

- **`local`** — runs inside the agent container (Docker mode) or on the host (host-agent mode)
- **SSH alias** — runs on a remote host via an alias defined in `~/.ssh/config`

When multiple targets are configured for a job:
- One independent crontab entry is created **per target**
- All entries fire at the same scheduled time
- Each target runs in parallel (SSH uses `BatchMode=yes`)
- Each target reports its execution result back to the agent independently

**Prerequisite:** key-based SSH access must be configured in `~/.ssh/config`
for the agent's user. Password prompts are not supported (`BatchMode=yes` suppresses them).

---

## SSH Key Setup

The `docker-compose-full.yml` mounts `/root/.ssh` from the Docker host into the agent
container by default:

```yaml
- /root/.ssh:/root/.ssh:ro
```

This gives the agent access to all SSH host aliases and key pairs configured for the
host's root user — the simplest setup for homelab environments.

> **Security note:** Mounting `/root/.ssh` exposes **every** SSH key and host alias
> configured for root. If you have SSH keys to systems unrelated to Cronmanager,
> consider the alternative below.

---

## Alternative: Agent-Specific SSH Directory

Create a dedicated directory with only the keys and hosts Cronmanager needs:

```bash
mkdir -p /opt/cronmanager/.ssh
chmod 700 /opt/cronmanager/.ssh

# Generate a dedicated key pair (no passphrase for unattended use)
ssh-keygen -t ed25519 -C "cronmanager-agent" -N "" \
    -f /opt/cronmanager/.ssh/id_ed25519

# Create a config file listing only the hosts Cronmanager manages
cat > /opt/cronmanager/.ssh/config <<'EOF'
Host myserver1
    HostName 192.168.1.10
    User root
    IdentityFile ~/.ssh/id_ed25519
    BatchMode yes
    ConnectTimeout 10

Host myserver2
    HostName 192.168.1.11
    User root
    IdentityFile ~/.ssh/id_ed25519
    BatchMode yes
    ConnectTimeout 10
EOF
chmod 600 /opt/cronmanager/.ssh/config
```

In `docker-compose-full.yml`, replace the default mount with:

```yaml
- /opt/cronmanager/.ssh:/root/.ssh:ro
```

**Authorize the key on each remote host:**

```bash
ssh-copy-id -i /opt/cronmanager/.ssh/id_ed25519.pub root@192.168.1.10
ssh-copy-id -i /opt/cronmanager/.ssh/id_ed25519.pub root@192.168.1.11
```

---

## Reaching the Docker Host Itself

If you want to import or run cron jobs on the **Docker host** (the machine running the
containers), the agent container must be able to SSH back to it.

### Step 1 – Add the host to the SSH config

```
Host dockerhost
    HostName host.docker.internal
    User root
    IdentityFile ~/.ssh/id_ed25519
    BatchMode yes
    ConnectTimeout 10
    StrictHostKeyChecking accept-new
```

> `host.docker.internal` resolves to the Docker gateway IP inside the container.
> Add it to the agent service in `docker-compose-full.yml` if not already present:
> ```yaml
> extra_hosts:
>   - "host.docker.internal:host-gateway"
> ```

### Step 2 – Authorize the agent's public key on the Docker host

```bash
# Append the agent's public key to root's authorized_keys on the Docker host
cat /opt/cronmanager/.ssh/id_ed25519.pub >> /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys

# Ensure the SSH daemon permits key-based root login
grep -i permitrootlogin /etc/ssh/sshd_config
# Should be: PermitRootLogin prohibit-password
```

### Step 3 – Add the host key to known_hosts

`host.docker.internal` only resolves **inside** Docker containers, not on the Docker
host itself. Run `ssh-keyscan` from **inside** the container:

```bash
docker exec cronmanager-agent ssh-keyscan -H host.docker.internal \
    >> /root/.ssh/known_hosts
```

> `ssh-keyscan` runs inside the container where `host.docker.internal` resolves correctly.
> The `>>` runs in the host shell and writes directly to the host's `known_hosts`.
> The container sees the update immediately via the read-only mount — no restart required.

### Step 4 – Verify

```bash
docker exec cronmanager-agent ssh dockerhost 'crontab -l -u root'
```

You should see the root crontab. If it works here, the Cronmanager import page
will show the Docker host as an available target.

---

## SSH Connectivity Test

The **Maintenance Windows** page has a **Test** button for each SSH-target maintenance
window. It runs:

```
ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new <host> echo ok
```

The result (Connected / Failed + output) is shown inline without a page reload.
The button only appears for SSH targets, not for `local` or `_agent_`.

You can also trigger the test via the REST API:

```bash
curl -s -X POST \
    -H "Authorization: Bearer cm_<token>" \
    -H "Content-Type: application/json" \
    -d '{"host": "myserver1"}' \
    https://cronmanager.example.com/api/v1/ssh/test
```

---

## Troubleshooting SSH

### Remote SSH jobs are not executing

1. **Test SSH connectivity from the agent:**
   ```bash
   # Host-agent mode
   ssh -o BatchMode=yes <host-alias> 'echo ok'

   # Docker mode
   docker exec cronmanager-agent ssh -o BatchMode=yes <host-alias> 'echo ok'
   ```

2. **Verify the SSH config is accessible inside the container:**
   ```bash
   docker exec cronmanager-agent cat /root/.ssh/config
   ```

3. **Check `known_hosts`** — SSH silently refuses unknown hosts when `BatchMode=yes`:
   ```bash
   docker exec cronmanager-agent ssh-keyscan -H <hostname> >> /root/.ssh/known_hosts
   ```
   Or add `StrictHostKeyChecking accept-new` to the SSH config block for that host.

4. **Verify the crontab entry exists on the remote host:**
   ```bash
   ssh <host-alias> 'crontab -l'
   ```

5. **Run the wrapper manually** to reproduce the execution path:
   ```bash
   # Host-agent mode
   /opt/cronmanager/agent/bin/cron-wrapper.sh <job-id> <ssh-host-alias>

   # Docker mode
   docker exec cronmanager-agent \
       /opt/cronmanager/agent/bin/cron-wrapper.sh <job-id> <ssh-host-alias>
   ```

### Auto-kill fires the notification but the job keeps running

`kill -TERM -$PID` sends SIGTERM to the entire process group. This only works when
the job was launched with `setsid`. Check:

```bash
grep -n "setsid" /opt/cronmanager/agent/bin/cron-wrapper.sh
```

If `setsid` does not appear, redeploy the agent to get the current wrapper.

For SSH targets, the remote command must also use `setsid`:

```bash
grep -A3 "REMOTE_PID_FILE" /opt/cronmanager/agent/bin/cron-wrapper.sh | grep setsid
```
