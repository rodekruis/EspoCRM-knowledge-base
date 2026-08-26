# Azure Monitor Logs

This EspoCRM extension sends log records to **Azure Application Insights** as `traces`, using the
Application Insights ingestion ("track") API.

- Registers a custom Monolog handler through `logger.handlerList`.
- Records are **buffered for the lifetime of a request / CLI run** and flushed as a
  single batched HTTPS POST on shutdown.
- Configuration is a **single connection string**. No custom table, no data collection
  rule, no role assignment.
- Failures are contained: a bad endpoint, quota exhaustion or a network outage degrades
  to `error_log()` messages and never affects the application.

The local file log (`data/logs/espo.log`) and the Admin > App Log keep working unchanged.

## Overview

```
Espo\Core\Utils\Log (Monolog)
 ├── RotatingFileHandlerLoader   → data/logs/espo.log   (re-declared, see note below)
 ├── AzureMonitorHandlerLoader   → BufferHandler → AzureMonitorHandler → <region>.in.applicationinsights.azure.com/v2.1/track
 └── DatabaseHandler                                    (added by Espo when logger.databaseHandler = true)
```

Each EspoCRM log record becomes one `MessageData` envelope in Azure App Insights. The same data has two different schemas
depending on where you query it: the Application Insights resource's **Logs** blade uses the
legacy camelCase names, the Log Analytics workspace uses the PascalCase ones.

| Espo | App Insights (`traces`) | Log Analytics (`AppTraces`) |
| --- | --- | --- |
| message | `message` | `Message` |
| timestamp | `timestamp` | `TimeGenerated` |
| level | `severityLevel` (0 Verbose … 4 Critical) | `SeverityLevel` |
| level name | `customDimensions.level` | `Properties.level` |
| channel | `customDimensions.channel` | `Properties.channel` |
| context keys | `customDimensions.*` (flattened, redacted) | `Properties.*` |
| `roleName` | `cloud_RoleName` | `AppRoleName` |
| `roleInstance` | `cloud_RoleInstance` | `AppRoleInstance` |
| FPM vs cron | `customDimensions.source` = `web` / `cli` | `Properties.source` |

`channel`, `level`, `source` and `processId` are reserved: a log context key with one of
those names is dropped in favour of the handler's own value.

Espo logs PSR-3 style, e.g. `Before-save formula script failed. {message}` plus a context
`message` key. The placeholders are substituted with Monolog's `PsrLogMessageProcessor`
before sending, so `traces.message` reads as a complete sentence. The context keys stay in
`customDimensions`.

## 1. Prerequisite

in Azure, an **Application Insights** resource, linked to a **Log Analytics Workspace**.

## 2. Install

