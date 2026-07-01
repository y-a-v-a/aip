# Fixes log

A running record of **non-obvious** fixes — real bugs, environment/Docker/macOS
gotchas, or wrong assumptions that cost time to diagnose. The goal is that nobody
(human or a future session) has to rediscover the same thing.

Maintained as part of the `self-eval` skill (`.claude/skills/self-eval/SKILL.md`,
step 8): when a fix during the test → validate loop was non-obvious, add an entry
here. Skip trivial or self-evident changes.

## Entry format

```markdown
## YYYY-MM-DD — <short title>
- Symptom: what was observed / which check failed.
- Root cause: why it happened.
- Fix: what changed (files), and why that resolves it.
- Verified: the command/check that now passes.
```

Newest entries on top.

---

## 2026-06-27 — Auth hardening: HTTPS-conditional secure cookie + 6-digit PIN floor
- Symptom: two silent-breakage traps while hardening login. (1) Setting the session
  cookie `'secure' => true` outright would break local dev — over `http://localhost`
  the cookie is never sent, so login just fails to stick with no visible error.
  (2) Raising the PIN floor to 6 would break the Docker dev auto-seed (`DEV_PIN=1234`),
  and `docker-entrypoint.sh` swallows that failure with `|| echo`, so the dev user
  would silently stop existing.
- Root cause: `secure` cookies only travel over HTTPS, but dev runs over plain http;
  and the dev-seed PIN is coupled to the `make_user.php` minimum.
- Fix: `auth.php` derives `'secure'` from a runtime HTTPS check
  (`$_SERVER['HTTPS']` or `X-Forwarded-Proto: https`) instead of a constant — prod
  (`https://aip.bij-ons-aan-tafel.nl`) hardens automatically, dev still works.
  `tools/make_user.php` floor raised to 6; `DEV_PIN` bumped to `123456` in
  `docker-compose.yml` + `docker-entrypoint.sh`, and the dev-login PIN updated in
  `README.md` and the self-eval skill so seed and docs stay valid.
- Verified: over http `Set-Cookie` has no `secure`; with `X-Forwarded-Proto: https`
  it gains `secure`. Dev login `123456` → 302; old `1234` → 200 (rejected).
  `make_user.php` rejects 5-char (exit 1) / accepts 6-char. `php -l` clean; gate all 404.

## 2026-06-22 — self-eval harness silently passed on generate errors
- Symptom: while adding the ingredients page, the smoke harness reported
  "generate ok" even on requests that rendered an error block.
- Root cause: the harness checked `grep -q "class='err'"` (single quotes), but
  every page emits `class="err"` (double quotes), so the check never matched —
  a false negative that would mask real generation failures.
- Fix: `.claude/skills/self-eval/SKILL.md` — changed the check to
  `grep -q 'class="err"'` and added a note to match the markup exactly.
- Verified: re-ran the harness against a deliberately failing request; it now
  prints "ERROR on generate". Normal runs still print "generate ok".
