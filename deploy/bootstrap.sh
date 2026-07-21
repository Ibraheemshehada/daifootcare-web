#!/usr/bin/env bash
#
# DiaFootCare — server bootstrap.
#
# Run this ON THE VPS, as a user with sudo, from the cloned repo:
#
#     sudo bash deploy/bootstrap.sh
#
# It does the parts of DEPLOYMENT.md §3 and §8 that are pure shell: the app,
# a fresh APP_KEY, migrations, the dashboard build, and the Python inference
# sidecar under systemd.
#
# CLOUDPANEL
# ----------
# CloudPanel installs and owns nginx, MySQL and PHP-FPM. This script detects it
# and stays out of the way: it will NOT apt-install a second MySQL beside
# CloudPanel's, which is how a working control panel gets broken. On a
# CloudPanel server, create the site and the database in the panel UI first —
# that is also the part a browser can do.
#
# It deliberately does NOT do the two steps that need judgement: the 208 MB
# model upload (§2), and the nginx vhost (§4, still the only untested part).
#
# Safe to re-run. Every step checks before acting, because the realistic use is
# running it, hitting one failure, fixing that, and running it again.
#
# It prompts for the database password rather than taking it as an argument: an
# argument sits in your shell history and in `ps` output while it runs.

set -euo pipefail

APP_DIR="${APP_DIR:-$(pwd)}"
DB_NAME="${DB_NAME:-diafootcare}"
DB_USER="${DB_USER:-diafootcare}"
PHP_V="${PHP_V:-8.3}"

say()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '    \033[1;32m[ok]\033[0m %s\n' "$*"; }
warn() { printf '    \033[1;33m[!]\033[0m  %s\n' "$*"; }
die()  { printf '\n\033[1;31mFAILED:\033[0m %s\n' "$*" >&2; exit 1; }

[ -f "$APP_DIR/artisan" ] \
    || die "No artisan in $APP_DIR. Run from the repo root, or set APP_DIR."

CLOUDPANEL=0
if [ -d /home/clp ] || command -v clpctl >/dev/null 2>&1; then
    CLOUDPANEL=1
fi

# ---------------------------------------------------------------- packages ---
say "Packages"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

if [ "$CLOUDPANEL" = 1 ]; then
    ok "CloudPanel detected — leaving nginx, MySQL and PHP to it"
    apt-get install -y -qq git curl unzip python3-venv python3-pip
    command -v composer >/dev/null 2>&1 || apt-get install -y -qq composer
else
    apt-get install -y -qq nginx git curl unzip \
        "php${PHP_V}-fpm" "php${PHP_V}-mbstring" "php${PHP_V}-xml" \
        "php${PHP_V}-curl" "php${PHP_V}-zip" "php${PHP_V}-mysql" \
        "php${PHP_V}-bcmath" composer mysql-server python3-venv python3-pip
fi
ok "apt packages"

# The dashboard is built here — public/build is gitignored — and Ubuntu's
# packaged node is too old for Vite 7.
if ! command -v node >/dev/null 2>&1 || [ "$(node -v | cut -c2-3)" -lt 20 ]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null 2>&1
    apt-get install -y -qq nodejs
fi
ok "node $(node -v)"

# ---------------------------------------------------------------- database ---
say "Database"

DB_PASS=""
if mysql -e "USE ${DB_NAME}" 2>/dev/null; then
    ok "database ${DB_NAME} exists"
elif [ "$CLOUDPANEL" = 1 ]; then
    warn "CloudPanel owns MySQL and '${DB_NAME}' does not exist yet."
    warn "Create it in the panel: Databases -> Add Database."
    warn "Then put its name, user and password into .env and re-run this."
    warn "Creating it here would bypass the panel's own record of what exists."
else
    # utf8mb4, not utf8. Arabic survives either, but a 4-byte emoji — which the
    # app allows in appointment notes — is rejected by 3-byte utf8 with an error
    # that blames the column rather than the charset.
    read -rsp "    New password for MySQL user '${DB_USER}': " DB_PASS; echo
    [ -n "$DB_PASS" ] || die "Empty password."
    mysql <<SQL
CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
    ok "created ${DB_NAME} (utf8mb4), user scoped to that database only"
fi

# ------------------------------------------------------------- application ---
say "Application"

cd "$APP_DIR"
composer install --no-dev --optimize-autoloader --quiet
ok "composer"

if [ ! -f .env ]; then
    cp .env.example .env
    [ -n "$DB_PASS" ] && sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env
    sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
    ok ".env created from .env.example"
    warn "Set APP_URL to your real https:// domain, and the DB_* values, in .env"
else
    ok ".env present, left alone"
fi

# Always regenerate. An APP_KEY from this repo's history was published once, and
# reusing it would make every session cookie and encrypted value forgeable.
php artisan key:generate --force
ok "APP_KEY generated fresh on this server"

if php artisan migrate --force 2>/dev/null; then
    ok "migrations"
else
    warn "Migrations failed — almost always the DB_* values in .env."
    warn "Fix those and re-run this script."
fi

npm ci --silent && npm run build --silent
ok "dashboard built"

php artisan config:cache && php artisan route:cache
# CloudPanel runs php-fpm as the SITE user. Chowning to www-data gave a 500
# with no log line to explain it, because the log itself was unwritable.
OWNER=www-data
if [ "$CLOUDPANEL" = 1 ]; then
    OWNER=$(stat -c '%U' "$APP_DIR")
