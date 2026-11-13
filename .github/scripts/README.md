# Release Scripts

Standalone scripts to build a Nextcloud server release without GitHub Actions. These are the same scripts used by the `release-build.yml` workflow.

## Prerequisites

- `git`, `jq`, `php` (compatible version), `composer`
- `sudo` (for setting file ownership)
- `tar`, `zip`, `sha256sum`, `sha512sum`, `md5sum`
- Signing key (PEM) if signing is needed

## Quick start

Build a release for v34.0.1:

```bash
SCRIPTS=".github/scripts"
VERSION="34.0.1"
TAG="v${VERSION}"
CONFIG="stable34.json"

# 1. Fetch all components
bash "$SCRIPTS/fetch-all.sh" "$TAG" "$CONFIG" /tmp/build --docs

# 2. Clean server dev files (removes .git automatically after using it)
bash "$SCRIPTS/clean-server-dev-files.sh" /tmp/build/server

# 3. Assemble into nextcloud/ structure
bash "$SCRIPTS/assemble.sh" /tmp/build /tmp/nextcloud

# 4. Clean dev files from all apps, core, settings
for dir in /tmp/nextcloud/apps/*/ /tmp/nextcloud/core/ /tmp/nextcloud/settings/; do
  bash "$SCRIPTS/clean-dev-files.sh" "$dir"
done

# 5. Update version.php
bash "$SCRIPTS/update-version-php.sh" /tmp/nextcloud stable stable34

# 6. Sign (optional, requires signing key)
bash "$SCRIPTS/sign-release.sh" /tmp/nextcloud /path/to/signing-key.pem

# 7. Generate metadata (NC30+, optional)
bash "$SCRIPTS/generate-metadata.sh" /tmp/nextcloud "$VERSION" ./releases

# 8. Package (creates tar.bz2, zip, checksums)
bash "$SCRIPTS/package.sh" /tmp/nextcloud "$VERSION" ./releases

# 9. GPG signatures (optional, signs with your default secret key)
bash "$SCRIPTS/gpg-sign.sh" ./releases "$VERSION"
```

## Scripts

| Script | Purpose |
|---|---|
| `fetch-all.sh` | Clone server, 3rdparty, all apps, updater, skeleton, docs |
| `assemble.sh` | Place all components into the `nextcloud/` structure |
| `clean-server-dev-files.sh` | Remove dev files from server (uses .nextcloudignore or hardcoded list) |
| `apply-nextcloudignore.sh` | Remove what an app's `.nextcloudignore` lists, with git semantics (needs the app's `.git`) |
| `clean-dev-files.sh` | Remove the dev files listed in `dev-files.txt` from an app directory; `DEV_FILES_REPORT` records what `.nextcloudignore` missed |
| `dev-files.txt` | The development files that must not ship, in `.nextcloudignore` syntax |
| `update-version-php.sh` | Rewrite version.php with channel, build timestamp, edition |
| `sign-release.sh` | Sign core + all apps with occ integrity commands |
| `generate-metadata.sh` | Generate migration metadata (NC30+) |
| `package.sh` | Set permissions, create tar.bz2 + zip, generate checksums (including the `.metadata` file's) |
| `gpg-sign.sh` | Write `.asc` GPG signatures for the archives |

> Milestone management, repository tagging and the updater-server PR have moved
> to the unit-tested PHP package in [`tools/release/`](../../tools/release/README.md)
> (`milestones:update`, `milestones:audit`, `repo:tag`, `updater:bump`).

## Milestone management, tagging & updater server

Moved to the PHP package - see [`tools/release/`](../../tools/release/README.md),
which documents the full release auto-logic (when milestones are created/closed,
when tags are created, which branch is used).

The audit determines expected state from the latest stable release tags on
`nextcloud-releases/server`. It exits with code 1 if issues are found.

Both scripts require `gh` CLI authenticated with a token that has repo access to all Nextcloud repositories.

## Notes

- `fetch-all.sh` runs composer automatically on apps with runtime dependencies
- `clean-server-dev-files.sh` must run before removing `.git` from the server (it uses `git ls-files` for .nextcloudignore)
- `apply-nextcloudignore.sh` must run on each app before its `.git` is removed, for the same reason. It keeps `composer.json`, `composer.lock`, `package.json` and `package-lock.json` even when listed
- `sign-release.sh` uses the repo's `resources/codesigning/core.crt` by default. Pass a custom cert as the third argument for testing
- `package.sh` requires `sudo` to set file ownership to `nobody:nogroup`
- The workflow adds parallelism (fetches all apps simultaneously) and a compare step. The scripts produce identical output
