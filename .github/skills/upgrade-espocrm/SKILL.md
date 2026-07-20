---
name: upgrade-espocrm
description: 'Upgrade an EspoCRM instance to a newer version (minor or major, e.g. 9.x → 10.x). Use when the user wants to upgrade, update, or bump EspoCRM, migrate across a major version boundary, apply the espocrm-installer upgrade, handle the v10 docker-compose mount migration, or troubleshoot/roll back a failed EspoCRM upgrade. Covers pre-upgrade checks, backups, customization review, running the upgrade, post-upgrade verification, rollback, and escalation strategies (version-by-version stepping).'
argument-hint: 'Target EspoCRM version (e.g. "10.2.0") — optional'
---

# Upgrade EspoCRM

Orchestrates a safe EspoCRM upgrade. The full step-by-step procedure, major-version specifics, troubleshooting, and rollback commands live in the [Administration wiki — Upgrade EspoCRM version](https://github.com/rodekruis/EspoCRM-knowledge-base/wiki/Administration#upgrade-espocrm-version). **Fetch that page at runtime and read it before acting** — it is the source of truth and may have been updated since this skill was written.

You (the agent) **can run the VM/SSH/root commands yourself** (via the connected terminal/SSH session) — `docker`, `command.sh`, editing files on the VM, `mysqldump`, etc. What you **cannot** do are **Azure portal actions** (VM/disk snapshots, managed-DB backups, resizing) and **EspoCRM admin-UI actions** (maintenance mode, cron, rebuild via UI). For those, ask the user to perform them and confirm **before** you proceed. Your job is to **run what you can, delegate what you can't, verify everything, and gatekeep**.

## Golden rules

1. **Delegate + confirm the manual gates.** Before each phase, make sure the **Azure** and **EspoCRM-UI** prerequisites for that phase are done — ask the user to do them and confirm, since you cannot. Never advance past a backup or maintenance-mode gate on assumption. The VM/SSH steps you can just run yourself (showing the command and its output).
2. **When in doubt, consult the official docs.** Fetch and cite [EspoCRM documentation](https://docs.espocrm.com/) — especially [Administration](https://docs.espocrm.com/administration/) and [server configuration](https://docs.espocrm.com/administration/server-configuration/) — and the [release notes](https://github.com/espocrm/espocrm/releases) for the exact target version. Never guess version-specific behavior.
3. **Protect customizations.** Review them (see below) and decide whether any must be moved or edited before upgrading.
4. **Verify, then trust.** After the upgrade, confirm the whole application is actually running. If it is not, roll back.

## Procedure

Work through these phases. Track them with a todo list and stop for user confirmation at each gate.

### Phase 0 — Pre-flight (run what you can, delegate the rest)

The items below marked **[Azure]** or **[EspoCRM]** you cannot do — ask the user to do them and confirm. The **[VM]** items you can run yourself via the terminal.

- [ ] Target version chosen and its [release notes](https://github.com/espocrm/espocrm/releases) reviewed for breaking changes.
- [ ] Does the jump cross a **major** boundary (e.g. 9.x → 10.x)? If so, the one-time steps in the procedure's *Major version upgrades* section apply.
- [ ] **[VM]** Enough disk space on the VM — run `df -h` yourself.
- [ ] **[EspoCRM]** **Maintenance mode enabled** — ask the user (Administration > Settings).
- [ ] **[Azure]** **VM backup** taken — ask the user.
- [ ] **[Azure]** **Database backup** taken, if an external managed DB is used — ask the user (for a self-managed DB you can take the dump yourself, see below).
- [ ] **[VM]** PHP version compatible with the target release — you can check on the VM.
- [ ] **[EspoCRM]** **Cron disabled** — ask the user (Administration > Settings).

Do not proceed past a backup or maintenance-mode gate until the user confirms the [Azure]/[EspoCRM] items are done. These map to the "Before upgrading" section of the [upgrade wiki page](https://github.com/rodekruis/EspoCRM-knowledge-base/wiki/Administration#upgrade-espocrm-version).

**If EspoCRM uses an external MySQL/MariaDB database** (i.e. not the bundled `espocrm-db` container), also confirm before proceeding:

- [ ] **DB engine + version meets the target release's requirements** — check MySQL/MariaDB min version for the target EspoCRM in [server configuration](https://docs.espocrm.com/administration/server-configuration/) and compare against the running server (you can run `SELECT VERSION();`). A DB too old for the new EspoCRM is a common upgrade blocker.
- [ ] **The DB user has DDL privileges** (CREATE, ALTER, INDEX, DROP). The upgrade runs schema migrations against the external DB; a read/write-only account will fail mid-migration.
- [ ] **A real database backup exists and its method matches the DB host.** Azure Database for MySQL → **[Azure]** ask the user to run portal *Backup and restore*. Self-managed MySQL/MariaDB → you can take the dump yourself: `mysqldump --single-transaction --routines --triggers -u <user> -p <db> > espo_pre_upgrade.sql`. The VM snapshot does **not** cover an external DB.
- [ ] Note the exact DB host/name/user from `data/config.php` so the same target is used for backup and any restore.

### Phase 1 — Review customizations

This repository's customizations all live under `custom/` and `client/custom/`, which are the folders EspoCRM (and the v10 mount migration) preserves across upgrades. Still, review them against the target version before upgrading:

- Back-end: `customization/duplication/`, `customization/globalFilters/`, `customization/fieldValidation/`, `customization/entities_nondeletable.md`, `customization/conditionalOptions/`.
- Client-side JS overrides (e.g. `client/custom/src/views/fields/phone.js`) that **extend core views** are the most fragile — a core view renamed or refactored in the new version can silently break an override. Check the target version's front-end for the parent views being extended.
- Metadata JSON under `custom/Espo/Custom/Resources/metadata/` (recordDefs, selectDefs, clientDefs, entityDefs) — confirm the keys/format are still valid for the target version.
- Custom PHP classes under `custom/Espo/Custom/Classes/` — confirm base classes / interfaces they rely on still exist.

Decide per customization whether to (a) leave as-is, (b) edit for the new version now, or (c) temporarily move aside and re-apply after. Tell the user your recommendation and get agreement before upgrading. When unsure whether an API changed, consult the [Development docs](https://docs.espocrm.com/development/).

### Phase 2 — Run the upgrade

Follow the *Upgrade* and, if applicable, *Major version upgrades* sections of the [upgrade wiki page](https://github.com/rodekruis/EspoCRM-knowledge-base/wiki/Administration#upgrade-espocrm-version). These are SSH/root actions on the VM — **you can run them yourself**. Run each command, show its output, and check the upgrade log (`sudo docker logs espocrm --tail 100 -f`) before continuing. Before running the upgrade, re-confirm the user has done the **[Azure]** backup and **[EspoCRM]** maintenance-mode gates from Phase 0.

For a major jump (v10+), the `docker-compose.yaml` mount migration and full `command.sh stop`/`start` are mandatory one-time steps — do not skip and do not substitute a plain `docker restart`.

### Phase 3 — Verify the whole application is running

After the upgrade, confirm the instance is genuinely healthy — not just "the command finished". Run the VM/log checks yourself; the browser and admin-UI checks you delegate to the user:

- [ ] All containers up: `sudo docker ps` shows `espocrm`, `espocrm-nginx`, `espocrm-daemon`, `espocrm-websocket` — and `espocrm-db` **only if the bundled DB is used**. With an **external** MySQL/MariaDB there is no `espocrm-db` container; instead confirm EspoCRM can reach the external DB (successful login and record load already prove connectivity, or check `data/espocrm/data/logs/` for connection errors).
- [ ] No errors in `sudo docker logs espocrm --tail 100` (and nginx/daemon/websocket logs). Watch for DB connection or migration errors in particular when the DB is external.
- [ ] The schema migration completed against the external DB (no half-applied migration errors in the upgrade log).
- [ ] The web UI loads and login works (not blank / not 404) — **[EspoCRM]** ask the user (or a browser tool if available).
- [ ] **[EspoCRM]** Maintenance mode **disabled** and cron **re-enabled** — ask the user.
- [ ] A quick functional smoke test: open a record, run a saved flow, confirm customizations still behave — **[EspoCRM]** ask the user.
- [ ] Browser cache/data cleared to rule out stale front-end assets.

If something is broken, first try the matching entry in the procedure's **Troubleshooting** section (stuck daemon/websocket = maintenance mode still on; 404 after mount migration; blank page / missing buttons for everyone = stale front-end files; per-user missing buttons = browser cache).

### Phase 4 — Roll back if it cannot be made healthy

If the application cannot be restored to a healthy state, **revert** using the *Rollback* section: `sudo /var/www/espocrm/command.sh restore "<archive>.tar.gz"`. Confirm the pre-upgrade backup exists before starting any upgrade — a rollback is only possible if Phase 0's backups were taken.

**External DB — critical:** `command.sh restore` restores the app files (and the bundled DB volume) but does **not** touch an external database. By the time you roll back, the upgrade has already migrated the external schema, so restoring files alone leaves **old code against a new schema** — a broken, inconsistent state. You must restore the external DB **as well**, to the same pre-upgrade backup, so code and schema match:

- Azure Database for MySQL → portal *Backup and restore* (point-in-time / the pre-upgrade backup).
- Self-managed → re-import the dump, e.g. `sudo /var/www/espocrm/command.sh import-sql "/path/to/espo_pre_upgrade.sql"`, or `mysql -u <user> -p <db> < espo_pre_upgrade.sql`.

Restore files and DB from the **same point in time**, then re-verify Phase 3.

## When things go wrong — escalation strategies

Apply in roughly this order:

1. **Read the logs first.** `sudo docker logs <container> --tail 100`. The error usually names the cause (extension version, mount typo, maintenance mode).
2. **Match a known troubleshooting entry** in the [upgrade wiki page](https://github.com/rodekruis/EspoCRM-knowledge-base/wiki/Administration#upgrade-espocrm-version) before improvising.
3. **Update blocking extensions first.** If the upgrade fails citing an extension version (e.g. Advanced Pack on the 9→10 jump), restore the pre-upgrade backup, update that extension, then retry the upgrade.
4. **Upgrade version-by-version instead of one-off.** If a large jump (e.g. 8.x → 10.x) fails or the release notes warn of chained migrations, restore the backup and upgrade **one significant version at a time** (…→9.x→…→10.x), verifying health (Phase 3) between each hop. EspoCRM's own upgrade path historically assumes stepping through majors rather than skipping them — confirm the supported path in the [release notes](https://github.com/espocrm/espocrm/releases) and [docs](https://docs.espocrm.com/administration/upgrading/).
5. **Research current best practice** for the specific error before deeper changes: check the [EspoCRM forum](https://forum.espocrm.com/), the relevant [GitHub issue tracker](https://github.com/espocrm/espocrm/issues), and the [upgrading docs](https://docs.espocrm.com/administration/upgrading/). Prefer the official/supported fix over ad-hoc edits.
6. **Rebuild after manual metadata/customization fixes:** `sudo /var/www/espocrm/command.sh rebuild` (or Administration > Rebuild), then re-verify Phase 3.
7. **If still broken, roll back** (Phase 4) rather than leaving the instance in a partial state, and report findings so the procedure doc can be updated.

## After a successful upgrade

Consider recording any new lesson (unexpected extension requirement, a customization that needed editing, whether `public/` needed refreshing) in the [Administration wiki page](https://github.com/rodekruis/EspoCRM-knowledge-base/wiki/Administration#upgrade-espocrm-version) so the next upgrade benefits.
