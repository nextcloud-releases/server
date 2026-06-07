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
```

## Scripts

| Script | Purpose |
|---|---|
| `fetch-all.sh` | Clone server, 3rdparty, all apps, updater, skeleton, docs |
| `assemble.sh` | Place all components into the `nextcloud/` structure |
| `clean-server-dev-files.sh` | Remove dev files from server (uses .nextcloudignore or hardcoded list) |
| `clean-dev-files.sh` | Remove dev files from an app directory |
| `update-version-php.sh` | Rewrite version.php with channel, build timestamp, edition |
| `sign-release.sh` | Sign core + all apps with occ integrity commands |
| `generate-metadata.sh` | Generate migration metadata (NC30+) |
| `package.sh` | Set permissions, create tar.bz2 + zip, generate checksums |
| `update-updater-server.sh` | Create a PR to the updater server with release config and tests |

## Updater server

After a release is built and signed, `update-updater-server.sh` creates a PR to [`nextcloud-releases/updater_server`](https://github.com/nextcloud-releases/updater_server) with:

- Updated `config/releases.json` (new version, signatures)
- Regenerated `config/config.php`
- Updated Behat feature files (version strings, URLs, signatures)

```bash
# Patch release
bash .github/scripts/update-updater-server.sh v33.0.6 "$(cat bz2.sig)" "$(cat zip.sig)"

# First stable at 30% rollout
bash .github/scripts/update-updater-server.sh v34.0.0 "$BZ2_SIG" "$ZIP_SIG" --deploy 30

# RC bump (dry-run)
bash .github/scripts/update-updater-server.sh v34.0.0rc6 "$BZ2_SIG" "$ZIP_SIG" --dry-run

# Use existing checkout instead of cloning
bash .github/scripts/update-updater-server.sh v33.0.6 "$BZ2_SIG" "$ZIP_SIG" --repo-dir /path/to/updater_server
```

**Deploy percentage**: first stable releases start at 30%, bumped to 70% then 100% in separate PRs over the following patch releases. Use `--deploy 30` for first stable.

The workflow (`release-updater.yml`) can also be triggered manually from the Actions UI with a dry-run option for testing.

## Notes

- `fetch-all.sh` runs composer automatically on apps with runtime dependencies
- `clean-server-dev-files.sh` must run before removing `.git` from the server (it uses `git ls-files` for .nextcloudignore)
- `sign-release.sh` uses the repo's `resources/codesigning/core.crt` by default. Pass a custom cert as the third argument for testing
- `package.sh` requires `sudo` to set file ownership to `nobody:nogroup`
- The workflow adds parallelism (fetches all apps simultaneously) and a compare step. The scripts produce identical output
