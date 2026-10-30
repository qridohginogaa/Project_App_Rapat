#!/usr/bin/env bash
# Deploy / update Dashboard Rapat di Docker (container app + web + db) pada VPS bersama.
# Nginx host hanya menjadi reverse proxy HTTPS ke 127.0.0.1:8100.
# Jalankan sebagai root; aman diulang, dan dipakai juga untuk update setelah ada commit baru:
#   bash /opt/rapat-deploy/deploy.sh
set -euo pipefail

DOMAIN="${DOMAIN:-meeting.poltekkes-manado.ac.id}"
REPO_URL="${REPO_URL:-https://github.com/qridohginogaa/Project_App_Rapat.git}"
SRC_DIR="${SRC_DIR:-/var/www/rapat}"
DEPLOY_DIR="$(cd "$(dirname "$0")" && pwd)"
DOCKER_DIR="$DEPLOY_DIR/docker"
WEB_PORT=8100

# Dockerfile sengaja di luar build context (clone Git) supaya git pull tetap bersih;
# compose/bake perlu izin membaca berkas di luar context.
export BUILDX_BAKE_ENTITLEMENTS_FS=0

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }
dc() { docker compose --project-directory "$DOCKER_DIR" -f "$DOCKER_DIR/compose.yaml" "$@"; }
artisan() { dc run --rm --no-deps --user www-data app php artisan "$@"; }
secret() { printf '%s-Aa9' "$(openssl rand -base64 33 | tr '+/' '_.')"; }

[ "$(id -u)" -eq 0 ] || die "Jalankan sebagai root."

log "Kode aplikasi"
if [ -d "$SRC_DIR/.git" ]; then
    git -C "$SRC_DIR" pull --ff-only
else
    git clone "$REPO_URL" "$SRC_DIR"
fi
chown -R 33:33 "$SRC_DIR/uploads"

if [ ! -f "$DOCKER_DIR/.env" ]; then
    log "Membuat $DOCKER_DIR/.env"
    (umask 077; cat > "$DOCKER_DIR/.env" <<ENV
APP_NAME="Dashboard Rapat Poltekkes Manado"
APP_ENV=production
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=https://$DOMAIN
APP_LOCALE=id
APP_FALLBACK_LOCALE=id
APP_FAKER_LOCALE=id_ID
APP_TIMEZONE=Asia/Makassar
BCRYPT_ROUNDS=12

LOG_CHANNEL=stderr
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=rapat_poltekkes
DB_USERNAME=rapat
DB_PASSWORD=$(secret)
DB_ROOT_PASSWORD=$(secret)

SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log
MAIL_MAILER=log
ENV
    )
fi

log "Build image"
dc build --pull

log "Database"
dc up -d --wait db
if dc ps --status running --services | grep -qx app; then
    mkdir -p /root/backups
    dc exec -T db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines "$MYSQL_DATABASE"' \
        | gzip > "/root/backups/rapat-$(date +%Y%m%d-%H%M%S).sql.gz"
fi
# Versi migrasi 2026_10_19_000000 di GitHub membuat ID UNSIGNED sehingga foreign key migrasi
# berikutnya gagal di database kosong. Jalankan versi yang diperbaiki (nama sama) bila belum pernah jalan.
if ! artisan migrate:status 2>/dev/null | grep -q '2026_10_19_000000_create_meeting_application_tables .*Ran'; then
    dc run --rm --no-deps --user www-data -v "$DEPLOY_DIR/migrations:/fix:ro" app \
        php artisan migrate --force --path=/fix --realpath
fi
artisan migrate --force
artisan db:seed --force

log "Menjalankan container"
dc up -d --remove-orphans --wait
dc ps

log "Nginx host -> 127.0.0.1:$WEB_PORT"
site="/etc/nginx/sites-available/$DOMAIN"
cert="/etc/letsencrypt/live/$DOMAIN"
[ -f "$cert/fullchain.pem" ] || die "Sertifikat $cert belum ada; terbitkan dulu: certbot --nginx -d $DOMAIN"
cat > "$site.new" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name $DOMAIN;

    ssl_certificate $cert/fullchain.pem;
    ssl_certificate_key $cert/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    client_max_body_size 110M;

    location / {
        proxy_pass http://127.0.0.1:$WEB_PORT;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 300;
        proxy_send_timeout 300;
    }
}
NGINX
if ! cmp -s "$site.new" "$site"; then
    [ -f "$site" ] && cp "$site" "/root/$(basename "$site").bak-$(date +%Y%m%d-%H%M%S)"
    mv "$site.new" "$site"
    ln -sf "$site" "/etc/nginx/sites-enabled/$DOMAIN"
    if ! nginx -t; then
        die "Konfigurasi Nginx tidak valid; cek $site (cadangan lama ada di /root)."
    fi
    systemctl reload nginx
else
    rm -f "$site.new"
fi

log "Cek"
curl -fsS -o /dev/null -w "https://$DOMAIN/up -> %{http_code}\n" "https://$DOMAIN/up"
echo "Update selesai: $(git -C "$SRC_DIR" log -1 --format='%h %s')"
