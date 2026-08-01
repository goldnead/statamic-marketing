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
# What is under test is THIS addon's tests/Integration suite against the
# siblings' real code. The siblings' own test suites are never run here, so
# installing them from dist (which no longer ships tests/) is correct — what
# is needed from them is src/, routes/, config/ and database/migrations/, and
# none of those are export-ignored.
#
# Usage:
#   scripts/test-siblings.sh
#
#   # point at local checkouts instead of resolving from Packagist:
#   AUTOMATIONS_PATH=../statamic-automations WEBHOOK_MANAGER_PATH=../statamic-webhook-manager scripts/test-siblings.sh
#
# Requirements: PHP >=8.2 (sqlite, dom, mbstring, fileinfo), Composer 2.x.

set -euo pipefail
IFS=$'\n\t'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AUTOMATIONS_PATH="${AUTOMATIONS_PATH:-}"
WEBHOOK_MANAGER_PATH="${WEBHOOK_MANAGER_PATH:-}"
LEADHUB_PATH="${LEADHUB_PATH:-}"

# Path overrides have to be absolute before the `cd` below, or Composer
# resolves them against the throwaway directory instead of the directory the
# caller ran this from — the documented `../statamic-automations` would have
# pointed at nothing.
for var in AUTOMATIONS_PATH WEBHOOK_MANAGER_PATH LEADHUB_PATH; do
    if [[ -n "${!var}" ]]; then
        printf -v "$var" '%s' "$(cd "${!var}" && pwd)"
    fi
done

WORKDIR="$(mktemp -d)"
STAGE="$WORKDIR/addon"
trap 'rm -rf "$WORKDIR"' EXIT

# The pest plugin must be allowed to run even when this executes as root (CI).
export COMPOSER_ALLOW_SUPERUSER=1

echo "==> Staging a throwaway copy of the addon in $STAGE"
# Deliberately NOT `git archive`: it applies .gitattributes export-ignore, and
# since the packaging sweep of 01.08.2026 that list holds /tests, /phpunit.xml
# and /scripts. Staging through an archive therefore produced a copy with no
# test suite and no PHPUnit config at all, and Pest aborted with
# "The test directory [%s] does not exist." export-ignore is right for the
# Composer tarball and stays; it just must not decide what CI runs.
#
# read-tree into a scratch index plus checkout-index yields the same HEAD
# content without the export filter, and never touches the real index.
mkdir -p "$STAGE"
GIT_INDEX_FILE="$WORKDIR/stage-index" git -C "$REPO_ROOT" read-tree HEAD
GIT_INDEX_FILE="$WORKDIR/stage-index" git -C "$REPO_ROOT" \
    checkout-index --all --force --prefix="$STAGE/"

if [[ ! -d "$STAGE/tests/Integration" ]]; then
    echo "The staged copy has no tests/Integration — staging is broken, not the suite." >&2
    exit 1
fi

cd "$STAGE"

# The three hard siblings (brand-context, leadhub, suppression) are ordinary
# requirements and resolve from Packagist like everything else. LEADHUB_PATH is
# honoured for working against an uncommitted checkout.
if [[ -n "$LEADHUB_PATH" ]]; then
    composer config repositories.leadhub path "$LEADHUB_PATH"
fi

echo "==> Registering the sibling addons as Composer dev dependencies"
# Both optional siblings are published on Packagist since 01.08.2026, so the
# default is the newest stable release — the version a user actually installs
# next to this addon. The VCS repository entries this script used to write
# existed only because the repos were private, and pinning `*@dev` meant CI
# tested unreleased sibling branches against a released addon.
requirements=()

if [[ -n "$AUTOMATIONS_PATH" ]]; then
    composer config repositories.automations path "$AUTOMATIONS_PATH"
    requirements+=("goldnead/statamic-automations:*@dev")
else
    requirements+=("goldnead/statamic-automations:*")
fi

if [[ -n "$WEBHOOK_MANAGER_PATH" ]]; then
    composer config repositories.webhook-manager path "$WEBHOOK_MANAGER_PATH"
    requirements+=("goldnead/statamic-webhook-manager:*@dev")
else
    requirements+=("goldnead/statamic-webhook-manager:*")
fi

composer require --dev "${requirements[@]}" \
    --no-interaction --no-progress --with-all-dependencies

echo "==> Running the Integration suite"
JUNIT="$WORKDIR/integration.junit.xml"

status=0
vendor/bin/pest --testsuite=Integration --colors=always --log-junit "$JUNIT" || status=$?

if [[ $status -ne 0 ]]; then
    exit "$status"
fi

# A skip is a failure HERE, and this is asserted rather than delegated to
# `--fail-on-skipped`: Pest 3 accepts that flag and then exits 0 anyway (plain
# PHPUnit honours it, the Pest runner does not). Every test in this suite calls
# markTestSkipped() when its sibling class is missing, so a run in which the
# siblings failed to install, failed to autoload or failed to boot their
# bridges reports "7 skipped" and exits 0 — indistinguishable from a pass in
# the CI summary, which is the state these seven tests were in for their whole
# existence. Below, the siblings are installed by construction.
if [[ ! -s "$JUNIT" ]] || ! grep -q '<testcase' "$JUNIT"; then
    echo "The Integration suite ran no tests at all." >&2
    exit 1
fi

if grep -q '<skipped' "$JUNIT"; then
    echo >&2
    echo "The siblings are installed, yet tests skipped themselves — a bridge did" >&2
    echo "not load. The skip reasons are in the Pest output above." >&2
    exit 1
fi

echo "==> Integration suite ran against the installed siblings, nothing skipped."
