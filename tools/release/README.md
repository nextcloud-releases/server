<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: MIT
-->
# Release tools (PHP)

Unit-tested PHP for the release automation, migrated incrementally from the
bash scripts in `.github/scripts/`. The logic-heavy, API-driven scripts move
here where they get real types, return values and PHPUnit coverage; the
filesystem/packaging scripts stay in bash.

## Layout

- `src/` — domain logic, pure (no I/O), mockable services, and (later) commands.
- `tests/` — PHPUnit.

This first step ships the milestone **domain** (no I/O):

| class | purpose |
|---|---|
| `Version` | parse a release tag → major/minor/patch, isPrerelease, isFirstBeta |
| `MilestonePlan` | current/next/upcoming milestone names; first beta → next major (N+1) |
| `DueDate` | validate `YYYY-MM-DD`, convert to the ISO form the API wants |
| `RepoList` | merge the config + tag-only lists (sorted, unique) |
| `AuditExpectation` | expected milestone state + orphan rule for the audit |

Next steps wire these behind a GitHub client wrapper and Symfony Console
commands (`milestones:update`, `milestones:audit`), then retire the
corresponding bash scripts once at parity.

## Develop

```bash
cd tools/release
composer install
composer test
```