fi
chown -R "${OWNER}:${OWNER}" storage bootstrap/cache 2>/dev/null || true
ok "caches warmed, owned by ${OWNER}"

# --------------------------------------------------------- CloudPanel vhost ---
if [ "$CLOUDPANEL" = 1 ]; then
    say "CloudPanel vhost"
    SITE_OWNER=$(stat -c '%U' "$APP_DIR")
    DOMAIN_GUESS=$(basename "$APP_DIR")
    VHOST="/etc/nginx/sites-enabled/${DOMAIN_GUESS}.conf"

    if [ -f "$VHOST" ]; then
        # CloudPanel points the root at the site directory; Laravel serves from
        # public/, and everything above it — .env, vendor, storage — must never
        # be reachable over HTTP.
        if grep -q "root ${APP_DIR};" "$VHOST"; then
            cp "$VHOST" "${VHOST}.bak.$(date +%s)"
            sed -i "s#root ${APP_DIR};#root ${APP_DIR}/public;#g" "$VHOST"
            ok "vhost root moved to public/"
        else
            ok "vhost root already correct"
        fi

        # Let's Encrypt writes its challenge to the site root, which nginx no
        # longer serves once the root is public/. The symlink keeps both true.
        mkdir -p "${APP_DIR}/.well-known"
        ln -sfn "${APP_DIR}/.well-known" "${APP_DIR}/public/.well-known"
        chown -h "${SITE_OWNER}:${SITE_OWNER}" "${APP_DIR}/public/.well-known"
        chown -R "${SITE_OWNER}:${SITE_OWNER}" "${APP_DIR}/.well-known"
        ok "acme-challenge path linked into public/"

        nginx -t >/dev/null 2>&1 && systemctl reload nginx && ok "nginx reloaded"
    else
        warn "No vhost at $VHOST — create the site in CloudPanel first."
    fi
fi

# ------------------------------------------------------- inference sidecar ---
say "Inference sidecar"

cd "$APP_DIR/inference"
[ -d .venv ] || python3 -m venv .venv
# ai-edge-litert, not full tensorflow: 629 MB resident vs ~960 MB measured, for
# the same four models.
./.venv/bin/pip install -q --upgrade pip
./.venv/bin/pip install -q fastapi "uvicorn[standard]" python-multipart \
                           numpy opencv-python-headless pillow ai-edge-litert
ok "python deps (LiteRT, not full TensorFlow)"

# Must be the user that owns the files, or systemd fails with CHDIR denied.
SVC_USER=www-data
[ "$CLOUDPANEL" = 1 ] && SVC_USER=$(stat -c '%U' "$APP_DIR")

cat > /etc/systemd/system/dfc-inference.service <<UNIT
[Unit]
Description=DiaFootCare inference sidecar
After=network.target

[Service]
User=${SVC_USER}
WorkingDirectory=${APP_DIR}/inference
Environment=DFC_MODELS_DIR=${APP_DIR}/storage/app/models
# 127.0.0.1 is load-bearing. This service runs the models and authenticates
# nobody; Laravel is its only client and does the authorising. Never 0.0.0.0.
ExecStart=${APP_DIR}/inference/.venv/bin/uvicorn server:app \\
          --host 127.0.0.1 --port 8500 --workers 1
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable dfc-inference >/dev/null 2>&1 || true
systemctl restart dfc-inference || warn "sidecar did not start — journalctl -u dfc-inference -n 50"
ok "systemd unit installed"

# ------------------------------------------------------------------ checks ---
say "Verifying"

MODELS_DIR="$APP_DIR/storage/app/models"
COUNT=$(find "$MODELS_DIR" -maxdepth 1 -name '*.tflite' 2>/dev/null | wc -l)

if [ "$COUNT" -lt 4 ]; then
    warn "${COUNT}/4 .tflite files in ${MODELS_DIR}"
    warn "Upload them (DEPLOYMENT.md §2). Until then the sidecar cannot load,"
    warn "and offline-mode phones have nothing to download."
else
    ok "${COUNT} model files present"
    for i in $(seq 1 30); do
        if curl -sf -m 3 http://127.0.0.1:8500/health >/dev/null 2>&1; then
            ok "sidecar answering on 127.0.0.1:8500"
            break
        fi
        [ "$i" = 30 ] && warn "sidecar not up — journalctl -u dfc-inference -n 50"
        sleep 2
    done
fi

cat <<'NEXT'

────────────────────────────────────────────────────────────────────────
Shell work done. Two steps remain, both needing a human:

1. MODEL FILES (DEPLOYMENT.md §2), if warned above. Upload, then compare
   sha256sum against your local copies. A truncated 175 MB upload becomes a
   checksum failure on every patient's phone, debugged from the wrong end.

2. NGINX (DEPLOYMENT.md §4) — the only untested part of this deployment.
   The model files must be served by nginx directly, never through PHP.
   On CloudPanel this goes in the site's Vhost Editor, not a hand-written
   file: the panel validates the syntax and reverts a broken config.

   Then, from anywhere:

     curl -sI -r 0-99 https://YOUR-DOMAIN/api/v1/models/file/tissue_head.tflite

   It must return 206, not 200. A 200 means ranges are not working and every
   resumed download silently refetches from zero — the app looks fine while
   burning a patient's data allowance.

Only then rebuild the APK, because API_BASE_URL is compiled in:

  flutter build apk --release \
    --dart-define=API_BASE_URL=https://YOUR-DOMAIN/api/v1
────────────────────────────────────────────────────────────────────────
NEXT
