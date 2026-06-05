# Nextcloud Server Releases

Release artifacts and automation for Nextcloud server. Branches are synced daily from `nextcloud/server`.

## How releases work

When a release is published on this repository, three things happen in parallel:

1. **Changelog** is generated and attached to the release
2. **All app repositories** get tagged at their stable branch HEAD
3. **Release archives** are built independently and compared against the release script output
4. **Milestones** are updated across all repos (stable releases and first betas only)

The tagger and builder can also be run manually for re-tagging or testing.

## Release configuration

One JSON file per major version lists all bundled apps:

- `stable32.json`, `stable33.json` — 23 apps
- `stable34.json`, `master.json` — 25 apps (+files_lock, +office)

When a new app is added to the release or an existing one is removed, edit the corresponding JSON file.

`tag-only.json` lists repositories that should be tagged on release but are not part of the build (server, 3rdparty, updater, example-files, documentation).

## Running manually

**Re-tag a release**: Actions > "Tag all repositories" > enter tag (e.g., `v34.0.1`). Check "force" to overwrite existing tags.

**Rebuild a release**: Actions > "Build and compare release" > enter tag. Compares the result against the release script's archives on the same GitHub release.

**Update milestones**: Actions > "Update milestones on release" > enter tag. Use dry-run to preview. Runs automatically for stable releases and first betas. Check "audit only" to verify consistency without making changes.

## Where we are

The old release script still creates releases and uploads to the download server. This workflow runs alongside it to validate that both produce the same result.

Once we are confident the output matches, the release script will be retired and this workflow will take over publishing.

## What comes next

- Enable publishing directly from the workflow (retire the release script)
- Auto-create PRs to the updater server with release configuration
- Add GPG signatures for archives
