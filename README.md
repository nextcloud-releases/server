# Nextcloud Server Releases

Release artifacts and automation for Nextcloud server. Branches are synced daily from `nextcloud/server`.

## How releases work

You cut a release by pushing one button and typing one tag. Go to Actions >
"Release pipeline", enter a tag like `v34.0.4`, and run it. That tag is the only
input: the pipeline reads the version out of it and works out everything else on
its own - which branch to build from, which repositories to tag, which
milestones to move, and whether this is an alpha, beta, release candidate or a
stable release. There is no checklist to follow and no per-release bookkeeping to
remember.

Behind that button, `release.yml` runs five smaller workflows:

1. **Tag** (`release-tag.yml`) - stamps the tag on every repository that ships in
   the release (server, 3rdparty, the bundled apps, ...), each at the tip of its
   release branch. Nothing else starts until the code is tagged.
2. **Changelog** (`release-changelog.yml`) - finds the previous release,
   generates the changelog between the two, and attaches it to the GitHub
   release.
3. **Build** (`release-build.yml`) - assembles the actual archives: fetch every
   component, lay them out, strip dev files, stamp `version.php`, sign, and
   package the `.tar.bz2`/`.zip` with checksums. For now it also compares its
   output against the old release script to prove they match.
4. **Updater** (`release-updater.yml`) - once the archives and their signatures
   exist, opens a pull request to the updater server wiring in the new release
   (download URLs, signatures, supported-version rules) so clients are offered
   the update.
5. **Milestones** (`release-milestones.yml`) - tidies GitHub milestones across all
   the repos: closes the one you just shipped, moves leftover issues forward, and
   opens the next ones. Runs only when it makes sense - stable releases and the
   first beta of a new major - never for ordinary RCs.

Steps 2-4 run in sequence (the updater needs the build, the build needs the tag);
milestones run alongside them, off the tag. If a step fails, the ones after it
don't run, so the pipeline never leaves a release half-done.

Tagging, milestones and the updater PR are unit-tested PHP commands in
[`tools/release/`](tools/release/README.md); the build/package/sign steps are
bash in [`.github/scripts/`](.github/scripts/README.md). Both are covered by
tests that run on every pull request.

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
- Add GPG signatures for archives
