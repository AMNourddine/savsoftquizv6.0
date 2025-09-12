#!/usr/bin/env bash
set -euo pipefail

# Ensures bind-mounted data directory has expected ownership for Bitnami MariaDB
# Bitnami images typically run as uid 1001 and expect group root on /bitnami

: "${DB_CONTAINER:=mariadb_gam_quiz}"

echo "Fixing ownership inside container '$DB_CONTAINER' (requires root exec)"
docker exec -u 0 "$DB_CONTAINER" bash -lc 'chown -R 1001:root /bitnami/mariadb || true && find /bitnami/mariadb -maxdepth 2 -type d -ls'
echo "Done."

