#!/usr/bin/env bash
#
# check-dist-fresh.sh — guard against stale committed CP build assets.
#
# resources/dist/build is COMMITTED on purpose: Marketplace / Composer installs
# never run npm, so the compiled Control-Panel bundle must ship in git and must
# always match the current source. A stale bundle is what caused the
# webhook-manager "vue is not defined" browser error (source rebuilt, dist not
# re-committed). This script rebuilds the bundle and fails if the committed
# output differs from a fresh build.
#
# Authoritative on a CLEAN tree / in CI (fresh checkout == committed HEAD).
# Run locally after committing source changes to confirm dist was rebuilt too.
#
# Usage:
#   npm run build:check          # local (uses installed node_modules)
#   CI: `npm ci` then `npm run build:check`
#
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -d node_modules ]; then
  echo "node_modules missing — run 'npm ci' (or 'npm install') first." >&2
  exit 2
fi

DIST="resources/dist/build"

echo "==> Rebuilding CP bundle (npm run build)"
npm run build >/dev/null

if git diff --quiet HEAD -- "$DIST" && [ -z "$(git status --porcelain -- "$DIST")" ]; then
  echo "OK: committed $DIST matches a fresh build."
  exit 0
fi

{
  echo "STALE: committed $DIST does not match a fresh build."
  echo "Changed files:"
  git --no-pager diff --stat HEAD -- "$DIST" || true
  git status --porcelain -- "$DIST" || true
  echo ""
  echo "Fix: npm run build && git add $DIST && commit the rebuilt assets."
} >&2
exit 1
