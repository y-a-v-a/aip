#!/bin/sh
# listusers.sh — list allowed logins (phone + date added), no PIN hashes.
#
#   ./tools/listusers.sh
#
# Reads ./data/users.json via a throwaway official PHP container. No PHP on the
# host required. If deploying, this is the same users.json you upload.
set -eu

REPO=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

docker run --rm -v "$REPO":/app -u "$(id -u):$(id -g)" php:8.3-cli-alpine \
    php /app/tools/list_users.php
