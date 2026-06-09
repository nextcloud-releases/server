<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: MIT
-->
# Release tools (PHP)

Unit-tested PHP for the GitHub-API parts of the release automation, migrated
from the bash scripts in `.github/scripts/`. The logic-heavy, API-driven steps
(milestones, tagging) live here where they get real types, return values and
PHPUnit coverage. The filesystem/packaging scripts stay in bash.

## Commands

```bash
cd tools/release && composer install
GH_TOKEN=... php bin/console <command>
```

| command | replaces | what |
|---|---|---|
| `milestones:update <tag> <config.json> <tag-only.json> [--dry-run] [--next-due Y-M-D] [--upcoming-due Y-M-D]` | `update-milestones.sh` | close/create milestones, move issues |
| `milestones:audit <config.json> <tag-only.json>` | `audit-milestones.sh` | report milestone inconsistencies (read-only) |
| `repo:tag <tag> <branch> <config.json> <tag-only.json> [--force] [--dry-run]` | `tag-repo.sh` | tag all release repos at a branch |

`GH_TOKEN` (or `GITHUB_TOKEN`) must be a token with write access to the org
repos - the same `RELEASE_TOKEN` the workflows already pass.

## Release auto-logic

This is the behaviour the workflows encode. It is intentionally rule-based so a
human rarely has to think about milestone/tag bookkeeping.

### Config & branch selection (from the tag)

- **Alpha/beta of a new major** (`vN.0.0alpha*` / `vN.0.0beta*`) -> branch
  `master`, config `master.json`.
- **Everything else** (stable `vN.M.P`, and RCs) -> branch `stableN`, config
  `stableN.json`.
- The repo list is the **union** of the config file (objects with `.repo`) and
  `tag-only.json` (plain strings), sorted and de-duplicated.

### Milestones (`milestones:update`)

The invariant: **two patch milestones stay open** at all times.

- **Stable release `vX.Y.Z`** (not a pre-release):
  1. find the released milestone - `Nextcloud X.Y.Z`, or the short `Nextcloud X`
     for an initial `X.0.0`;
  2. ensure `Nextcloud X.Y.(Z+1)` exists (create if missing) - the **next** patch;
  3. **move all open issues** from the released milestone to the next one
     (gathered up front, so none are skipped; pull requests are left alone);
  4. **close** the released milestone;
  5. ensure `Nextcloud X.Y.(Z+2)` exists (create if missing) - the **upcoming** patch.
  - `--next-due` / `--upcoming-due` set the due date on the next / upcoming
    milestone respectively. The date is applied whether the milestone is created
    now **or already exists**, so re-running fixes stale/missing dates and is
    idempotent.
- **First beta `vN.0.0beta1`**: only **create `Nextcloud N+1`** (the next major)
  where missing. `Nextcloud N` already exists from the previous cycle. No close,
  no move, no due date.
- **Any other pre-release** (alpha/rc, later betas): **no-op**.
- `--dry-run` reads state and logs "Would ..." but changes nothing.

### Audit (`milestones:audit`)

Read-only. Derives the major from the config name (`stableN` -> N; `master` ->
highest released major + 1), finds the latest stable release of that major, and
flags: released milestone still open, missing/closed next or upcoming, an
upcoming milestone without a due date, and orphaned patch milestones (open but at
or below the released patch). Exits non-zero if anything is flagged. If no stable
release exists yet for the major, it skips.

### Tags (`repo:tag`)

- Per repo, resolve the tag target: prefer the release `branch` (stableN/master),
  fall back to the repo's **default branch**; fail that repo if neither exists.
- Create the tag as a **lightweight ref** via the git-refs API (same result as
  the old `gh release create`, no clone/push).
- If the tag already exists: **skip**, unless `--force` (then recreate) - but the
  **server repos are immutable**: `nextcloud/server` and
  `nextcloud-releases/server` are never re-tagged even with `--force`.
- `--dry-run` reports what it would do without writing.

## Architecture & testing

Three layers, so the logic is testable without touching GitHub:

- **Domain** (`src/Version.php`, `MilestonePlan`, `DueDate`, `RepoList`,
  `AuditExpectation`, `TagPolicy`) - pure, no I/O.
- **Service** (`MilestoneUpdater`, `MilestoneAuditor`, `RepoTagger`) - the
  orchestration, talking to a `GitHub\GitHubApi` interface.
- **Command** (`src/Command/`) - thin Symfony Console glue; builds the real
  `GitHub\KnpGitHubApi` (knplabs/github-api) from `GH_TOKEN`.

Tests run the services against `GitHub\FakeGitHubApi` - an in-memory GitHub that
records every mutation to a **journal**, so a test asserts the exact sequence of
create/close/move/setdue/tag calls (the PHP equivalent of the old
`fake-gh.sh` + journal). Only the thin `KnpGitHubApi` adapter touches the
network; it's verified by dry-running the workflows. Each test file documents
what it covers and why in its class docblock.

## Develop

```bash
cd tools/release
composer install
composer test
```
