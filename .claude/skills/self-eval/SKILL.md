---
name: self-eval
description: >
  Self-evaluation and validation playbook for the AIP recipe app in this repo.
  Use after implementing ANY feature request or fix here: it defines how to lint,
  build/run in Docker, smoke-test the app end-to-end (auth + endpoints), validate
  the specific change against its acceptance criteria, and — if validation fails —
  diagnose, fix, re-test, and document the fix. Ends with a step to improve this
  skill itself when its steps proved insufficient or out of date.
---

# Self-evaluation & validation loop

The intended working model for this repo: **a feature request comes in → implement
it → test it → validate it against the request → if it fails, find a fix, apply it,
re-validate, and document how the fix was made.** Run this skill as the "test →
validate → fix → document" half of that loop, after you've made the change and
before you report it done. Never report a change as working on inspection alone —
prove it with a run.

## Operating context (what a fresh session needs to know)

- **App:** plain PHP (no framework), served by the built-in server via `router.php`
  (`php -S`). `router.php` is also the security gate — only `php -S` uses it; on a
  shared Apache host the `.htaccess` files enforce protection instead.
- **Run/validate it with Docker.** There is no PHP on the host. Use the image for
  both linting and running. Requires Docker Desktop running and `ANTHROPIC_API_KEY`
  in the environment.
- **Dev login (auto-seeded):** `+31644444444` / PIN `123456`. Created on container
  start by `docker-entrypoint.sh` when `DEV_SEED=1` (set in `docker-compose.yml`).
- **Live reload:** the source is bind-mounted, so `.php`/`.txt` edits show up after
  a ~1–3s macOS file-share delay — **no rebuild**. Only `Dockerfile` changes need
  `docker compose up --build`.
- **Cost awareness:** each recipe generation is a real, billed Claude API call (a
  few cents). Keep test generations to the minimum that proves the change. Every
  successful call is logged to `data/api_log.jsonl` (model, tokens, est. USD cost).
- **File map:** `config.php` (settings, pricing, helpers), `lib/claude.php` (the
  single API call), `lib/store.php` (saved recipes), `lib/view.php` (HTML/CSS),
  `auth.php` (phone+PIN, CSRF, lockout), `index.php` / `login.php` / `recipes.php`
  / `ingredients.php` (pages), `system_prompt.txt` (model input), `tools/` (CLI),
  `data/` (credentials, recipes, logs, live `ingredients.txt` — web-blocked).
- **Pantry:** `ingredients.txt` at the repo root is the **shipped default**; the
  **live, editable** list is `data/ingredients.txt` (seeded from the default on
  first use, managed via the `ingredients.php` page).

## The loop

1. **Restate acceptance criteria.** In one or two lines, write what the request
   asked for and the **observable** check that proves it (a returned status, a
   substring in the page, a value in `data/`, a log line, absence of a forbidden
   token). If you can't name an observable check, you can't validate — define one.

2. **Lint changed PHP.** `php -l` every file you touched, via the image. If the
   stack is up: `docker compose exec -T aip php -l /app/<file>`. If not:
   `docker compose run --rm aip php -l /app/<file>`. Note: `docker compose` names
   the built image `aip-aip` (project-prefixed), so a bare `docker run … aip …`
   won't find it — use the `compose` forms above. Fix syntax errors first.

3. **Build & run.** If you changed the `Dockerfile`/compose: `docker compose up -d
   --build`. Otherwise `docker compose up -d` is enough (edits are live; give it
   ~2–3s). The dev user is auto-seeded.

4. **Smoke-test end-to-end.** Run the harness below: it logs in as the dev user
   (CSRF + cookies) and confirms the app responds. This catches regressions in
   auth, routing, and rendering regardless of the specific feature.

5. **Validate the specific change.** Assert the observable check from step 1
   against a real run. Examples of checks used in this repo:
   - units/metric → grep the generated recipe for `°C`, `g`, `ml`; assert no
     imperial.
   - security gate → `/data/users.json`, `/data/api_key`, `/ingredients.txt`,
     `/lib/claude.php` must return 404/403, not contents.
   - variety → generate a few and confirm the main ingredient spreads.
   - cost log → confirm a line was appended to `data/api_log.jsonl` with sane
     token counts.
   - loading UI → confirm the page contains the spinner markup / submit handler.

