#!/bin/sh
# deluser.sh — revoke a login by phone number.
#
#   ./tools/deluser.sh +31612345678
#   ./tools/deluser.sh                # prompts for the phone
#
# Rewrites ./data/users.json via a throwaway official PHP container (no local
# PHP needed). Upload the file to the shared host afterwards if deploying.
set -eu

REPO=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

phone=${1:-}
if [ -z "$phone" ]; then
    printf 'Phone to remove (e.g. +31612345678): '
    read -r phone
fi

docker run --rm -v "$REPO":/app -u "$(id -u):$(id -g)" php:8.3-cli-alpine \
    php /app/tools/del_user.php "$phone"

echo "-> updated $REPO/data/users.json  (upload it to the shared host if deploying)"
