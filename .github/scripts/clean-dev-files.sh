#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Clean development files from an app or component directory.
# Usage: clean-dev-files.sh <directory>

set -e

DIR="${1:-.}"
cd "$DIR"

# Process .nextcloudignore if present
if [ -f ".nextcloudignore" ]; then
  APP_DIR=$(pwd)
  while IFS= read -r line || [[ -n "$line" ]]; do
    pattern=$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/\r$//')
    [[ -z "$pattern" || "$pattern" == \#* ]] && continue
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

# Remove dev file patterns (based on getDataToBeRemovedFromAppFolders)
DEV_PATTERNS=(
  .babelrc .babelrc.js .codecov.yml .devcontainer .drone.yml .editorconfig
  .eslintrc.cjs .eslintrc.js .eslintrc.json .eslintignore
  .git/ .gitattributes .github .gitignore .git-blame-ignore-revs
  .jshintrc .l10nignore .lgtm .nextcloudignore .npmignore .noopenapi
  .php_cs.dist .php-cs-fixer.dist.php .scrutinizer.yml .stylelintignore
  .stylelintrc.js .travis.yml .tx/
  babel.config.js build-js/ build.xml
  check-handlebars-templates.sh codecov.yml compile-handlebars-templates.sh
  CONTRIBUTING.md
  cypress.config.js cypress.config.ts cypress.json cypress/
  issue_template.md jest-raw-loader.js jest.config.js jsconfig.json
  krankerl.toml l10n/.gitkeep Makefile postcss.config.js psalm.xml
  .prettierignore
  README.md rector.php renovate.json screenshots/ src/
  stylelint.config.js stylelint.config.cjs tests/ tsconfig.json
  vite.config.js vite.config.ts vitest.config.js vitest.config.ts
  webpack.common.js webpack.config.js webpack.dev.js webpack.js webpack.prod.js
)

for pattern in "${DEV_PATTERNS[@]}"; do
  rm -rf "$pattern"
  if [[ "$pattern" != */ ]]; then
    rm -rf "${pattern}.license"
  fi
done

# Catch-all: any *.config.{js,ts,mjs,cjs} is a dev config
find . -maxdepth 1 \( -name "*.config.js" -o -name "*.config.ts" -o -name "*.config.mjs" -o -name "*.config.cjs" \) -delete 2>/dev/null || true

# Remove .map.license files (REUSE companions of sourcemaps, not needed in release)
find . -name "*.map.license" -delete 2>/dev/null || true