Grab the zip from the [latest release](https://github.com/rodekruis/EspoCRM-knowledge-base/releases?q=azure-monitor-logs)
tagged `azure-monitor-logs-v<version>` and upload it in **Administration > Extensions**.

Releases are cut by [`.github/workflows/azure-monitor-logs.yml`](../../.github/workflows/azure-monitor-logs.yml)
on every push to `main` touching this folder, but only when `manifest.json` carries a version
that has no release yet. To build locally instead, run `.\build.ps1`.

### New release

Bump the version in **two** places, or CI fails, and push to `main`:

| File | What to change |
| --- | --- |
| `manifest.json` | `"version": "0.3.0"` — this is what decides whether a release is cut |
| `files/custom/Espo/Modules/AzureMonitorLogs/Log/AzureMonitorHandler.php` | `SDK_VERSION = 'php:espocrm-azure-monitor-logs:0.3.0'` |

---

## 3. Configure

Copy the **connection string** from **Application Insights > Overview**.

Then, in `data/config-internal.php`:

```php
'azureMonitorLogs' => [
    'enabled' => true,
    'connectionString' => 'InstrumentationKey=00000000-0000-0000-0000-000000000000;IngestionEndpoint=https://westeurope-5.in.applicationinsights.azure.com/;LiveEndpoint=https://westeurope.livediagnostics.monitor.azure.com/',
    'roleName' => 'vm-name',
],
```

Then rebuild: if EspoCRM is installed with Docker

```bash
sudo docker exec espocrm php /var/www/html/rebuild.php
```

or if EspoCRM runs directly on the VM

```bash
sudo php /var/www/espocrm/rebuild.php   # your app root
```

### All parameters

| Key | Default | Purpose |
| --- | --- | --- |
| `enabled` | `false` | Master switch. When false the handler becomes a `NullHandler`. |
| `connectionString` | – | Application Insights connection string. **Required.** |
| `roleName` | `espocrm` | Becomes `cloud_RoleName`; use it to tell instances apart. |
| `roleInstance` | hostname | Becomes `cloud_RoleInstance`. |
| `level` | follows `logger.level` | Override the level shipped to Azure. |
| `connectTimeout` | `2` | Seconds. Clamped to 0.5–30. |
| `timeout` | `5` | Seconds. Clamped to 0.5–30. |
| `bufferLimit` | `200` | Records buffered per request before an early flush. |
| `maxBatchBytes` | `262144` | Byte ceiling per POST. |
| `maxMessageLength` | `10000` | Message truncation. |
| `maxPropertyLength` | `8192` | Per-customDimension value truncation. |
| `maxPropertyCount` | `50` | Max customDimensions per record, including the reserved ones. |
| `breakerCooldown` | `60` | Seconds to stop calling Azure after a failure. Doubles per consecutive failure. |
| `breakerMaxCooldown` | `900` | Upper bound on the cooldown. |
| `excludeSql` | `true` | Drop records flagged `isSql`. |
| `cachePath` | `data/cache/azureMonitorLogs` | Circuit-breaker state directory. Setting it to `''` turns the breaker off entirely, so an Azure outage then costs a full timeout on every request that logs. |


## 4. Verify that it works

if EspoCRM is installed with Docker

```bash
sudo docker exec espocrm php /var/www/html/command.php azure-monitor-logs-test
```

or if EspoCRM runs directly on the VM

```bash
sudo php /var/www/espocrm/command.php azure-monitor-logs-test   # your app root
```

This runs two stages:

1. **Direct POST** — a hand-built `WARNING` envelope straight to the track endpoint. Proves
   the connection string, egress and TLS. Expects **HTTP 200** with `itemsAccepted` equal to
   `itemsReceived`.
2. **Real log record** — emitted through Espo's logger at the level the handler actually
   filters on (`level`, else `logger.level`, else `WARNING`). Proves the handler is wired
   into `logger.handlerList` and that the buffer flushes on shutdown. It is sent when the
   command's process exits, so the command cannot report its result inline.

Both markers share a random prefix. First telemetry can take a few minutes to appear.

From the **Application Insights** resource > Logs:

```kusto
traces
| where message startswith "AZMON-SMOKE-"
| order by timestamp desc
```

From the **Log Analytics workspace** > Logs, the same rows are in `AppTraces`:

```kusto
AppTraces
| where Message startswith "AZMON-SMOKE-"
| order by TimeGenerated desc
```

Expect **both** `-DIRECT` and `-LOGGER`. If only `-DIRECT` arrives, connectivity is fine but
the handler is not registered in `logger.handlerList` — or `logger.level` excludes the level
being emitted.


## 5. Rollback

Config-only — no database schema is touched.

- Quickest: set `azureMonitorLogs.enabled => false` and clear the cache.
- Full: uninstall in **Administration > Extensions**. `AfterUninstall` restores the
  previous `logger.handlerList` (or removes the key so Espo's default handler returns)
  and drops its own `azureMonitorLogsPreviousLogger` bookkeeping key. Your
  `azureMonitorLogs` block is left untouched — it becomes inert once the handler classes
  are gone, so a reinstall does not need the connection string pasted again.


### Security considerations

1. **On the connection string.** Microsoft states plainly that
   [instrumentation keys "aren't security tokens or security keys"](https://learn.microsoft.com/en-us/azure/azure-monitor/app/connection-strings) —
   the same ikey is embedded in browser JavaScript for client-side telemetry. It is a
   **write-only destination identifier**, not a credential that can read your data. The
   realistic risk is someone with the key injecting junk telemetry or burning your daily
   quota, not exfiltrating logs. Keep it in `data/config-internal.php` and out of git, but
   it does not warrant Key Vault ceremony.
2. **Log content is not sanitised beyond key-name redaction.** `ContextRedactor` masks
   *context keys* matching `pass|secret|token|apikey|authorization|auth|credential|privatekey|hash|salt|cookie|session`.
   It does **not** inspect values, and it does **not** touch the log *message*. Anything
   EspoCRM writes into a log message — usernames, email addresses, record IDs, exception
   text containing SQL — is shipped verbatim. Treat the Application Insights resource as a
   system holding personal data: EU region, explicit retention, restricted RBAC.
3. **`logger.sql` must stay `false`.** It logs full SQL including bound values. `excludeSql`
   drops records Espo flags with `isSql`, but a failed-query *exception message* is not flagged.
4. **`logger.printTrace` should stay `false`.** Stack traces can carry argument values.
5. **Log injection.** A user-controlled string (e.g. a login name) can contain newlines and
   text that mimics other log lines *inside* `message`. Structured fields cannot be spoofed.


## Operational notes

- **Cost.** Volume follows `logger.level` (`WARNING` by default). Dropping Espo to `INFO`
  or `DEBUG` multiplies ingestion cost. Consider a daily cap on the Application Insights
  resource as a backstop.
- **Batching.** One POST per request/CLI run. A cron run that logs heavily may issue
  several POSTs once `bufferLimit` or `maxBatchBytes` is exceeded.
- **Outage behaviour.** After a failure the circuit breaker opens for `breakerCooldown`
  seconds, doubling per consecutive failure up to `breakerMaxCooldown`. While open, requests
  skip Azure entirely and cost nothing. Records buffered during that window are dropped —
  the file log remains the durable copy.
- **Quota exhaustion** returns HTTP 402/439 and opens the breaker, so a blown daily cap
  degrades quietly rather than slowing every request.
- **Resetting the breaker.** Delete `data/cache/azureMonitorLogs/`.
- **Where extension errors surface.** Failures go to `error_log()`, never to Espo's logger.
  In Docker that is the container's stderr (`docker logs espocrm`). On a VM it is the
  PHP-FPM error log for web requests and the cron job's stderr for CLI runs — not
  `data/logs/espo.log`.
- **If local authentication is disabled** on the Application Insights resource (Entra-only
  ingestion), the endpoint returns 403 and this extension will not work as configured.
