#!/usr/bin/env bash
#
# Build a local, runnable Statamic 6 test environment ("playground") for
# goldnead/statamic-marketing — same pattern as LeadHub's playground script.
#
#   * fresh Statamic 6 install at ./playground
#   * leadhub + marketing wired in as Composer *path* repositories
#   * SQLite, migrations run, CP assets published
#   * a CP super-user created non-interactively
#   * demo data: two mailing lists with realistic subscriber counts, a
#     branded template, and campaigns in every lifecycle state (sent with
#     open/click stats, scheduled, draft) — pretty enough for screenshots
#
# After it finishes:
#
#   cd playground && php artisan serve --host=0.0.0.0 --port=8000
#   → open http://127.0.0.1:8000/cp  (login printed at the end)
#
# Re-running is safe; pass --fresh to wipe and rebuild.
#
# Env overrides: CP_EMAIL (admin@example.com), CP_PASSWORD (password),
# PHP_BIN, COMPOSER_BIN, LEADHUB_PATH (../statamic-leadhub).

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADDON_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLAYGROUND_DIR="$ADDON_DIR/playground"
LEADHUB_PATH="${LEADHUB_PATH:-$ADDON_DIR/../statamic-leadhub}"

CP_EMAIL="${CP_EMAIL:-admin@example.com}"
CP_PASSWORD="${CP_PASSWORD:-password}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

export COMPOSER_ALLOW_SUPERUSER=1

FRESH=0
[ "${1:-}" = "--fresh" ] && FRESH=1

step() { echo; echo "▸ $*"; }
ok()   { echo "  ✓ $*"; }
warn() { echo "  ⚠ $*"; }

php_app() { ( cd "$PLAYGROUND_DIR" && "$PHP_BIN" -r '
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
'"$1" ); }

step "Pre-flight"
command -v "$PHP_BIN" >/dev/null      || { echo "php not found"; exit 1; }
command -v "$COMPOSER_BIN" >/dev/null || { echo "composer not found"; exit 1; }
"$PHP_BIN" -m | grep -qi sqlite       || { echo "php sqlite extension required"; exit 1; }
[ -d "$LEADHUB_PATH" ]                || { echo "leadhub checkout not found at $LEADHUB_PATH (set LEADHUB_PATH)"; exit 1; }
ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;'), addon: $ADDON_DIR"

if [ "$FRESH" = "1" ] && [ -d "$PLAYGROUND_DIR" ]; then
    step "Removing existing playground (--fresh)"
    rm -rf "$PLAYGROUND_DIR"
fi

if [ -d "$PLAYGROUND_DIR" ]; then
    warn "playground/ already exists — skipping scaffold. Pass --fresh to rebuild."
else
    step "Creating Statamic project at playground/"
    "$COMPOSER_BIN" create-project --prefer-dist --no-interaction --no-scripts \
        statamic/statamic "$PLAYGROUND_DIR" 2>&1 | tail -3
    cd "$PLAYGROUND_DIR"
    "$COMPOSER_BIN" install --no-interaction --prefer-dist 2>&1 | tail -2
    ok "Statamic installed"

    step "Configuring SQLite + APP_KEY"
    [ -f .env ] || { cp .env.example .env 2>/dev/null || touch .env; }
    "$PHP_BIN" -r '
    $p=".env"; $e=file_exists($p)?file_get_contents($p):"";
    $lines=array_filter(preg_split("/\r?\n/",$e),fn($l)=>!preg_match("/^(DB_CONNECTION|DB_DATABASE|MAIL_MAILER)=/",$l));
    $lines[]="DB_CONNECTION=sqlite";
    $lines[]="DB_DATABASE=".__DIR__."/database/database.sqlite";
    $lines[]="MAIL_MAILER=log";
    file_put_contents($p,implode("\n",$lines)."\n");'
    mkdir -p database && touch database/database.sqlite
    "$PHP_BIN" artisan key:generate --force --no-interaction >/dev/null
    "$PHP_BIN" artisan migrate --force --no-interaction 2>&1 | tail -1
    ok "SQLite configured"

    step "Wiring leadhub + marketing as Composer path repositories"
    "$COMPOSER_BIN" config repositories.leadhub path "$LEADHUB_PATH" --no-interaction
    "$COMPOSER_BIN" config repositories.marketing path "$ADDON_DIR" --no-interaction
    "$COMPOSER_BIN" config minimum-stability dev --no-interaction
    "$COMPOSER_BIN" config prefer-stable true --no-interaction
    "$COMPOSER_BIN" config --no-interaction allow-plugins.pixelfear/composer-dist-plugin true
    "$COMPOSER_BIN" config --no-interaction allow-plugins.composer/installers true
    "$COMPOSER_BIN" config --no-interaction allow-plugins.php-http/discovery true
    "$COMPOSER_BIN" require "goldnead/statamic-marketing:@dev" -W --no-interaction --prefer-dist 2>&1 | tail -3
    ok "Addons installed (path repos — src/ edits are live)"

    "$PHP_BIN" artisan vendor:publish --tag=marketing-config --force --no-interaction >/dev/null || true
    "$PHP_BIN" artisan migrate --force --no-interaction 2>&1 | tail -1
    ok "Config published + tables migrated"
fi

cd "$PLAYGROUND_DIR"

step "Publishing addon CP assets"
if [ ! -f "$ADDON_DIR/resources/dist/build/manifest.json" ]; then
    warn "resources/dist/build missing — run npm run build in the addon first"
fi
"$PHP_BIN" artisan vendor:publish --tag=statamic-marketing --force --no-interaction 2>&1 | tail -1
"$PHP_BIN" artisan vendor:publish --tag=statamic-leadhub --force --no-interaction 2>&1 | tail -1
ok "CP assets published"

step "Creating Control Panel super-user"
CP_EMAIL="$CP_EMAIL" CP_PASSWORD="$CP_PASSWORD" php_app '
use Statamic\Facades\User;
$email=getenv("CP_EMAIL");
$u=User::findByEmail($email) ?: User::make()->email($email);
$u->password(getenv("CP_PASSWORD"))->makeSuper()->save();
echo "user_ready\n";
' | grep -q user_ready && ok "Super-user: $CP_EMAIL / $CP_PASSWORD"

step "Seeding demo lists, subscribers, templates & campaigns"
php_app "require '$ADDON_DIR/scripts/playground-seed.php';"
ok "Demo data ready"

echo
echo "Playground ready:"
echo "  cd playground && $PHP_BIN artisan serve --host=0.0.0.0 --port=8000"
echo "  → http://127.0.0.1:8000/cp  ($CP_EMAIL / $CP_PASSWORD)"
