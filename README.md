# AIP Recipe Generator

A tiny PHP web app that generates lunch / dinner / dessert recipes using **only**
the whole-food ingredients in `ingredients.txt` (an AIP-style elimination diet),
via the OpenRouter API (Claude models by default — any OpenRouter model works).
Recipes are saved to disk and browsable. Access is invite-only: an admin adds
people (phone number or email) on the **Admin** page and sends them a one-time
login link; a PIN is optional. Users live in a JSON file — no database.

KISS by design: no framework, no Composer, no database. Just PHP + cURL.

**Documentation:** open [`docs/index.html`](docs/index.html) in a browser — a user guide,
an admin guide (inviting people) and a developer guide, with diagrams.

## Requirements

- PHP 8.0+ with the `curl` extension (bundled in standard PHP builds)
- `OPENROUTER_API_KEY` set in the environment (get one at <https://openrouter.ai/keys>)

## Setup

1. **Create your admin account** (phone or email + PIN):

   ```sh
   php tools/make_user.php +15551234567 123456 --admin
   ```

   Re-run to change a PIN. PINs are stored only as bcrypt hashes in
   `data/users.json`. Everyone else you add from the browser — see
   [Inviting people](#inviting-people-admin-page).

2. **Make sure your API key is in the environment**, e.g.:

   ```sh
   export OPENROUTER_API_KEY="sk-or-..."
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
OPENROUTER_API_KEY="sk-or-..." docker compose up
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
  -e OPENROUTER_API_KEY="sk-or-..." \
  -v aip-data:/app/data \
  aip
# add a user against the same volume:
docker run --rm -v aip-data:/app/data aip php tools/make_user.php +15551234567 123456
```

How the key reaches the app: `lib/openrouter.php` calls `getenv('OPENROUTER_API_KEY')`,
which reads the container's process environment set by `-e` / compose `environment`.
Don't use `ENV OPENROUTER_API_KEY=...` in the Dockerfile or a build arg — that would
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
   - …or set `OPENROUTER_API_KEY` as an account env var (cPanel "Environment
     variables"), or `SetEnv OPENROUTER_API_KEY ...` in the root `.htaccess`
     (Apache never serves `.htaccess` itself).

4. **Create your admin login** — with SSH, in the deploy directory:
   `php tools/make_user.php +15551234567 123456 --admin` (on an existing account
   this keeps its data and just sets the PIN + admin flag).
   **No SSH / no local PHP?** Run `./tools/adduser.sh +15551234567 123456 --admin`
   (a thin Docker wrapper around `make_user.php`), then upload the generated
   `data/users.json`. After that, add everyone else from the Admin page.

5. **Verify protection** after deploy: requesting `/data/users.json`,
   `/data/api_key`, `/ingredients.txt`, or `/lib/openrouter.php` should return
   403/404 — *not* file contents. If anything downloads, your host has
   `AllowOverride None` (htaccess disabled): ask them to enable it, or relocate
   `data/` above the document root.

## Inviting people (admin page)

Log in with your admin account and open **Admin** in the menu (`/admin.php`).
It asks for your PIN once more (valid for 30 minutes of activity). Anyone who is
not an admin gets a 404 there, and admin rights can only be granted from the
command line (`make_user.php … --admin`), never from the web.

1. Enter a **phone number** (international format, `+31612345678`) or an
   **email address**, optionally a name and a PIN, and press *Add & create login link*.
2. You get a personal link with *Copy*, *Send via WhatsApp* (phones) or
   *Send by email* (emails) buttons — they open your own WhatsApp / mail app with
   a prefilled message; the server sends nothing itself.
3. The person opens the link and taps **Sign in**. They then stay signed in on
   that device for a year (an httpOnly "device" cookie).

Links work **once** and expire after 7 days. Opening a link only shows the
*Sign in* button — the link is used up by tapping it, so WhatsApp's link previews
can't burn it. Lost phone / new device? Press **New login link** (the old link
stops working). **Sign out everywhere** ends all their sessions and devices;
**Remove** revokes access immediately. People given a PIN can also log in with
phone-or-email + PIN on the login page.

## Admin scripts (no local PHP — just Docker)

Each wraps a PHP CLI tool in a throwaway container and acts on `./data/`:

- `tools/adduser.sh <phone|email> <pin> [--admin]` — add or update a login (PIN
  min 6 chars); `--admin` grants access to the Admin page.
- `tools/listusers.sh` — list logins (id, date, flags; never PIN hashes).
- `tools/deluser.sh <phone|email>` — revoke a login.
- `tools/backup.sh` — snapshot the `aip_aip-data` volume to `./backups/`;
  `tools/backup.sh restore <file>` puts one back. Backups hold PIN hashes — keep
  them private (they're `.gitignore`d). For the shared host, download `data/` via
  your host's file panel instead.

These edit your *local* `data/users.json`; for the shared host, prefer the Admin
page (or `php tools/…` over SSH) — uploading a local copy overwrites users added
from the web.

## How it works

- `index.php` — the app: pick lunch/dinner/dessert, optional note, generate.
- `lib/openrouter.php` — the single OpenRouter chat-completions call (raw cURL, no SDK).
- `system_prompt.txt` — **the prompt; edit this to change behavior.** The pantry
  is appended to it at request time, so the model is restricted to your ingredients.
- `ingredients.php` — manage the pantry from the browser (login required).
- `ingredients.txt` — the **shipped default** pantry (one item per line). On first
  use it is copied to `data/ingredients.txt`, which is the live, editable list.
- `recipes.php` — browse and re-read saved recipes.
- `auth.php` — sessions, CSRF, PIN check, login links, device cookies, lockout,
  admin gate.
- `admin.php` — the admin page (invite / new link / sign out / remove).
- `lib/users.php` — the `data/users.json` store (locked read-modify-write),
  identifier normalization, login-link and device tokens (stored as sha256 only).
- `data/` — `users.json` (hashed PINs + token hashes), `lockout.json`, `ingredients.txt` (live
  pantry), `api_log.jsonl`, and `recipes/*.json`.

## Tweaking

- **Prompt / dietary rules:** edit `system_prompt.txt`.
- **Ingredients:** use the **Ingredients** page in the app (writes
  `data/ingredients.txt`). Editing the repo's `ingredients.txt` only changes the
  default used to seed a fresh `data/` — it won't affect a deployment that already
  has a live list. To reset to the default, delete `data/ingredients.txt`.
- **Model / cost:** edit `MODEL` in `config.php`. Default is
  `anthropic/claude-sonnet-4.6`; `anthropic/claude-haiku-4.5` is cheaper and
  plenty for recipes, `anthropic/claude-opus-4.8` is top quality. Any model ID
  from <https://openrouter.ai/models> works. Per-call cost is logged to
  `data/api_log.jsonl` straight from OpenRouter's usage accounting.

## End-to-end tests (Playwright)

`e2e/` holds a small Playwright suite (Node). It builds the app from the repo
`Dockerfile` and runs it next to a mock OpenRouter server
(`e2e/mock-openrouter/server.mjs`), so tests never call the real, paid API.
The app is pointed at the mock with `OPENROUTER_BASE_URL`, an env override that
should stay unset in production.

```bash
cd e2e
npm install
npx playwright install chromium
npm test                      # builds + starts the stack, runs, tears it down
E2E_KEEP_STACK=1 npm test     # leave containers up afterwards for debugging
```

The stack listens on `localhost:8081` (app) and `localhost:4010` (mock); change
them with `E2E_APP_PORT` / `E2E_MOCK_PORT`. Tests queue canned responses on the
mock (`POST /__mock/enqueue`) and inspect what the app sent
(`GET /__mock/requests`). Every run starts with a fresh `data/` directory and a
seeded test user, who is an admin (`tests/admin.spec.js` covers inviting,
login links, device cookies, sign-out/removal and the admin gate).

## Security notes (read before exposing this publicly)

- The `router.php` gate (and `.htaccess` for Apache) blocks direct HTTP access to
  `data/`, `lib/`, `tools/`, and raw `.json`/`.txt` files. If you serve this any
  other way, replicate that — never let `data/users.json` be downloadable.
- Auth is "secure enough" for a small private app: bcrypt PINs, CSRF tokens,
  session regeneration on login, and a 5-strikes / 5-minute lockout per account.
  Login links are 192-bit random, single-use, 7-day tokens; only their sha256
  is stored, same for device cookies. Anyone holding an unused link can sign in
  as that person — send it only to them. It is **not** phone/email-ownership
  verification: you vouch for who you send the link to.
- The file-based lockout/session state has minor race conditions under heavy
  concurrent load — fine for personal use, not for high traffic.
- **HTTPS:** the session cookie sets `Secure` automatically on HTTPS requests
  (`auth.php`), and the root `.htaccess` redirects http→https + sends HSTS on
  Apache. Deploy that redirect only once TLS is actually live on the domain, or
  http visitors get bounced to a dead `https://` URL.

## Want an SDK instead of raw cURL?

OpenRouter is OpenAI-compatible, so any OpenAI PHP client works — point it at
`https://openrouter.ai/api/v1` and replace the body of `generate_recipe()` in
`lib/openrouter.php` with a chat-completions call. Everything else stays the same.
