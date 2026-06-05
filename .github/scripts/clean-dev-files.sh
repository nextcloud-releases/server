#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Clean development files from an app or component directory.
# Usage: clean-dev-files.sh <directory>

set -e

DIR="${1:-.}"
cd "$DIR"

# Files to preserve even if .nextcloudignore lists them
KEEP_FILES=(composer.json composer.lock package.json package-lock.json)

# Process .nextcloudignore if present
if [ -f ".nextcloudignore" ]; then
  APP_DIR=$(pwd)
  while IFS= read -r line || [[ -n "$line" ]]; do
    pattern=$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/\r$//')
    [[ -z "$pattern" || "$pattern" == \#* ]] && continue

    # Check if this pattern targets a preserved file
    # e.g. /composer.* would match composer.json — skip the whole pattern
    clean="${pattern#/}"
    skip=false
    for keep in "${KEEP_FILES[@]}"; do
      # shellcheck disable=SC2254
      if [[ "$keep" == $clean ]]; then
        skip=true
        break
      fi
    done
    $skip && continue

    if [[ "$pattern" == /* ]]; then
      pattern="${pattern#/}"
      # shellcheck disable=SC2086
      for match in $APP_DIR/$pattern; do
        [ -e "$match" ] && rm -rf "$match"
      done
    else
      find "$APP_DIR" -name "$pattern" -exec rm -rf {} + 2>/dev/null || true
    fi
  done < .nextcloudignore
fi

# Remove dev file patterns (synced with release script's getDataToBeRemovedFromAppFolders)
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
  krankerl.toml l10n/.gitkeep Makefile phpDocumentor.sh psalm.xml
  README.md rector.php renovate.json screenshots/ src/ testConfiguration.json
  tests/ vendor-bin/
  webpack.common.js webpack.dev.js webpack.js webpack.prod.js
)

for pattern in "${DEV_PATTERNS[@]}"; do
  rm -rf "$pattern"
  if [[ "$pattern" != */ ]]; then
    rm -rf "${pattern}.license"
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
    rm -rf "${base}${ext}"
    rm -rf "${base}${ext}.license"
  done
done

# Remove REUSE sidecar files for sourcemaps. Sourcemaps (*.map) ship in the
# release, but their *.map.license sidecars are REUSE metadata that only matters
# in source repos — pure clutter in the tarball.
find . -type f -name '*.map.license' -delete

