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

# The pest plugin must be allowed to run even when this executes as root (CI).
export COMPOSER_ALLOW_SUPERUSER=1

echo "==> Staging a throwaway copy of the addon in $WORKDIR"
git -C "$REPO_ROOT" archive --format=tar HEAD | tar -x -C "$WORKDIR"
cd "$WORKDIR"

# LeadHub is a hard dependency declared as a RELATIVE path repository in
# composer.json — rewrite it to an absolute path so it resolves from the
# throwaway location.
LEADHUB_ABS="$(cd "${LEADHUB_PATH:-$REPO_ROOT/../statamic-leadhub}" && pwd)"
php -r '
    $file = "composer.json";
    $data = json_decode(file_get_contents($file), true);
    foreach ($data["repositories"] ?? [] as $i => $repo) {
        if (($repo["type"] ?? null) === "path" && str_contains($repo["url"], "statamic-leadhub")) {
            $data["repositories"][$i]["url"] = $argv[1];
        }
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$LEADHUB_ABS"

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
