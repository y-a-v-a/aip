# AIP Recipe Generator

A tiny PHP web app that generates lunch / dinner / dessert recipes using **only**
the whole-food ingredients in `ingredients.txt` (an AIP-style elimination diet),
via the Anthropic Claude API. Recipes are saved to disk and browsable. Login is a
file-based phone-number allow-list with per-user PINs.

KISS by design: no framework, no Composer, no database. Just PHP + cURL.

## Requirements

- PHP 8.0+ with the `curl` extension (bundled in standard PHP builds)
- `ANTHROPIC_API_KEY` set in the environment

## Setup

1. **Add at least one allowed user** (phone + PIN):

   ```sh
   php tools/make_user.php +15551234567 123456
   ```

   Re-run to change a PIN or add more people. PINs are stored only as bcrypt
   hashes in `data/users.json`.

2. **Make sure your API key is in the environment**, e.g.:

   ```sh
   export ANTHROPIC_API_KEY="sk-ant-..."
   ```

3. **Run it** (the router enforces access control on the built-in server):

   ```sh
   php -S localhost:8000 router.php
   ```

   Open <http://localhost:8000>, log in with the phone + PIN you created, pick a
   meal, and generate.

## Run with Docker (local dev)

The API key is injected at **runtime** (via env var) — it is never baked into the
image or committed.

```sh
# 1. Build
docker compose build

# 2. (optional) add more users — a dev login +31644444444 / 123456 is auto-seeded
#    on startup via DEV_SEED in docker-compose.yml (dev only; never on the host)
docker compose run --rm aip php tools/make_user.php +15551234567 123456

# 3. Run — pass the key from your shell (or put it in a .env file beside docker-compose.yml)
ANTHROPIC_API_KEY="sk-ant-..." docker compose up
```

Open <http://localhost:8000>. `users.json` and saved recipes live in the named
volume `aip-data`, so they survive restarts and rebuilds.

### Editing code & reloading

`docker compose up` alone does **not** rebuild — it starts the last-built image,
so stale code is expected. But the compose file bind-mounts your source into the
container, so:

- **Editing `.php` / `.txt` / `config` / prompt:** just save and refresh. The
  change is picked up live (allow ~1–3s for macOS Docker file-sharing to
  propagate it into the container). No rebuild, no restart.
- **Changing the `Dockerfile`** (base image, packages): `docker compose up --build`.

Plain `docker` (no compose) works too:

```sh
docker build -t aip .
docker run --rm -p 8000:8000 \
  -e ANTHROPIC_API_KEY="sk-ant-..." \
  -v aip-data:/app/data \
  aip
# add a user against the same volume:
docker run --rm -v aip-data:/app/data aip php tools/make_user.php +15551234567 123456
```

How the key reaches the app: `lib/claude.php` calls `getenv('ANTHROPIC_API_KEY')`,
which reads the container's process environment set by `-e` / compose `environment`.
Don't use `ENV ANTHROPIC_API_KEY=...` in the Dockerfile or a build arg — that would
bake it into the image.

> The container runs PHP's built-in server (KISS). Fine for personal/LAN use;
> put a real web server or TLS terminator in front if you expose it to the internet.

## Deploying to a shared PHP host

The Docker setup above is just for local dev. On a shared **Apache** host there's
no `php -S` and `router.php` is never used — requests hit files directly, so
protection comes from the bundled `.htaccess` files instead.

1. **Upload everything** to your (sub)domain's document root (e.g. `public_html/`).
   Prefer giving it its own subdomain/docroot so the app sits at the root URL:
   the internal links are absolute (`/`, `/login.php`), so a subfolder install
   would need those paths adjusted.

2. **Make `data/` writable** by the web account — typically `chmod 775 data` (it
   holds `users.json`, `lockout.json`, and `recipes/`).

3. **Provide the API key** (any one — none bakes it into code):
   - Create a file **`data/api_key`** containing just the key. It's blocked from
     the web by `data/.htaccess`. *Recommended — simplest and portable.*
   - …or set `ANTHROPIC_API_KEY` as an account env var (cPanel "Environment
     variables"), or `SetEnv ANTHROPIC_API_KEY ...` in the root `.htaccess`
     (Apache never serves `.htaccess` itself).

4. **Create a login** — with SSH: `php tools/make_user.php +15551234567 123456`.
   **No SSH?** Run that locally (or in Docker), then upload the generated
   `data/users.json`.

5. **Verify protection** after deploy: requesting `/data/users.json`,
   `/data/api_key`, `/ingredients.txt`, or `/lib/claude.php` should return
   403/404 — *not* file contents. If anything downloads, your host has
   `AllowOverride None` (htaccess disabled): ask them to enable it, or relocate
   `data/` above the document root.

## How it works

- `index.php` — the app: pick lunch/dinner/dessert, optional note, generate.
- `lib/claude.php` — the single Claude Messages API call (raw cURL, no SDK).
- `system_prompt.txt` — **the prompt; edit this to change behavior.** The pantry
  is appended to it at request time, so the model is restricted to your ingredients.
- `ingredients.php` — manage the pantry from the browser (login required).
- `ingredients.txt` — the **shipped default** pantry (one item per line). On first
  use it is copied to `data/ingredients.txt`, which is the live, editable list.
- `recipes.php` — browse and re-read saved recipes.
- `auth.php` — sessions, CSRF, phone+PIN check, failed-attempt lockout.
- `data/` — `users.json` (hashed PINs), `lockout.json`, `ingredients.txt` (live
  pantry), `api_log.jsonl`, and `recipes/*.json`.

## Tweaking

- **Prompt / dietary rules:** edit `system_prompt.txt`.
- **Ingredients:** use the **Ingredients** page in the app (writes
  `data/ingredients.txt`). Editing the repo's `ingredients.txt` only changes the
  default used to seed a fresh `data/` — it won't affect a deployment that already
  has a live list. To reset to the default, delete `data/ingredients.txt`.
- **Model / cost:** edit `MODEL` in `config.php`. Default is `claude-opus-4-8`;
  `claude-sonnet-4-6` or `claude-haiku-4-5` are cheaper and plenty for recipes.

## Security notes (read before exposing this publicly)

- The `router.php` gate (and `.htaccess` for Apache) blocks direct HTTP access to
  `data/`, `lib/`, `tools/`, and raw `.json`/`.txt` files. If you serve this any
  other way, replicate that — never let `data/users.json` be downloadable.
- Auth is "secure enough" for a small private app: bcrypt PINs, CSRF tokens,
  session regeneration on login, and a 5-strikes / 5-minute lockout per phone.
  It is **not** phone-ownership verification (that needs SMS OTP).
- The file-based lockout/session state has minor race conditions under heavy
  concurrent load — fine for personal use, not for high traffic.
- **Serve over HTTPS** if it leaves localhost, and then uncomment the
  `'secure' => true` cookie flag in `auth.php`.

## Want the official SDK instead of raw cURL?

```sh
composer require anthropic-ai/sdk
```

Then replace the body of `generate_recipe()` in `lib/claude.php` with a
`$client->messages->create(...)` call. Everything else stays the same.
