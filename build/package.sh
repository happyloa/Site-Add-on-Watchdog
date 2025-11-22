#!/usr/bin/env bash
set -euo pipefail

# Build a release zip without any dotfiles (e.g., .gitignore, .github) to keep
# VCS and tool configs out of published artifacts or SVN exports.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARTIFACT="${ROOT_DIR}/site-add-on-watchdog.zip"

rm -f "${ARTIFACT}"
cd "${ROOT_DIR}"

# Exclude all hidden files and directories from the archive.
zip -rq "${ARTIFACT}" . \
    -x ".*" -x "*/.*" \
    -x "${ARTIFACT}" \
    -x "tests/*" -x "tests/**" -x "*/tests/*" \
    -x "phpunit.xml.dist" -x "*/phpunit.xml.dist" \
    -x "composer.json" -x "*/composer.json" \
    -x "composer.lock" -x "*/composer.lock" \
    -x "phpcs.xml" -x "*/phpcs.xml" \
    -x "build/*" -x "*/build/*"

echo "Created ${ARTIFACT} without hidden files."
