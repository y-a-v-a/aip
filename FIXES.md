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
