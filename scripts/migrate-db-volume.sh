#!/usr/bin/env bash
set -euo pipefail

# Migrate the MariaDB data volume from the default Docker named volume
# (db-data / <project>_db-data) to a bind mount at ./data/mariadb so the
# raw files are visible on the host filesystem for backups and resilience.
#
# Steps performed:
#   1. docker compose down
#   2. Copy existing data from the named volume to ./data/mariadb
#   3. Rewrite docker-compose.yml to use the bind mount
#   4. docker compose up -d
#
# You can override defaults via environment variables:
#   COMPOSE_FILE (default: docker-compose.yml)
#   PROJECT_NAME (default: auto-detected from docker compose config)
#   VOLUME_NAME  (default: ${PROJECT_NAME}_db-data)
#   BIND_PATH    (default: data/mariadb)
#
# Usage:
#   scripts/migrate-db-volume.sh

COMPOSE_FILE=${COMPOSE_FILE:-docker-compose.yml}
BIND_PATH=${BIND_PATH:-data/mariadb}

if [ ! -f "$COMPOSE_FILE" ]; then
  echo "Error: $COMPOSE_FILE not found. Run this from the repository root or set COMPOSE_FILE." >&2
  exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "Error: docker CLI not found in PATH." >&2
  exit 1
fi

if ! command -v docker compose >/dev/null 2>&1; then
  echo "Error: docker compose plugin not available." >&2
  exit 1
fi

# Determine project name the same way docker compose does, unless supplied
if [ -z "${PROJECT_NAME:-}" ]; then
  PROJECT_NAME=$(docker compose -f "$COMPOSE_FILE" ls --quiet 2>/dev/null | head -n1)
  if [ -z "$PROJECT_NAME" ]; then
    PROJECT_NAME=$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]_-')
  fi
fi

VOLUME_NAME=${VOLUME_NAME:-${PROJECT_NAME}_db-data}

echo "Using compose file: $COMPOSE_FILE"
echo "Detected project name: $PROJECT_NAME"
echo "Named volume to migrate: $VOLUME_NAME"
echo "Bind mount destination: $BIND_PATH"

# Stop the stack (ignore if services not running)
echo "[1/4] Stopping compose project"
docker compose -f "$COMPOSE_FILE" down || true

# Ensure destination directory exists with safe permissions
mkdir -p "$BIND_PATH"
chmod 750 "$BIND_PATH"

# Copy data from named volume if it exists
if docker volume inspect "$VOLUME_NAME" >/dev/null 2>&1; then
  echo "[2/4] Copying existing data from volume '$VOLUME_NAME'"
  docker run --rm \
    -v "$VOLUME_NAME:/from" \
    -v "$(cd "$BIND_PATH" && pwd):/to" \
    alpine sh -c 'set -e; cd /from && cp -a . /to'
else
  echo "[2/4] Volume '$VOLUME_NAME' not found. Skipping copy (fresh install?)."
fi

# Update compose file if needed
BIND_ENTRY="      - './${BIND_PATH#./}:/bitnami/mariadb'"
NAMED_ENTRY="      - 'db-data:/bitnami/mariadb'"
if grep -q "${BIND_ENTRY//\//\/}" "$COMPOSE_FILE"; then
  echo "[3/4] Compose file already uses bind mount."
else
  if grep -q "${NAMED_ENTRY//\//\/}" "$COMPOSE_FILE"; then
    echo "[3/4] Updating compose file to use bind mount"
    python3 - "$COMPOSE_FILE" "$NAMED_ENTRY" "$BIND_ENTRY" <<'PY'
import sys
from pathlib import Path
compose_path = Path(sys.argv[1])
old = sys.argv[2]
new = sys.argv[3]
text = compose_path.read_text()
if new in text:
    sys.exit(0)
if old not in text:
    sys.exit("Expected volume mapping '%s' not found in %s" % (old, compose_path))
compose_path.write_text(text.replace(old, new, 1))
PY
  else
    echo "[3/4] Warning: expected volume mapping not found; please update $COMPOSE_FILE manually." >&2
  fi
fi

# Bring stack back up
echo "[4/4] Starting compose project"
docker compose -f "$COMPOSE_FILE" up -d

echo "Done. MariaDB should now persist data in '$BIND_PATH'."
echo "Remember to remove the unused named volume with 'docker volume rm $VOLUME_NAME' once you verify the migration."
