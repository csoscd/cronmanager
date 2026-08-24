# InfluxDB Metrics

Cronmanager can write a data point to **InfluxDB 2.x** after every completed execution.
This lets you build dashboards in Grafana (or any Flux-capable tool) showing execution
history, success rates, durations, and failure trends across all your jobs.

Writes are dispatched in a **background process** (`send-influx.php`) so a slow or
unreachable InfluxDB instance never blocks the agent's HTTP response.

---

## Measurement Schema

Every completed execution produces one `cron_execution` data point:

| Kind | Name | Type | Description |
|---|---|---|---|
| **Tag** | `job_id` | string | Numeric job ID |
| **Tag** | `description` | string | Job description |
| **Tag** | `linux_user` | string | Linux user that ran the job |
| **Tag** | `target` | string | Execution target (`local` or SSH alias) |
| **Tag** | `status` | string | `success`, `failed`, `killed`, `limit_exceeded`, `maintenance`, `interrupted` |
| **Tag** | `job_tags` | string | Comma-separated job tags (omitted when empty) |
| **Field** | `duration_seconds` | float | Elapsed wall-clock time in seconds |
| **Field** | `exit_code` | int | Raw process exit code |
| **Field** | `output_length` | int | Bytes of captured output |
| **Field** | `during_maintenance` | int | `1` if a maintenance window was active, else `0` |

---

## Enable

### Via environment variables (recommended for Docker)

Add to `.env` or `docker-compose-full.yml`:

```dotenv
INFLUXDB_ENABLED=true
INFLUXDB_URL=http://influxdb:8086
INFLUXDB_TOKEN=your-api-token
INFLUXDB_ORG=your-org
INFLUXDB_BUCKET=cronmanager
```

After adding these variables, restart the agent container so the entrypoint
regenerates `config.json`:

```bash
docker restart cronmanager-agent
```

### Via the web UI

Go to **Settings → Agent Settings** → **InfluxDB** section.
Settings saved here are stored in the agent's database and survive container recreations
without environment variables.

### All environment variables

| Variable | Default | Description |
|---|---|---|
| `INFLUXDB_ENABLED` | `false` | Enable InfluxDB metrics export |
| `INFLUXDB_URL` | `http://influxdb:8086` | InfluxDB base URL |
| `INFLUXDB_TOKEN` | _(empty)_ | InfluxDB API token |
| `INFLUXDB_ORG` | _(empty)_ | InfluxDB organisation name |
| `INFLUXDB_BUCKET` | `cronmanager` | InfluxDB bucket name |
| `INFLUXDB_TIMEOUT` | `10` | HTTP write timeout in seconds |

---

## Grafana Dashboard

An importable Grafana dashboard is included at `grafana/cronmanager-overview.json`.

### Import steps

1. Grafana → **Dashboards → Import**
2. Upload `grafana/cronmanager-overview.json` (or paste its JSON)
3. Select your InfluxDB datasource when prompted for `DS_INFLUXDB`
4. Set the `bucket` variable to your bucket name (default: `cronmanager`)

### Panels included

| Panel | Type |
|---|---|
| Total Executions | Stat |
| Success Rate | Gauge |
| Failed | Stat |
| Avg Duration | Stat |
| Maintenance Skipped | Stat |
| Executions over Time by Status | Stacked time series |
| Duration over Time by Job | Time series |
| Executions by Job | Horizontal bar chart |
| Avg Duration by Job | Horizontal bar chart |
| Recent Failures (last 50) | Table |

---

## Troubleshooting

### No data appears in Grafana

1. **Verify InfluxDB is enabled** in the agent config or web UI settings.

2. **Check the agent log** for write errors:
   ```bash
   grep -i "influx" /opt/cronmanager/agent/log/cronmanager-agent.log | tail -20
   # Docker mode:
   docker exec cronmanager-agent grep -i "influx" \
       /opt/cronmanager/agent/log/cronmanager-agent.log | tail -20
   ```

3. **Test connectivity from the agent container:**
   ```bash
   docker exec cronmanager-agent curl -s \
       -H "Authorization: Token <your-token>" \
       "http://influxdb:8086/api/v2/buckets?org=<your-org>"
   ```

4. **Verify the bucket and org names** match exactly (case-sensitive) between
   Cronmanager and InfluxDB.

5. **Container start order:** if the agent starts before InfluxDB is healthy,
   the first few write attempts may fail. Check whether errors resolve themselves
   after InfluxDB is fully up.
