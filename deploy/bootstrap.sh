#!/usr/bin/env bash
#
# DiaFootCare — one-shot server bootstrap.
#
# Run this ON THE VPS, as a user with sudo, from the cloned repo:
#
#     cd /var/www/diafootcare
#     sudo bash deploy/bootstrap.sh
#
# It does everything in DEPLOYMENT.md §3 and §8 that is pure shell: packages,
# database, application, dashboard build, and the inference sidecar under
# systemd. It deliberately does NOT do the two things that need a human
# decision — the nginx vhost (§4, and the only untested part of the
# deployment) and the 208 MB model upload (§2).
#
# Safe to re-run. Every step checks before it acts, because the realistic use is
# running it, hitting one failure, fixing that, and running it again.
#
# It asks for the database password rather than taking it on the command line:
# an argument would land in your shell history and in `ps` output while it runs.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/diafootcare}"
DB_NAME="${DB_NAME:-diafootcare}"
DB_USER="${DB_USER:-diafootcare}"
PHP_V="${PHP_V:-8.3}"

say()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '    \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31mFAILED:\033[0m %s\n' "$*" >&2; exit 1; }

[ -f "$APP_DIR/artisan" ] || die "No artisan in $APP_DIR. Clone the repo there first, or set APP_DIR."

# ---------------------------------------------------------------- packages ---
say "Installing packages"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

apt-get install -y -qq nginx git curl unzip \
    "php${PHP_V}-fpm" "php${PHP_V}-mbstring" "php${PHP_V}-xml" "php${PHP_V}-curl" \
    "php${PHP_V}-zip" "php${PHP_V}-mysql" "php${PHP_V}-bcmath" \
    composer mysql-server python3-venv python3-pip
ok "apt packages"

# Node from NodeSource: the dashboard is built here (public/build is gitignored)
# and Ubuntu's packaged node is too old for Vite 7.
if ! command -v node >/dev/null 2>&1 || [ "$(node -v | cut -c2-3)" -lt 20 ]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y -qq nodejs
fi
ok "node $(node -v)"

# ---------------------------------------------------------------- database ---
say "Database"

if mysql -e "USE ${DB_NAME}" 2>/dev/null; then
    ok "database ${DB_NAME} already exists"
    DB_PASS=""
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
    ok "created ${DB_NAME} (utf8mb4) and user ${DB_USER}, scoped to that database only"
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
    warn "Set APP_URL in .env to your real https:// domain before going live."
else
    ok ".env already present, left alone"
fi

# Always regenerate: an APP_KEY from this repo's history was published once, and
# reusing it would mean every session cookie and encrypted value is forgeable.
php artisan key:generate --force
ok "APP_KEY generated fresh on this server"

php artisan migrate --force
ok "migrations"

npm ci --silent && npm run build --silent
ok "dashboard built"

php artisan config:cache && php artisan route:cache
chown -R www-data:www-data storage bootstrap/cache
ok "caches warmed, permissions set"

# ------------------------------------------------------- inference sidecar ---
say "Inference sidecar"

cd "$APP_DIR/inference"
[ -d .venv ] || python3 -m venv .venv
# ai-edge-litert rather than full tensorflow: 629 MB resident vs ~960 MB
# measured, for the same four models.
./.venv/bin/pip install -q --upgrade pip
./.venv/bin/pip install -q fastapi "uvicorn[standard]" python-multipart \
                           numpy opencv-python-headless pillow ai-edge-litert
ok "python deps (LiteRT, not full TensorFlow)"

cat > /etc/systemd/system/dfc-inference.service <<UNIT
[Unit]
Description=DiaFootCare inference sidecar
After=network.target

[Service]
User=www-data
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
systemctl enable --now dfc-inference >/dev/null 2>&1 || true
systemctl restart dfc-inference
ok "systemd unit installed and started"

# ------------------------------------------------------------------ checks ---
say "Verifying"

MODELS_DIR="$APP_DIR/storage/app/models"
COUNT=$(ls -1 "$MODELS_DIR" 2>/dev/null | grep -c '\.tflite$' || true)

if [ "$COUNT" -lt 4 ]; then
    warn "Only ${COUNT}/4 .tflite files in ${MODELS_DIR}."
    warn "Upload them (DEPLOYMENT.md §2) — until then the sidecar cannot load,"
    warn "and offline-mode phones have nothing to download."
else
    ok "${COUNT} model files present"
    for i in $(seq 1 30); do
        if curl -sf -m 3 http://127.0.0.1:8500/health >/dev/null 2>&1; then
            ok "sidecar answering on 127.0.0.1:8500"
            break
        fi
        [ "$i" = 30 ] && warn "sidecar not up yet — journalctl -u dfc-inference -n 50"
        sleep 2
    done
fi

cat <<'NEXT'

────────────────────────────────────────────────────────────────────────
Done with everything that is pure shell. Two steps remain, both by hand:

1. THE MODEL FILES (DEPLOYMENT.md §2) if the warning above appeared.
   Upload, then compare sha256sum against your local copies. A truncated
   175 MB upload becomes a checksum failure on every patient's phone, and
   you would debug it from the wrong end.

2. NGINX (DEPLOYMENT.md §4) — the only untested part of this deployment.
   The model files must be served by nginx directly, never through PHP.
   After reloading, run the curl checks at the end of that section:

     curl -sI -r 0-99 https://YOUR-DOMAIN/api/v1/models/file/tissue_head.tflite

   It must return 206, not 200. A 200 means ranges are not working and
   every resumed download silently refetches from zero — the app will look
   fine while wasting a patient's data allowance.

Then, and only then, rebuild the APK with the real domain:

  flutter build apk --release --dart-define=API_BASE_URL=https://YOUR-DOMAIN/api/v1
────────────────────────────────────────────────────────────────────────
NEXT
