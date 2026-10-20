# espocrm-image-refresh

Weekly refresh of the infrastructure containers (nginx, MariaDB, certbot) of a dockerised EspoCRM
installed with [espocrm-installer](https://github.com/espocrm/espocrm-installer), so their operating
system packages and server software keep receiving security fixes between EspoCRM upgrades.

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
