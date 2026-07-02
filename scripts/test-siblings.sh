#!/usr/bin/env bash
#
# Run the live integration suite against goldnead/statamic-automations and
# goldnead/statamic-webhook-manager.
#
# The siblings are OPTIONAL peers: the default test suite runs with them
# absent (the Integration tests self-skip). This script installs both into a
# throwaway copy of the repo — the committed composer.json/lock and your
# working tree stay untouched — and runs ONLY the Integration suite, which
# exercises the real cross-addon paths:
#
#   1. automations registers the marketing triggers/actions as built-in nodes
#      and runs an automation from a real subscription event
#   2. the marketing bridge contributes its templates to the automations catalog
#   3. webhook-manager exposes marketing events as outbound triggers and the
#      marketing.process_esp_event inbound action
#
# Usage:
#   scripts/test-siblings.sh
#
#   # point at local checkouts instead of cloning from GitHub:
#   AUTOMATIONS_PATH=../statamic-automations WEBHOOK_MANAGER_PATH=../statamic-webhook-manager scripts/test-siblings.sh
#
# Requirements: PHP >=8.2 (sqlite, dom, mbstring, fileinfo), Composer 2.x.

set -euo pipefail
IFS=$'\n\t'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AUTOMATIONS_PATH="${AUTOMATIONS_PATH:-}"
WEBHOOK_MANAGER_PATH="${WEBHOOK_MANAGER_PATH:-}"
LEADHUB_PATH="${LEADHUB_PATH:-}"
AUTOMATIONS_REPO="${AUTOMATIONS_REPO:-https://github.com/goldnead/statamic-automations.git}"
WEBHOOK_MANAGER_REPO="${WEBHOOK_MANAGER_REPO:-https://github.com/goldnead/statamic-webhook-manager.git}"

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

echo "==> Staging a throwaway copy of the addon in $WORKDIR"
git -C "$REPO_ROOT" archive --format=tar HEAD | tar -x -C "$WORKDIR"
cd "$WORKDIR"

# LeadHub is a hard dependency resolved via a relative path repo; keep that
# resolvable from the throwaway location.
composer config repositories.leadhub path "${LEADHUB_PATH:-$REPO_ROOT/../statamic-leadhub}"

echo "==> Registering the sibling addons as Composer dev dependencies"
if [[ -n "$AUTOMATIONS_PATH" ]]; then
    composer config repositories.automations path "$AUTOMATIONS_PATH"
else
    composer config repositories.automations vcs "$AUTOMATIONS_REPO"
fi

if [[ -n "$WEBHOOK_MANAGER_PATH" ]]; then
    composer config repositories.webhook-manager path "$WEBHOOK_MANAGER_PATH"
else
    composer config repositories.webhook-manager vcs "$WEBHOOK_MANAGER_REPO"
fi

composer require --dev \
    "goldnead/statamic-automations:*@dev" \
    "goldnead/statamic-webhook-manager:*@dev" \
    --no-interaction --no-progress --with-all-dependencies

echo "==> Running the Integration suite"
vendor/bin/pest --testsuite=Integration --colors=always
