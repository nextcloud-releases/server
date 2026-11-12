#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Remove development files from an app or component directory, using the
# release script's getDataToBeRemovedFromAppFolders list. The app's own
# .nextcloudignore is applied earlier by apply-nextcloudignore.sh, so anything
# this still removes is something the app's .nextcloudignore should list.
#
# Usage: clean-dev-files.sh <directory>
# With DEV_FILES_REPORT set, appends "<dir name><TAB><path>" for each removal.

set -e

DIR="${1:-.}"
cd "$DIR"
APP=$(basename "$(pwd)")

remove() {
  [ -e "$1" ] || [ -L "$1" ] || return 0
  rm -rf "$1"
  # An app cannot be expected to list its .nextcloudignore in itself.
  if [ -n "${DEV_FILES_REPORT:-}" ] && [ "$1" != .nextcloudignore ]; then
    printf '%s\t%s\n' "$APP" "$1" >> "$DEV_FILES_REPORT"
  fi
}

DEV_PATTERNS=(
  .babelrc .codecov.yml .devcontainer .drone.yml .editorconfig .eslintignore
  .git/ .gitattributes .github .gitignore .git-blame-ignore-revs
  .jshintrc .l10nignore .lgtm .nextcloudignore .npmignore .noopenapi
  .php_cs.dist .php-cs-fixer.dist.php .prettierignore .scrutinizer.yml
  .stylelintignore .travis.yml .tx/
  build-js/ build.xml
  check-handlebars-templates.sh codecov.yml compile-handlebars-templates.sh
  CONTRIBUTING.md
  cypress.json cypress/
  issue_template.md jest-raw-loader.js jsconfig.json
  krankerl.toml l10n/.gitkeep Makefile phpDocumentor.sh playwright/ psalm.xml
  README.md rector.php renovate.json screenshots/ src/ testConfiguration.json
  tests/ vendor-bin/
  webpack.common.js webpack.dev.js webpack.js webpack.prod.js
)

for pattern in "${DEV_PATTERNS[@]}"; do
  remove "${pattern%/}"
  if [[ "$pattern" != */ ]]; then
    remove "${pattern}.license"
  fi
done

# JavaScript config files: cross-product of base names × extensions
# (matches release script's $javascriptConfigs × $suffix loop)
JS_CONFIG_BASES=(
  .babelrc .eslintrc .prettierrc .stylelintrc
  babel.config cypress.config eslint.config jest.config jsconfig
  oxlintrc oxlint.config playwright.config postcss.config prettier.config
  rspack.config stylelint.config tsconfig vite.config vitest.config
  webpack webpack.config
)
JS_EXTENSIONS=(.json .js .mjs .cjs .ts .mts .cts)

for base in "${JS_CONFIG_BASES[@]}"; do
  for ext in "${JS_EXTENSIONS[@]}"; do
    remove "${base}${ext}"
    remove "${base}${ext}.license"
  done
done

# Remove REUSE sidecar files for sourcemaps. Sourcemaps (*.map) ship in the
# release, but their *.map.license sidecars are REUSE metadata that only matters
# in source repos, pure clutter in the tarball.
find . -type f -name '*.map.license' -delete
