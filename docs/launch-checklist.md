# Launch Checklist - ledgerkit

Work top to bottom before tagging `0.1.0` and publishing to Packagist. Nothing is checked until
verified against real Postgres and MySQL, not assumed from a green unit run.

## Package hygiene

- [ ] `composer.json` complete: name `thealirazadev/ledgerkit`, description, license (MIT),
      PHP `8.3.*`, `ext-bcmath`, pinned `illuminate/*` versions, autoload maps, `provide`
      section for the service provider auto-discovery.
- [ ] `composer.lock` committed and matching the pins; `composer validate --strict` clean.
- [ ] No secrets anywhere in the repo; `.env` git-ignored; `.env.example` carries dummy DSNs
      for both test engines only.
- [ ] LICENSE file present; CHANGELOG starts at `0.1.0` with the actual shipped surface.

## Correctness gates

- [ ] Full suite green on Postgres 15 and MySQL 8 in CI (both matrix jobs), `vendor/bin/pint
      --test` clean.
- [ ] Raw-SQL bypass checks re-run by hand on Postgres: unbalanced insert aborts at commit;
      UPDATE and DELETE on entries/postings rejected on both engines; wrong-currency posting
      insert rejected by the composite FK.
- [ ] Concurrent idempotency race demonstrated by hand (two psql/mysql sessions): one entry
      row, second caller gets it as a replay.
- [ ] `ledger:verify` exits 0 on the demo dataset and exits 1 with precise output when a
      snapshot row is planted wrong.
- [ ] Snapshot + tail equals full recomputation on a seeded 10k-posting ledger; `EXPLAIN`
      confirms the `(account_id, id)` and `(account_id, posted_at)` indexes are used.

## Operator documentation

- [ ] README quickstart executed verbatim on a fresh Laravel 11 app against Postgres: install,
      migrate, post, balance - no undocumented steps.
- [ ] MySQL enforcement difference (no commit-time balance trigger) stated plainly in the
      README, not only in `docs/`.
- [ ] Safety-window assumption documented where operators will see it: config comment, README,
      and the `SnapshotBuilder` doc block all state that the window must exceed the longest
      write transaction.
- [ ] `ledger:demo` run recorded on both engines with output matching `docs/design.md`.

## Release

- [ ] Migrations run and roll back cleanly on both engines from a fresh database, twice
      (idempotent re-run of the pair).
- [ ] Version tag `v0.1.0` created after CI is green on the tagged commit; Packagist webhook
      confirmed picking it up.
- [ ] Install test from Packagist into a throwaway app (`composer require
      thealirazadev/ledgerkit`) migrates and posts successfully.
- [ ] `docs/memory.md` updated with the release entry and any deviations discovered above.
