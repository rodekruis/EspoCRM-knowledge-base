# espocrm-image-refresh

Weekly refresh of the infrastructure containers (nginx, the database and certbot) of a dockerised EspoCRM
installed with [espocrm-installer](https://github.com/espocrm/espocrm-installer), so their operating
system packages and server software keep receiving security fixes between EspoCRM upgrades.

The database can be either of the two layouts the installer has used:

| Service | Image pinned to e.g. | Upgrade step after a new image | Table check |
|---|---|---|---|
| `espocrm-db` | `mariadb:11.8` | `MARIADB_AUTO_UPGRADE: "1"` (required) runs `mariadb-upgrade` | `mariadb-check --check-upgrade` |
| `espocrm-mysql` | `mysql:8.4` | `mysqld` upgrades itself at startup (refused if run with `--upgrade=NONE` or `MINIMAL`) | `CHECK TABLE ... FOR UPGRADE` (the 8.4 image has no `mysqlcheck`) |

The script detects which one the compose file uses. The site's host name for the health check comes
from `NGINX_HOST` on the nginx container, or else from `ESPOCRM_SITE_URL` on the `espocrm` service.

Installation, prerequisites and how to check it: see the wiki page
[Security > Container image updates](https://github.com/rodekruis/EspoCRM-knowledge-base/wiki/Security#container-image-updates).

| File | Install to |
|---|---|
| `espocrm-image-refresh` | `/usr/local/sbin/espocrm-image-refresh` (mode 755) |
| `espocrm-image-refresh.service` | `/etc/systemd/system/` |
| `espocrm-image-refresh.timer` | `/etc/systemd/system/` (runs Sundays 05:00 UTC) |

Manual options, for testing:
- `--force nginx` / `--force db` — recreate that service even if its image is unchanged
- `--force nginx --test-rollback` — simulate a failed health check to exercise the nginx rollback

Tested on deh-espocrm-global-demo (EspoCRM 10.0.10, nginx:stable 1.30.6, mariadb:11.8) in October 2026:
no-op run, forced nginx refresh, forced nginx rollback and forced MariaDB refresh with verified dump.

MySQL support tested on HRC-HealthIM-2 (EspoCRM 10.0.11, nginx:stable 1.30.6, mysql:8.4.13) in October 2026,
without recreating any container: service and engine detection, pin checks (including refusal of a
floating `mysql:8`, `--upgrade=NONE` and a floating `nginx`), site host from `ESPOCRM_SITE_URL`, health check,
`CHECK TABLE ... FOR UPGRADE` over all 189 tables, the startup wait against the log of a real
8.0.46 → 8.4.13 server upgrade, and a verified `mysqldump`. **Not yet run:** a full refresh that actually
replaces the MySQL container. Do that once, supervised, before relying on the timer for a MySQL instance:
`sudo espocrm-image-refresh --force db`.
