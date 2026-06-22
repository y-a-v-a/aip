#!/bin/sh
set -e

# Dev convenience: when DEV_SEED=1 (set only in docker-compose.yml), make sure a
# known login exists so you don't have to run make_user.php after every
# `docker compose down -v`. This never runs on the shared host (Apache serves
# the files directly; there is no entrypoint), so no dev credential leaks there.
if [ "$DEV_SEED" = "1" ]; then
    php /app/tools/make_user.php "${DEV_PHONE:-+31644444444}" "${DEV_PIN:-1234}" \
        || echo "dev seed skipped (make_user failed)"
fi

exec "$@"
