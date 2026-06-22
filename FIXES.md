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

_No fixes recorded yet._
