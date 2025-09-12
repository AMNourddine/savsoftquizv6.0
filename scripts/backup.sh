#!/usr/bin/env bash
set -euo pipefail

# Configuration (override via env vars)
: "${DB_CONTAINER:=mariadb_gam_quiz}"
: "${DB_NAME:=exam}"
: "${DB_ROOT_USER:=root}"
: "${DB_ROOT_PASSWORD:=exam}"
: "${BACKUP_DIR:=backups}"

TS="$(date +%Y%m%d-%H%M%S)"
SQL_GZ="$BACKUP_DIR/${DB_NAME}-${TS}.sql.gz"
DATA_TGZ="$BACKUP_DIR/volume-sqldb-${TS}.tgz"

mkdir -p "$BACKUP_DIR"

echo "[1/3] Dumping database '$DB_NAME' from container '$DB_CONTAINER' to $SQL_GZ"
if ! docker ps --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
  echo "Error: container '${DB_CONTAINER}' not running" >&2
  exit 1
fi

# Logical backup
docker exec -i "$DB_CONTAINER" \
  mysqldump -u"$DB_ROOT_USER" -p"$DB_ROOT_PASSWORD" --databases "$DB_NAME" \
  | gzip -9 > "$SQL_GZ"

echo "[2/3] Creating data archive (bind mount) if present"
if [ -d "data/mariadb" ]; then
  tar czf "$DATA_TGZ" -C data mariadb
  echo "Created $DATA_TGZ"
else
  echo "Skipped data archive: ./data/mariadb not found (using named volume?)"
fi

echo "[3/3] Done. Files in $BACKUP_DIR:"
ls -lh "$BACKUP_DIR"

