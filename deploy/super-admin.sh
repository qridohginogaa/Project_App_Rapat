#!/usr/bin/env bash
# Membuat Super Admin pertama di database container:
#   bash /opt/rapat-deploy/super-admin.sh <NIP 18 digit> "Nama" ["Jabatan"]
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "$0")" && pwd)"
docker compose --project-directory "$DEPLOY_DIR/docker" -f "$DEPLOY_DIR/docker/compose.yaml" \
    run --rm --no-deps --user www-data -v "$DEPLOY_DIR/buat-super-admin.php:/tmp/buat-super-admin.php:ro" \
    app php /tmp/buat-super-admin.php "$@"
