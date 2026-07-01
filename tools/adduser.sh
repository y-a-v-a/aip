#!/bin/sh
# adduser.sh — add or update an allowed login (phone + PIN) without local PHP.
#
# Runs tools/make_user.php inside a throwaway official PHP container, writing to
# ./data/users.json in this repo. On a shared host, upload that file afterwards
# (README "Deploying to a shared PHP host", step 4). Re-run to change a PIN or
# add more people; PINs are stored only as bcrypt hashes, min 6 chars.
#
#   ./tools/adduser.sh +31612345678 123456
#   ./tools/adduser.sh                        # prompts; PIN entry is hidden
#
# Requires: Docker (no PHP on the host). The image is pulled once on first run.
set -eu

# Repo root — this script lives in tools/, so users.json lands in ./data/.
REPO=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
IMAGE=php:8.3-cli-alpine   # matches the Dockerfile; ships password_hash in core

phone=${1:-}
pin=${2:-}

if [ -z "$phone" ]; then
    printf 'Phone (e.g. +31612345678): '
    read -r phone
fi
if [ -z "$pin" ]; then
    printf 'PIN (min 6 chars, hidden): '
    stty -echo 2>/dev/null || true
    read -r pin
    stty echo 2>/dev/null || true
    printf '\n'
fi

# One docker command: mount the repo, run the existing CLI tool as the host user
# (so ./data/users.json is owned by you, not root), pass phone + PIN straight
# through to make_user.php, which validates and bcrypt-hashes the PIN.
docker run --rm \
    -v "$REPO":/app \
    -u "$(id -u):$(id -g)" \
    "$IMAGE" \
    php /app/tools/make_user.php "$phone" "$pin"

echo "-> wrote $REPO/data/users.json  (upload it to the shared host if deploying)"
