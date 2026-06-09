# Nextcloud Server Releases

Release artifacts and automation for Nextcloud server. Branches are synced daily from `nextcloud/server`.

## How releases work

A release is driven entirely by its tag (for example `v34.0.4`). Everything else
- which branch, which repositories, which milestones - is derived from it, so
there is no per-release bookkeeping to remember.

The pipeline (`release.yml`) runs these reusable workflows:

1. **Tag** (`release-tag.yml`) - tag every release repository at the tip of its
   release branch.
2. **Changelog** (`release-changelog.yml`) - generate the changelog and attach
   it to the GitHub release.
3. **Build** (`release-build.yml`) - build the archives independently and compare
   them against the release script's output.
4. **Milestones** (`release-milestones.yml`) - tidy milestones across all repos
   (stable releases and first betas only).
5. **Updater** (`release-updater.yml`) - open a PR to the updater server with the
   new release config.

Tagging and milestone management are unit-tested PHP commands in
[`tools/release/`](tools/release/README.md); the build/package/sign steps are
bash in [`.github/scripts/`](.github/scripts/README.md).

### Branch and config selection

- A `.0.0` **alpha/beta of a new major** comes from `master` and uses
  `master.json`.
- **Everything else** (stable releases and RCs) comes from `stableN` and uses
  `stableN.json`.

### Milestone rules in short

Two open patch milestones are always kept. A stable `vX.Y.Z` closes its own
milestone, moves open issues to `X.Y.(Z+1)`, and creates `X.Y.(Z+2)`. The first
beta of a major opens the *next* major milestone (`vN.0.0beta1` creates
`Nextcloud N+1`). Full details and examples are in
[`tools/release/README.md`](tools/release/README.md).

## Release configuration

One JSON file per major version lists all bundled apps:

- `stable32.json`, `stable33.json` - 23 apps
- `stable34.json`, `master.json` - 25 apps (+files_lock, +office)

When a new app is added to the release or an existing one is removed, edit the corresponding JSON file.

`tag-only.json` lists repositories that should be tagged on release but are not part of the build (server, 3rdparty, updater, example-files, documentation).

## Running manually

**Re-tag a release**: Actions > "Tag all repositories" > enter tag (e.g., `v34.0.1`). Check "force" to overwrite existing tags (server repos are never re-tagged), or "dry run" to preview.

**Rebuild a release**: Actions > "Build and compare release" > enter tag. Compares the result against the release script's archives on the same GitHub release.

**Update milestones**: Actions > "Update milestones on release" > enter tag. Use dry-run to preview. Runs automatically for stable releases and first betas. Check "audit only" to verify consistency without making changes.

## Where we are

The old release script still creates releases and uploads to the download server. This workflow runs alongside it to validate that both produce the same result.

Once we are confident the output matches, the release script will be retired and this workflow will take over publishing.

## What comes next

- Enable publishing directly from the workflow (retire the release script)
- Auto-create PRs to the updater server with release configuration
- Add GPG signatures for archives
