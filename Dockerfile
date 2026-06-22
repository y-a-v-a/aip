# Small PHP image. The official PHP images ship with the curl extension
# enabled by default, which is all lib/claude.php needs — no extra installs.
FROM php:8.3-cli-alpine

WORKDIR /app
COPY . /app

# Entrypoint lives at root (not /app) so the dev source bind-mount can't shadow it.
COPY docker-entrypoint.sh /docker-entrypoint.sh

# Persisted, writable data dir (users.json, lockout.json, recipes/).
# Owned by www-data so the non-root runtime user can write to it.
RUN mkdir -p /app/data/recipes \
 && chown -R www-data:www-data /app/data \
 && chmod +x /docker-entrypoint.sh

# Don't run as root.
USER www-data

EXPOSE 8000

# The router enforces access control (blocks /data, /lib, raw .json/.txt).
# The API key is read from the process environment at runtime via getenv() —
# it is NOT baked into the image. Pass it with `docker run -e ANTHROPIC_API_KEY=...`.
ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000", "router.php"]
