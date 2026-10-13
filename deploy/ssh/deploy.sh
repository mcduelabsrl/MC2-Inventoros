#!/usr/bin/env bash
#
# Deploy the current checkout to a cPanel-layout server (cpanel-v1) over SSH.
#
#   deploy/ssh/deploy.sh            dry run: show what would change, touch nothing
#   deploy/ssh/deploy.sh --apply    back up the DB, sync the files, migrate, optimize
#
# Options (with --apply or alone):
#   --assets      also run `npm ci && npm run build` and sync public/build
#   --no-backup   skip the database dump (not recommended)
#
# Environment:
#   DEPLOY_HOST   SSH host or ~/.ssh/config alias        (default: siteground)
#   DEPLOY_ROOT   folder holding inventoros/ and public_html/, relative to the
#                 remote home                               (default: www/saxone.eu)
#   DEPLOY_PHP    PHP CLI binary on the server              (default: php)
#
# Never synced: .env, storage/, vendor/ (rebuilt on the server when composer.lock
# changes), bootstrap/cache, the web root's runtime state (storage, hot,
# plugin-assets) and everything build-release.php leaves out of a package.

set -euo pipefail

HOST="${DEPLOY_HOST:-siteground}"
ROOT="${DEPLOY_ROOT:-www/saxone.eu}"
PHP="${DEPLOY_PHP:-php}"
APPLY=0
ASSETS=0
BACKUP=1

for arg in "$@"; do
    case "$arg" in
        --apply) APPLY=1 ;;
        --assets) ASSETS=1 ;;
        --no-backup) BACKUP=0 ;;
        -h|--help) sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

cd "$(git rev-parse --show-toplevel)"

APP="$ROOT/inventoros"
WEB="$ROOT/public_html"

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
remote() { ssh "$HOST" "cd $APP && $*"; }

step "Checkout"
git log -1 --format='%h %s (%an, %ar)'
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Uncommitted changes in tracked files: commit or stash them first." >&2
    [ "$APPLY" = 1 ] && exit 1
fi

# What goes into inventoros/: the same entries a cPanel package holds.
APP_ENTRIES=(LICENSE VERSION app artisan bootstrap composer.json composer.lock config database lang plugins resources routes)
APP_EXCLUDES=(
    --exclude='.git' --exclude='node_modules'
    --exclude='/bootstrap/cache/*'
    --exclude='/database/seeders/E2ETestSeeder.php'
    --exclude='/database/seeders/E2ELayoutSeeder.php'
    --exclude='/database/seeders/ScreenshotSeeder.php'
    # Runtime state on the server, never in the repo: keep it out of --delete.
    --exclude='/storage' --exclude='/vendor' --exclude='/.env' --exclude='php_errorlog'
)
WEB_EXCLUDES=(
    --exclude='/index.php' --exclude='/.htaccess'
    --exclude='/storage' --exclude='/hot' --exclude='/plugin-assets' --exclude='/plugins'
    --exclude='php_errorlog' --exclude='Default.html'
)
[ "$ASSETS" = 1 ] || WEB_EXCLUDES+=(--exclude='/build')

RSYNC=(rsync -rlc --delete --itemize-changes)
[ "$APPLY" = 1 ] || RSYNC+=(--dry-run)

if [ "$ASSETS" = 1 ]; then
    step "Building front-end assets"
    npm ci --no-audit --no-fund
    npm run build
    test -f public/build/manifest.json
fi

step "Composer"
LOCK_CHANGED=0
if ! ssh "$HOST" "cat $APP/composer.lock" 2>/dev/null | cmp -s - composer.lock; then
    LOCK_CHANGED=1
    echo "composer.lock differs from the server: vendor/ will be rebuilt there."
else
    echo "composer.lock unchanged: vendor/ stays as it is."
fi

if [ "$APPLY" = 1 ] && [ "$BACKUP" = 1 ]; then
    step "Database backup"
    # Credentials go into a 0600 client file for the length of the dump, never on a command line.
    ssh "$HOST" "cd $APP && $PHP" <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = config('database.connections.'.config('database.default'));
if (($c['driver'] ?? '') !== 'mysql' && ($c['driver'] ?? '') !== 'mariadb') {
    fwrite(STDERR, "Backup supports MySQL/MariaDB only (driver: {$c['driver']}).\n");
    exit(1);
}
$file = getenv('HOME').'/.inventoros-dump.cnf';
touch($file);
chmod($file, 0600);
file_put_contents($file, sprintf("[client]\nuser=\"%s\"\npassword=\"%s\"\nhost=%s\nport=%s\n",$c['username'], addcslashes((string) $c['password'], '"\\'), $c['host'], $c['port']));
file_put_contents(getenv('HOME').'/.inventoros-dump.db', $c['database']);
PHP
    ssh "$HOST" bash -s -- "$(git rev-parse --short HEAD)" <<'SH'
set -uo pipefail
mkdir -p ~/backups/inventoros && chmod 700 ~/backups/inventoros
f=~/backups/inventoros/$(date -u +%Y%m%d-%H%M%S)-before-$1.sql.gz
mysqldump --defaults-extra-file="$HOME/.inventoros-dump.cnf" --single-transaction --quick --no-tablespaces "$(cat ~/.inventoros-dump.db)" | gzip > "$f"
rc=${PIPESTATUS[0]}
rm -f ~/.inventoros-dump.cnf ~/.inventoros-dump.db
if [ "$rc" != 0 ]; then echo "mysqldump failed" >&2; rm -f "$f"; exit 1; fi
ls -lh "$f"
ls -1t ~/backups/inventoros/*.sql.gz | tail -n +11 | xargs -r rm -f
SH
fi

if [ "$APPLY" = 1 ]; then
    step "Maintenance mode on"
    remote "$PHP artisan down --retry=30 || true"
    trap 'echo "Deploy failed: the site is still in maintenance mode. Fix it, then run: ssh $HOST \"cd $APP && $PHP artisan up\""' ERR
fi

step "Application files -> $APP"
"${RSYNC[@]}" "${APP_EXCLUDES[@]}" "${APP_ENTRIES[@]}" "$HOST:$APP/"

step "Web root -> $WEB"
"${RSYNC[@]}" "${WEB_EXCLUDES[@]}" public/ "$HOST:$WEB/"

if [ "$APPLY" = 0 ]; then
    step "Dry run only. Run again with --apply to deploy."
    exit 0
fi

if [ "$LOCK_CHANGED" = 1 ]; then
    step "composer install --no-dev"
    remote "$PHP \$(command -v composer) install --no-dev --no-interaction --prefer-dist --optimize-autoloader"
fi

step "Migrate, cache, restart workers"
remote "$PHP artisan migrate --force && $PHP artisan optimize && $PHP artisan queue:restart"

step "Maintenance mode off"
remote "$PHP artisan up"
trap - ERR

step "Smoke test"
curl -s -o /dev/null -w 'GET / -> %{http_code}\n' "$(remote "$PHP artisan tinker --execute='echo config(\"app.url\");'")/"
