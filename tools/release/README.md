<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: MIT
-->
# Release tools

The release automation that talks to the GitHub API lives here, as small PHP
commands with real unit tests. It used to be bash in `.github/scripts/`; the
parts that parse versions, walk the API and make decisions moved here where they
are far easier to read and to test. The parts that shuffle files around (build,
package, sign) are still bash, because that is what bash is good at.

The commands today:

- **`milestones:update`** - after a release, tidy up the milestones.
- **`milestones:audit`** - check the milestones look right (read-only).
- **`repo:tag`** - tag all the release repositories.
- **`updater:bump`** - update a checked-out updater_server with a release.

```bash
cd tools/release
composer install
GH_TOKEN=<a token with write access to the org> php bin/console milestones:update --help
```

The token is the same `RELEASE_TOKEN` the workflows already use. In CI you never
run these by hand; the release workflows do.

## How a release drives the tooling

You give it a tag (like `v34.0.4`) and it works everything else out. The rules
below are deliberately mechanical so nobody has to remember the bookkeeping.

### Which repos and which branch

From the tag, the workflow picks:

- an **alpha/beta of a brand-new major** (`v35.0.0beta3`) -> the `master` branch
  and `master.json`;
- **anything else** (stable releases and RCs) -> the `stableN` branch and
  `stableN.json`.

The list of repositories to act on is the config file plus `tag-only.json`
(repos that are tagged but not built), merged and de-duplicated.

### Milestones (`milestones:update`)

The golden rule: **there are always two open patch milestones**, so people
always have somewhere to file the next two patch releases.

**A stable release**, say `v33.0.4`:

1. find the `Nextcloud 33.0.4` milestone (for a `.0.0` it may be the short
   `Nextcloud 34` form instead);
2. make sure `Nextcloud 33.0.5` exists - that is where issues go next;
3. move every open issue from 33.0.4 to 33.0.5 (pull requests are left alone);
4. close 33.0.4;
5. make sure `Nextcloud 33.0.6` exists, so two patch milestones stay open.

You can hand it due dates for those two milestones:

```bash
php bin/console milestones:update v33.0.4 stable33.json tag-only.json \
  --next-due 2026-07-02 --upcoming-due 2026-08-27
```

The date is applied whether the milestone is brand new or already there, so
re-running with the same dates is harmless and also fixes any wrong dates.

**The first beta of a new major** (`v34.0.0beta1`) is special: it only creates
the *next* major milestone, `Nextcloud 35`. `Nextcloud 34` already exists from
the previous cycle, so there is nothing else to do.

**Any other pre-release** (alphas, RCs, later betas) does nothing.

Add `--dry-run` to see exactly what it would do without touching anything.

### Audit (`milestones:audit`)

A read-only health check. It figures out the major from the config name, looks
up the latest stable release, and complains if:

- the released milestone is still open,
- the next or upcoming milestone is missing or closed,
- the upcoming milestone has no due date,
- an old patch milestone is still open (an "orphan").

It changes nothing and exits non-zero if it finds problems. If the major has no
stable release yet, it simply skips.

### Tags (`repo:tag`)

For each repository it tags the tip of the release branch (falling back to the
repo's default branch if that branch does not exist). The tag is a plain
lightweight tag created through the API - no cloning, no pushing.

If a tag already exists it is left alone, unless you pass `--force`. The one
exception: **the server repositories are never re-tagged**, even with `--force`,
because a published release tag must never move.

### Updater server (`updater:bump`)

After a release is built and signed, this updates a checked-out
[updater_server](https://github.com/nextcloud-releases/updater_server):

- adds/replaces the entry in `config/releases.json` (internal version,
  signatures, and `deploy` percentage when below 100%);
- for a first pre-release of a new major, registers it in
  `config/major_versions.json`;
- rewrites the Behat feature files for the release type (patch, RC/beta bump,
  first stable, first pre-release).

The deploy percentage follows the same `X.0.0=30 / X.0.1=70 / else=100` rule as
the release plan. The command only edits the working copy; the workflow clones
the repo, fetches the internal version and minimum PHP from `nextcloud/server`,
regenerates `config/config.php` with the repo's own `make` target, and opens the
PR. `config.php` is therefore not produced here (and not covered by the tests).

## How it is built

Three thin layers, so the interesting logic never needs the network to be
tested:

- **domain** - pure functions and value objects (version parsing, milestone
  names, the due-date format, the tag rules);
- **services** - the actual steps, talking to a small `GitHubApi` interface;
- **commands** - Symfony Console wrappers that wire it together and build the
  real GitHub client (`knplabs/github-api`) from `GH_TOKEN`.

Tests run the services against an in-memory fake GitHub that records every change
it is asked to make, so a test can check the exact sequence of
create/close/move/tag calls. Only the thin real client touches the network, and
that path is checked by dry-running the workflows. Each test file says, at the
top, what it covers and why.

## Working on it

```bash
cd tools/release
composer install
composer test                            # all suites
vendor/bin/phpunit --testsuite tagger    # one area
```

CI runs the suites split by area (domain, milestones-update, milestones-audit,
tagger) and reports coverage.
