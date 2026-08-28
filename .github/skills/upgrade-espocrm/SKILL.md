---
name: upgrade-espocrm
description: 'Upgrade an EspoCRM instance to a newer version (minor or major, e.g. 9.x → 10.x). Use when the user wants to upgrade, update, or bump EspoCRM, migrate across a major version boundary, apply the espocrm-installer upgrade, run post-upgrade verification, or troubleshoot/roll back a failed EspoCRM upgrade. Covers pre-upgrade checks, backups, baseline capture, customization review, running the upgrade, applying any version-specific one-time steps documented on the wiki, post-upgrade verification, rollback, and escalation strategies (version-by-version stepping).'
argument-hint: 'Target EspoCRM version (e.g. "10.2.0") — optional'
---

# Upgrade EspoCRM

Orchestrates a safe EspoCRM upgrade. The full step-by-step procedure, major-version specifics, troubleshooting, and rollback commands live in the [Administration wiki — Upgrade EspoCRM version](https://github.com/rodekruis/EspoCRM-knowledge-base/wiki/Administration#upgrade-espocrm-version). **Fetch that page at runtime and read it before acting** — it is the source of truth and may have been updated since this skill was written. Any version-specific one-time steps (e.g. those that apply when crossing a major boundary) live there, not here.

You (the agent) **can run the VM/SSH/root commands yourself** (via the connected terminal/SSH session) — `docker`, `command.sh`, editing files on the VM, `mysqldump`, etc. What you **cannot** do are **Azure portal actions** (VM/disk snapshots, managed-DB backups, resizing) and **EspoCRM admin-UI actions** (maintenance mode, cron, rebuild via UI). For those, ask the user to perform them and confirm **before** you proceed. Your job is to **run what you can, delegate what you can't, verify everything, and gatekeep**.

## Golden rules

1. **Delegate + confirm the manual gates.** Before each phase, make sure the **Azure** and **EspoCRM-UI** prerequisites for that phase are done — ask the user to do them and confirm, since you cannot. Never advance past a backup or maintenance-mode gate on assumption. The VM/SSH steps you can just run yourself (showing the command and its output).
2. **When in doubt, consult the official docs.** Fetch and cite [EspoCRM documentation](https://docs.espocrm.com/) — especially [Administration](https://docs.espocrm.com/administration/) and [server configuration](https://docs.espocrm.com/administration/server-configuration/) — and the [release notes](https://github.com/espocrm/espocrm/releases) for the exact target version. Never guess version-specific behavior.
3. **Protect customizations.** Review them (see below) and decide whether any must be moved or edited before upgrading.
4. **Verify, then trust.** After the upgrade, confirm the whole application is actually running. If it is not, roll back.
5. **Verify by content, not status codes — and diff against a baseline.** A `200` from `curl` and "containers Up" do **not** prove a working front-end or intact data. Assert on actual asset content and record counts, and compare them to a pre-upgrade baseline — absolute post-upgrade numbers can't detect data loss or newly-broken flows.
6. **Prove the app bootstraps, not just that it answers.** `GET /` is a static file served by the web server and `GET /api/v1/App/user` returns `401` before much of the application loads — **both can pass while every login returns 500.** The minimum evidence that the backend is genuinely alive is `GET /api/v1/Settings` **and** `GET /api/v1/I18n` returning `200`; these are the calls the login screen itself makes. Never report an instance as healthy or "service restored" on the strength of `/` plus a `401`.

## Procedure

Work through these phases. Track them with a todo list and stop for user confirmation at each gate.

### Phase 0 — Pre-flight (run what you can, delegate the rest)

The items below marked **[Azure]** or **[EspoCRM]** you cannot do — ask the user to do them and confirm. The **[VM]** items you can run yourself via the terminal.

- [ ] Target version chosen and its [release notes](https://github.com/espocrm/espocrm/releases) reviewed for breaking changes.
- [ ] Does the jump cross a **major** boundary (e.g. 9.x → 10.x)? If so, the one-time steps in the procedure's *Major version upgrades* section on the wiki apply — read them before continuing.
- [ ] **[VM]** Enough disk space on the VM — run `df -h` yourself.
- [ ] **[EspoCRM]** **Maintenance mode enabled** — ask the user (Administration > Settings).
- [ ] **[Azure]** **VM backup** taken — ask the user.
- [ ] **[Azure]** **Database backup** taken, if an external managed DB is used — ask the user (for a self-managed DB you can take the dump yourself, see below).
- [ ] **[VM]** PHP version compatible with the target release — you can check on the VM.
- [ ] **[EspoCRM]** **Cron disabled** — ask the user (Administration > Settings).
- [ ] **[VM]** **Capture a verification baseline** (so Phase 3 is diffable). Save to the backup dir: exact `COUNT(*)` (`deleted=0`) for key + custom entities, the count of failed BPMN flow nodes, installed extension names/versions/`is_installed`, and the size/hash of a served front-end asset. Counts and flow-failures are meaningless without a "before".
- [ ] **[VM]** **Record the resolved image tags/digests** of every service in the compose file (`docker inspect <container> --format '{{.Config.Image}} {{.Image}}'`). Services pinned to floating tags such as `nginx`, `mariadb:latest` or `certbot` are silently upgraded by the `docker compose pull` inside `command.sh upgrade` — a database or web-server major version can move without anyone deciding to. Capturing this before and after makes that drift visible instead of accidental, and tells you whether a post-upgrade problem came from EspoCRM or from an infrastructure image that moved underneath it.

Do not proceed past a backup or maintenance-mode gate until the user confirms the [Azure]/[EspoCRM] items are done. These map to the "Before upgrading" section of the [upgrade wiki page](https://github.com/rodekruis/EspoCRM-knowledge-base/wiki/Administration#upgrade-espocrm-version).

**If EspoCRM uses an external MySQL/MariaDB database** (i.e. not the bundled `espocrm-db` container), also confirm before proceeding:

- [ ] **DB engine + version meets the target release's requirements** — check MySQL/MariaDB min version for the target EspoCRM in [server configuration](https://docs.espocrm.com/administration/server-configuration/) and compare against the running server (you can run `SELECT VERSION();`). A DB too old for the new EspoCRM is a common upgrade blocker.
- [ ] **The DB user has DDL privileges** (CREATE, ALTER, INDEX, DROP). The upgrade runs schema migrations against the external DB; a read/write-only account will fail mid-migration.
- [ ] **A real database backup exists and its method matches the DB host.** Azure Database for MySQL → **[Azure]** ask the user to run portal *Backup and restore*. Self-managed MySQL/MariaDB → you can take the dump yourself: `mysqldump --single-transaction --routines --triggers -u <user> -p <db> > espo_pre_upgrade.sql`. The VM snapshot does **not** cover an external DB.
- [ ] Note the exact DB host/name/user from `data/config.php` so the same target is used for backup and any restore.

### Phase 1 — Review customizations

This repository's customizations all live under `custom/` and `client/custom/`, which are the folders EspoCRM preserves across upgrades. Still, review them against the target version before upgrading:

- Back-end: `customization/duplication/`, `customization/globalFilters/`, `customization/fieldValidation/`, `customization/entities_nondeletable.md`, `customization/conditionalOptions/`.
- Client-side JS overrides (e.g. `client/custom/src/views/fields/phone.js`) that **extend core views** are the most fragile — a core view renamed or refactored in the new version can silently break an override. Check the target version's front-end for the parent views being extended.
- Metadata JSON under `custom/Espo/Custom/Resources/metadata/` (recordDefs, selectDefs, clientDefs, entityDefs) — confirm the keys/format are still valid for the target version.
- Custom PHP classes under `custom/Espo/Custom/Classes/` — confirm base classes / interfaces they rely on still exist.

Decide per customization whether to (a) leave as-is, (b) edit for the new version now, or (c) temporarily move aside and re-apply after. Tell the user your recommendation and get agreement before upgrading. When unsure whether an API changed, consult the [Development docs](https://docs.espocrm.com/development/).

### Phase 2 — Run the upgrade

Follow the *Upgrade* and, if applicable, *Major version upgrades* sections of the [upgrade wiki page](https://github.com/rodekruis/EspoCRM-knowledge-base/wiki/Administration#upgrade-espocrm-version). These are SSH/root actions on the VM — **you can run them yourself**. Run each command, show its output, and check the upgrade log (`sudo docker logs espocrm --tail 100 -f`) before continuing. Before running the upgrade, re-confirm the user has done the **[Azure]** backup and **[EspoCRM]** maintenance-mode gates from Phase 0.

For a major jump, complete the one-time steps in the wiki's *Major version upgrades* section exactly as written — do not skip them, and do not substitute a plain `docker restart` for any step that requires re-reading the compose file. Some major upgrades also leave the web-server-served front-end stale even though the backend responds; the wiki documents when and how to refresh it. Verify the outcome by content in Phase 3 rather than assuming the command finishing means success. If an extension version blocks the upgrade, **classify the failure before choosing a recovery path** (see Phase 4) — a migration that aborts before touching the schema does not need a full restore.

**Changing the image version and changing the mount layout are one atomic step, in both directions.** A major release can change what the image contains — e.g. whether the application itself ships inside it — so the compose mounts that are correct for the new version may be wrong for the old one and vice versa. If you move the image tag, move the mounts with it in the same edit, then recreate the containers (`command.sh stop` / `start`), then re-verify. The wiki holds the per-version mount specifics.

**Clear `data/cache` whenever you change the code version, in either direction.** `data/` is shared with the host and survives an image change, so a cache generated by one version can be read by another and reference classes that don't exist there — which typically surfaces as a 500 on login rather than an obvious upgrade error.

### Phase 3 — Verify the whole application is running

After the upgrade, confirm the instance is genuinely healthy — **not just "the command finished", and not just an HTTP 200.** Run the VM/log/SQL checks yourself; the browser and admin-UI checks you delegate to the user. Where possible, **diff against the Phase 0 baseline** rather than trusting absolute numbers.

Run this whole phase again after **any** intermediate state change — a revert, an image re-pin, a mount edit, a cache clear — not only once at the end. Every such change is a new deployment and deserves the same evidence. Announcing "service is restored" after a mid-run revert is a completion claim and needs the same proof as the final one.

**Structural (no auth):**
- [ ] All containers up: `sudo docker ps` shows `espocrm`, `espocrm-nginx`, `espocrm-daemon`, `espocrm-websocket` — and `espocrm-db` **only if the bundled DB is used** — with uptimes **growing**, none `Restarting`. (With an **external** DB there is no `espocrm-db`; confirm connectivity via a successful record load or `data/espocrm/data/logs/` instead.)
- [ ] **Application bootstraps:** `GET /api/v1/Settings` and `GET /api/v1/I18n` both return `200`. These exercise the PHP app end-to-end; `GET /` and a `401` from `/api/v1/App/user` do not (see golden rule 6). If either returns 500, read `data/espocrm/data/logs/` — the class or file named in the `CRITICAL` usually identifies the cause.
- [ ] `sudo docker exec espocrm-daemon bin/command app-check` → migration / DB / maintenance / cron all `OK`; `command.php rebuild` exits 0.
- [ ] No *new* `ERROR|CRITICAL` in `sudo docker logs espocrm --tail 100` or in `data/espocrm/data/logs/` since the upgrade timestamp (watch especially for DB/migration errors when the DB is external).
- [ ] `SELECT COUNT(*) FROM job WHERE status='Failed' AND modified_at > <upgrade_ts>` = 0.

**Front-end parity (a `200` does not prove the UI works):**
- [ ] Verify a served front-end asset matches the copy inside the app image (compare size/hash host-vs-image). If they differ — which can happen after a major upgrade — the web server is serving stale files; apply the refresh documented in the wiki's *Major version upgrades* / *Troubleshooting* section, then reload the web server and re-check.

**Data + functional (diff vs Phase 0 baseline):**
- [ ] Exact `COUNT(*)` (`deleted=0`) for key + custom entities **≥ baseline** (no data loss). Use `COUNT(*)`, not `information_schema.table_rows` (an estimate).
- [ ] Extension rows unchanged and `is_installed=1`.
- [ ] Authenticated API smoke test with a stored read-only API key: `GET /api/v1/<Entity>?maxSize=1` → `200` for a core entity **and** each custom entity/controller (proves ACL + record load, not just routing). If no such key exists on the instance, **say so explicitly and delegate the equivalent check to the user** — do not silently drop this item, and do not let the unauthenticated checks stand in for it.
- [ ] **Canary flow**: touch a dedicated test record that fires a known Workflow/BPMN flow; assert its marker field updates within N seconds, and that the failed-flow-node count **did not increase** vs baseline (pre-existing failures are OK; *new* ones fail the upgrade).

**Delegate (UI):**
- [ ] The web UI loads and login works (not blank / not 404), verified in a **fresh/incognito browser** — **[EspoCRM]** ask the user (or a browser tool if available).
- [ ] **[EspoCRM]** Maintenance mode **disabled** and cron **re-enabled** — ask the user.
- [ ] A quick functional smoke test: open a record, run a saved flow, confirm customizations still behave — **[EspoCRM]** ask the user.
- [ ] Browser cache/data cleared to rule out stale front-end assets.

If something is broken, first try the matching entry in the procedure's **Troubleshooting** section on the wiki.

### Phase 4 — Roll back if it cannot be made healthy

**First classify the failure — it decides how heavy the recovery needs to be.** Read the upgrade log and establish whether the database schema was actually migrated:

- **Schema not yet touched** (e.g. the run aborts during *prepare* migrations, or refuses to start on a compatibility/PHP gate). The database still matches the old code, so the cheapest correct recovery is to **put the image tag back to the last-good version — together with the mount layout that version requires — clear `data/cache`, recreate the containers and re-verify Phase 3.** No restore needed. Prefer this: it is faster, loses nothing, and keeps the instance available while you fix the blocker. It is also often a *prerequisite* for fixing the blocker, since installing or updating an extension needs a working admin UI.
- **Schema already migrated, app unhealthy.** Now code and schema disagree and only a restore can realign them — continue below.

If the application cannot be restored to a healthy state, **revert** using the *Rollback* section: `sudo /var/www/espocrm/command.sh restore "<archive>.tar.gz"`. Confirm the pre-upgrade backup exists before starting any upgrade — a rollback is only possible if Phase 0's backups were taken.

**External DB — critical:** `command.sh restore` restores the app files (and the bundled DB volume) but does **not** touch an external database. By the time you roll back, the upgrade has already migrated the external schema, so restoring files alone leaves **old code against a new schema** — a broken, inconsistent state. You must restore the external DB **as well**, to the same pre-upgrade backup, so code and schema match:

- Azure Database for MySQL → portal *Backup and restore* (point-in-time / the pre-upgrade backup).
- Self-managed → re-import the dump, e.g. `sudo /var/www/espocrm/command.sh import-sql "/path/to/espo_pre_upgrade.sql"`, or `mysql -u <user> -p <db> < espo_pre_upgrade.sql`.

Restore files and DB from the **same point in time**, then re-verify Phase 3.

## When things go wrong — escalation strategies

Apply in roughly this order:

1. **Read the logs first.** `sudo docker logs <container> --tail 100`. The error usually names the cause (extension version, mount typo, maintenance mode).
2. **Match a known troubleshooting entry** in the [upgrade wiki page](https://github.com/rodekruis/EspoCRM-knowledge-base/wiki/Administration#upgrade-espocrm-version) before improvising.
3. **Update blocking extensions first.** If the upgrade fails citing an extension version, classify per Phase 4: if the schema is untouched, re-pin the image (plus matching mounts) to restore the admin UI, update the extension there, then retry the upgrade. Only fall back to restoring the pre-upgrade backup if the schema was already migrated.
4. **Upgrade version-by-version instead of one-off.** If a large jump fails or the release notes warn of chained migrations, restore the backup and upgrade **one significant version at a time**, verifying health (Phase 3) between each hop. EspoCRM's own upgrade path historically assumes stepping through majors rather than skipping them — confirm the supported path in the [release notes](https://github.com/espocrm/espocrm/releases) and [docs](https://docs.espocrm.com/administration/upgrading/).
5. **Research current best practice** for the specific error before deeper changes: check the [EspoCRM forum](https://forum.espocrm.com/), the relevant [GitHub issue tracker](https://github.com/espocrm/espocrm/issues), and the [upgrading docs](https://docs.espocrm.com/administration/upgrading/). Prefer the official/supported fix over ad-hoc edits.
6. **Rebuild after manual metadata/customization fixes:** `sudo /var/www/espocrm/command.sh rebuild` (or Administration > Rebuild), then re-verify Phase 3.
7. **If still broken, roll back** (Phase 4) rather than leaving the instance in a partial state, and report findings so the procedure doc can be updated.

## After a successful upgrade

Consider recording any new lesson (an unexpected extension requirement, a customization that needed editing, a version-specific step that was needed) in the [Administration wiki page](https://github.com/rodekruis/EspoCRM-knowledge-base/wiki/Administration#upgrade-espocrm-version) so the next upgrade benefits — keep version-specific mechanics on the wiki, not in this skill.

Where practical, fold Phase 0's baseline capture and Phase 3's checks into a single `verify-espocrm.sh` run twice (`--baseline` before, `--compare` after) that exits non-zero on any failure — so data loss, a stale front-end, and newly-broken flows are caught by the script rather than reported by a user.