6. **Judge.** All checks pass → report concisely **with the evidence** (the actual
   output, not "it works"). Note deploy implications (rebuild vs live; re-upload to
   shared host). Then go to **Self-improvement**. Any check fails → step 7.

7. **Diagnose & fix.** Reproduce the failure minimally. Inspect: container logs
   (`docker compose logs aip`), exec in (`docker compose exec aip sh`), compare the
   container's file view vs HTTP, check `data/api_log.jsonl`. Form a hypothesis,
   apply the smallest fix, then **return to step 2**. Don't pile on speculative
   changes — one fix, re-validate.

8. **Document the fix.** When a fix was non-obvious (a real bug, a Docker/macOS
   gotcha, a wrong assumption), append an entry to `FIXES.md` at the repo root
   (create it if missing) so the knowledge isn't lost:

   ```markdown
   ## YYYY-MM-DD — <short title>
   - Symptom: what was observed / which check failed.
   - Root cause: why it happened.
   - Fix: what changed (files), and why that resolves it.
   - Verified: the command/check that now passes.
   ```

9. **Teardown** (optional). `docker compose down` to stop, or `down -v` to also
   wipe the volume (the dev user re-seeds on next `up`).

## Reusable smoke-test harness

Run after `docker compose up -d`. Adjust the feature-specific asserts at the bottom.
Run with the sandbox disabled if your tooling blocks the Docker socket / network.

```sh
cd "$(git rev-parse --show-toplevel 2>/dev/null || echo .)"
BASE=http://localhost:8000
cj=$(mktemp)
# login as the auto-seeded dev user (grab CSRF, keep the session cookie)
csrf=$(curl -s -c "$cj" "$BASE/login.php" | grep -o '[0-9a-f]\{32\}' | head -1)
code=$(curl -s -b "$cj" -c "$cj" -o /dev/null -w "%{http_code}" \
  --data-urlencode "phone=+31644444444" --data-urlencode "pin=123456" \
  --data-urlencode "csrf=$csrf" "$BASE/login.php")
echo "login -> $code (302 = ok)"

# security gate: these must NOT return contents
for p in /data/users.json /data/api_key /data/ingredients.txt /ingredients.txt /lib/claude.php; do
  echo "  $p -> $(curl -s -o /dev/null -w '%{http_code}' "$BASE$p")"
done

# generate one recipe (real API call — keep it minimal)
curl -s -b "$cj" -c "$cj" --data-urlencode "meal=dinner" \
  --data-urlencode "csrf=$csrf" "$BASE/" > /tmp/page.html
# NOTE: pages emit class="err" (double quotes) — match that exactly.
grep -q 'class="err"' /tmp/page.html && echo "ERROR on generate" || echo "generate ok"

# --- feature-specific asserts go here, e.g.: ---
# docker compose exec -T aip cat /app/data/api_log.jsonl | tail -1
rm -f "$cj"
```

## Self-improvement (run this at the end)

After finishing, evaluate **this skill** the same way you evaluated the change. Ask:

- Did a step send me the wrong way, or was a needed step missing?
- Did the repo change in a way this skill should now mention (new file, new command,
  new dev login, changed run command, a gotcha I hit and had to rediscover)?
- Was a documented command stale (e.g. a path, the model id, the dev phone/PIN)?

**If yes to any**, update this `SKILL.md` to fix the gap — edit the relevant step,
correct the command, or add the gotcha — and append a line to the changelog below.
Keep edits minimal and grounded in what actually happened this session; do not
speculatively expand the skill. **If no**, change nothing and say so.

Whenever you update this skill, state plainly to the user what you changed and why.

## Changelog

- 2026-06-22 — Initial version. Encodes the build/lint/smoke-test/validate/fix loop,
  the Docker dev harness, the auto-seeded dev login, and the FIXES.md convention.
- 2026-06-22 — Web-managed pantry shipped: added `ingredients.php` + live
  `data/ingredients.txt` to the file map and the gate checks. Fixed the harness's
  generate-error check (was `class='err'`, pages emit `class="err"` — a false
  negative that hid real errors). See FIXES.md.
- 2026-06-27 — Auth hardening pass (6-digit PIN floor + HTTPS-conditional secure
  cookie). Corrected the lint command: the compose image is `aip-aip`, not `aip`,
  so steer to `docker compose exec/run`. Bumped the documented dev login PIN to
  `123456` (now the enforced minimum). See FIXES.md.
