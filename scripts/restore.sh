#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $0 <backup.sql|backup.sql.gz|volume-sqldb.tgz>" >&2
  exit 1
}

[ $# -ge 1 ] || usage

TARGET="$1"
: "${DB_CONTAINER:=mariadb_gam_quiz}"
: "${DB_ROOT_USER:=root}"
: "${DB_ROOT_PASSWORD:=exam}"

if [[ "$TARGET" == *.sql || "$TARGET" == *.sql.gz ]]; then
  echo "Restoring SQL dump into container '$DB_CONTAINER' from $TARGET"
  if [[ "$TARGET" == *.gz ]]; then
    gunzip -c "$TARGET" | docker exec -i "$DB_CONTAINER" mysql -u"$DB_ROOT_USER" -p"$DB_ROOT_PASSWORD"
  else
    cat "$TARGET" | docker exec -i "$DB_CONTAINER" mysql -u"$DB_ROOT_USER" -p"$DB_ROOT_PASSWORD"
  fi
  echo "Restore complete."
elif [[ "$TARGET" == *.tgz || "$TARGET" == *.tar.gz ]]; then
  echo "Restoring data directory from archive $TARGET into ./data/mariadb (bind mount)"
  mkdir -p data/mariadb
  echo "Stopping DB container '$DB_CONTAINER'..."
  docker stop "$DB_CONTAINER" >/dev/null
  # Extract as root inside a helper container to preserve ownership/permissions
  ABS_TARGET="$(cd "$(dirname "$TARGET")" && pwd)/$(basename "$TARGET")"
  ABS_DATA="$(cd data && pwd)"
  docker run --rm -v "$ABS_DATA":/to -v "$ABS_TARGET":/backup.tgz alpine \
    sh -c 'set -e; rm -rf /to/mariadb; mkdir -p /to; tar xzf /backup.tgz -C /to'
  echo "Starting DB container..."
  docker start "$DB_CONTAINER" >/dev/null
  echo "Data restore complete."
else
  echo "Unsupported file type: $TARGET" >&2
  usage
fi

