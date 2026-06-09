# Nextcloud Server Releases

Release artifacts and automation for Nextcloud server. Branches are synced daily from `nextcloud/server`.

## How releases work

A release is fully determined by its tag (`vMAJOR.MINOR.PATCH[suffix]`). The
entry point is the `release.yml` workflow (`workflow_dispatch`, single `tag`
input). It parses the version and derives the release branch, the repository set,
the milestone actions, and the release channel from it; there is no per-release
configuration beyond the tag and the per-major app list. `release.yml` dispatches
five reusable workflows:

1. **Tag** (`release-tag.yml`): creates the tag on every repository in the release
   set at the tip of the resolved branch, through the GitHub git-refs API (no
   clone). Server repositories are never re-tagged. Gates the rest of the
   pipeline.
2. **Changelog** (`release-changelog.yml`): resolves the previous tag, generates
   the changelog for that range, and attaches it to the GitHub release. Depends
   on Tag.
3. **Build** (`release-build.yml`): fetches each component, assembles the
   `nextcloud/` tree, strips dev files, rewrites `version.php`, signs, and
   produces the `.tar.bz2`/`.zip` plus checksums. Also diffs its output against
   the legacy release script. Depends on Tag and Changelog.
4. **Updater** (`release-updater.yml`): fetches the internal version and minimum
   PHP, applies the release to a checkout of the updater server
   (`releases.json`, `major_versions.json`, Behat features), regenerates config
   via `make`, and opens a pull request. Depends on Build.
5. **Milestones** (`release-milestones.yml`): updates and audits milestones across
   the release set. Runs off Tag, in parallel with Changelog and Build, and only
   for stable releases and first betas (`…beta1`); alphas, RCs, and later betas
   are skipped.

`Tag -> Changelog -> Build -> Updater` is a linear dependency chain; Milestones
branches off Tag. A failed job blocks its dependents, so the pipeline cannot
publish a partial release.

Tag, Milestones, and Updater are PHP commands in
[`tools/release/`](tools/release/README.md) with unit, snapshot, and byte-parity
tests. Build, package, and sign are bash in
[`.github/scripts/`](.github/scripts/README.md) with hermetic snapshot and unit
tests. Both suites run on every push to `main` and every pull request.

### Branch and config selection

- A `.0.0` **alpha/beta of a new major** comes from `master` and uses
  `master.json`.
- **Everything else** (stable releases and RCs) comes from `stableN` and uses
  `stableN.json`.

### Milestone rules in short

Two open patch milestones are always kept. A stable `vX.Y.Z` closes its own
milestone, moves open issues to `X.Y.(Z+1)`, and creates `X.Y.(Z+2)`. The first
beta of a major opens the *next* major milestone (`vN.0.0beta1` creates
`Nextcloud N+1`). Due dates for the two kept milestones come from
`release-schedule.json`; a stable release whose milestones are not listed there
fails, so the schedule has to stay current. Full details and examples are in
[`tools/release/README.md`](tools/release/README.md).

## Release configuration

One JSON file per major version lists all bundled apps:

- `stable32.json`, `stable33.json`: 23 apps
- `stable34.json`, `master.json`: 25 apps (+files_lock, +office)

When a new app is added to the release or an existing one is removed, edit the corresponding JSON file.

`tag-only.json` lists repositories that should be tagged on release but are not part of the build (server, 3rdparty, updater, example-files, documentation).

`release-schedule.json` maps milestone titles to due dates (`"Nextcloud 34.0.1": "2026-06-25"`). The milestone step reads it to set due dates for the next and upcoming patch milestones. A stable release whose milestones are missing from this file fails, so add upcoming entries before they are due.

## Running manually

**Re-tag a release**: Actions > "Tag all repositories" > enter tag (e.g., `v34.0.1`). Check "force" to overwrite existing tags (server repos are never re-tagged), or "dry run" to preview.

**Rebuild a release**: Actions > "Build and compare release" > enter tag. Compares the result against the release script's archives on the same GitHub release.

**Update milestones**: Actions > "Update milestones on release" > enter tag. Use dry-run to preview. Runs automatically for stable releases and first betas. Check "audit only" to verify consistency without making changes.

## Current state and target

Target: the pipeline owns the full release end to end from a single tag,
including publishing to the download server, and the legacy release script is
removed.

Current state: the workflow runs in parallel with the legacy release script
rather than replacing it. The script remains the source of the published
artifacts and the download-server upload; the workflow rebuilds the same release
and diffs its output byte for byte to establish parity. Publishing from the
workflow is not yet enabled.

The cutover is staged because a release spans roughly 30 repositories, code
signing, and the update channel consumed by every server. The migration moves
logic out of untested shell into tested code, keeps test artifacts diffable, and
runs the suites on every change, so each piece is verified before publishing is
handed over.

### Remaining before the legacy script can be retired

- **Publishing from the workflow.** Build and signing are implemented; upload to
  the download server is not. This is the final cutover step.
- **Build parity.** Continue diffing workflow output against the legacy script
  across all release shapes (stable, RC, first beta, new major).
- **Workflow-glue hardening.** The shell in the workflow steps (version-file
  fetch and parse, clone, `make`, PR creation) is untested and has known issues,
  including a `git push --force` without a divergence check and a token passed
  through a git URL.
- **Changelog generator tests.** The PHP changelog tool has no unit tests.
- **GPG signatures.** Published archives are not GPG-signed for independent
  verification.
