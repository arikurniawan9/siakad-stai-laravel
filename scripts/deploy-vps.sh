#!/usr/bin/env bash

set -Eeuo pipefail

# Run a temporary copy so updating this tracked script during git reset is safe.
if [[ "${SIAKAD_DEPLOY_REEXEC:-0}" != "1" ]]; then
    app_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
    temporary_script="$(mktemp "${TMPDIR:-/tmp}/siakad-deploy.XXXXXX")"
    cp -- "${BASH_SOURCE[0]}" "$temporary_script"
    chmod 700 "$temporary_script"

    SIAKAD_DEPLOY_REEXEC=1 \
    SIAKAD_DEPLOY_APP_DIR="$app_dir" \
    SIAKAD_DEPLOY_TEMP_SCRIPT="$temporary_script" \
        exec "$temporary_script" "$@"
fi

APP_DIR="${SIAKAD_DEPLOY_APP_DIR:?Application directory is not available}"
TEMPORARY_SCRIPT="${SIAKAD_DEPLOY_TEMP_SCRIPT:-}"
TARGET_COMMIT="${1:-origin/master}"
MAINTENANCE_ENABLED=0
WAS_ALREADY_IN_MAINTENANCE=0

finish() {
    status=$?

    if [[ "$status" -ne 0 ]]; then
        echo "Deployment failed with exit code $status." >&2

        if [[ "$MAINTENANCE_ENABLED" -eq 1 && -f "$APP_DIR/artisan" ]]; then
            (cd "$APP_DIR" && php artisan up) || true
        fi
    fi

    if [[ -n "$TEMPORARY_SCRIPT" && -f "$TEMPORARY_SCRIPT" ]]; then
        rm -f -- "$TEMPORARY_SCRIPT"
    fi
}

trap finish EXIT

cd "$APP_DIR"

if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
    export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"

    if [[ -s "$NVM_DIR/nvm.sh" ]]; then
        # NVM is not loaded automatically by non-interactive SSH sessions.
        # shellcheck disable=SC1091
        source "$NVM_DIR/nvm.sh"
    fi
fi

for command in git php composer npm flock; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Required command is not installed: $command" >&2
        exit 1
    fi
done

if [[ ! -f .env ]]; then
    echo "Production .env is missing in $APP_DIR." >&2
    exit 1
fi

mkdir -p storage/framework
exec 9>storage/framework/deploy.lock

if ! flock -n 9; then
    echo "Another deployment is already running." >&2
    exit 1
fi

echo "Fetching $TARGET_COMMIT from GitHub..."
git fetch --prune origin master

if ! git rev-parse --verify --quiet "${TARGET_COMMIT}^{commit}" >/dev/null; then
    echo "Commit does not exist after fetch: $TARGET_COMMIT" >&2
    exit 1
fi

if ! git merge-base --is-ancestor "$TARGET_COMMIT" origin/master; then
    echo "Refusing to deploy a commit outside origin/master: $TARGET_COMMIT" >&2
    exit 1
fi

if [[ -f storage/framework/down ]]; then
    WAS_ALREADY_IN_MAINTENANCE=1
fi

if [[ -f vendor/autoload.php && "$WAS_ALREADY_IN_MAINTENANCE" -eq 0 ]]; then
    php artisan down --retry=60
    MAINTENANCE_ENABLED=1
fi

echo "Checking out $TARGET_COMMIT..."
git reset --hard "$TARGET_COMMIT"

echo "Installing production PHP dependencies..."
composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

echo "Building frontend assets..."
npm ci --no-audit --no-fund
npm run build

# The web server may own files created during runtime (sessions, cache, logs).
# The shared group permissions are provisioned once on the VPS; deployments
# must not fail merely because the deploy user is not the owner of one file.
find storage bootstrap/cache -type d -exec chmod ug+rwx {} + 2>/dev/null || true

echo "Clearing stale caches and applying migrations..."
php artisan optimize:clear
php artisan migrate --force

if [[ ! -e public/storage ]]; then
    php artisan storage:link
fi

echo "Caching the production application..."
php artisan optimize
php artisan queue:restart

if [[ "$MAINTENANCE_ENABLED" -eq 1 ]]; then
    php artisan up
    MAINTENANCE_ENABLED=0
fi

echo "Deployment of $TARGET_COMMIT completed successfully."
