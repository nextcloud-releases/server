#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: MIT
#
# Clean development files from the server repository.
# Removes .git at the end (needs it first for .nextcloudignore).
# Usage: clean-server-dev-files.sh [server-dir]

set -e

cd "${1:-.}"

# NC25+ uses .nextcloudignore with gitignore-style patterns.
# git ls-files -ic finds tracked files matching the ignore patterns.
# Older versions fall back to a hardcoded removal list.
if [ -f ".nextcloudignore" ]; then
  git ls-files -ic --exclude-from=.nextcloudignore -z | xargs -0 -r -n 10 -- rm -fr
  find . -empty -type d -delete
else
  rm -rf .babelrc .codecov.yml .devcontainer .drone.yml .editorconfig
  rm -rf .envrc .eslintignore .eslintrc.js .git-blame-ignore-revs
  rm -rf .gitattributes .github .gitignore .gitmodules .idea .jshintrc
  rm -rf .mailmap .npmignore .php-cs-fixer.dist.php .php_cs.dist
  rm -rf .pre-commit-config.yaml .scrutinizer.yml .tag .tx
  rm -rf CHANGELOG.md CODE_OF_CONDUCT.md COPYING-README DESIGN.md
  rm -rf Makefile README.md SECURITY.md __mocks__ __tests__
  rm -rf apps/dav/bin apps/testing
  rm -rf autotest-checkers.sh autotest-external.sh autotest-js.sh autotest.sh
  rm -rf babel.config.js build codecov.yml contribute custom.d.ts
  rm -rf cypress cypress.config.ts cypress.d.ts
  rm -rf eslint.config.js eslint.config.mjs flake.lock flake.nix
  rm -rf jest.config.js jest.config.ts openapi.json
  rm -rf psalm-ncu.xml psalm-ocp.xml psalm.xml stylelint.config.js
  rm -rf tests tsconfig.json vendor-bin vite.config.ts
  rm -rf vitest.config.mts vitest.config.ts
  rm -rf webpack.common.cjs webpack.common.js webpack.config.js
  rm -rf webpack.dev.js webpack.modules.cjs webpack.modules.js webpack.prod.js
  rm -rf window.d.ts
  rm -rf .direnv .well-known config/config.php data
fi

# Remove .git last (needed above for git ls-files)
rm -rf .git
