#!/bin/sh
# backup.sh — snapshot or restore the app's data (users, recipes, logs, pantry).
#
#   ./tools/backup.sh                         # -> ./backups/aip-data-<ts>.tar.gz
#   ./tools/backup.sh restore <file.tar.gz>   # overwrite the volume from a snapshot
#
# Operates on the Docker named volume 'aip_aip-data' — where `docker compose`
# keeps data/. No local PHP needed. For the shared host, download data/ via your
# host's file panel; keep the tarballs this makes as your off-host copy.
#
# Runs the tar as root (container default) so it can read every file and so
# restore preserves the www-data ownership the app needs to keep writing. On
# macOS the output file still lands owned by you (Docker Desktop maps it).
set -eu

REPO=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
VOL=aip_aip-data
BACKUP_DIR="$REPO/backups"

cmd=${1:-backup}
case "$cmd" in
  backup)
    mkdir -p "$BACKUP_DIR"
    ts=$(date +%Y%m%d-%H%M%S)
    out="aip-data-$ts.tar.gz"
    docker run --rm -v "$VOL":/data:ro -v "$BACKUP_DIR":/backup \
        alpine tar czf "/backup/$out" -C /data .
    echo "-> $BACKUP_DIR/$out"
    ;;
  restore)
    file=${2:-}
    [ -n "$file" ] || { echo "Usage: $0 restore <file.tar.gz>" >&2; exit 1; }
    [ -f "$file" ] || { echo "No such file: $file" >&2; exit 1; }
    dir=$(CDPATH= cd -- "$(dirname -- "$file")" && pwd)
    base=$(basename -- "$file")
    printf 'Restore %s into volume %s? This OVERWRITES current data. [y/N] ' "$base" "$VOL"
    read -r ans
    case "$ans" in [yY]*) ;; *) echo "Aborted."; exit 0 ;; esac
    docker run --rm -v "$VOL":/data -v "$dir":/backup:ro \
        alpine tar xzf "/backup/$base" -C /data
    echo "-> restored $base into $VOL"
    ;;
  *)
    echo "Usage: $0 [backup | restore <file.tar.gz>]" >&2
    exit 1
    ;;
esac
