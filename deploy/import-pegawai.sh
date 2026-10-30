#!/usr/bin/env bash
# Impor/perbarui pegawai dari CSV (header: nip,nama,jabatan) ke database container:
#   bash /opt/rapat-deploy/import-pegawai.sh /root/pegawai.csv
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "$0")" && pwd)"
csv="${1:?Pemakaian: import-pegawai.sh <file.csv>}"
[ -r "$csv" ] || { echo "File tidak terbaca: $csv" >&2; exit 1; }

docker compose --project-directory "$DEPLOY_DIR/docker" -f "$DEPLOY_DIR/docker/compose.yaml" \
    run --rm -T --no-deps --user www-data -v "$DEPLOY_DIR/import-pegawai.php:/tmp/import-pegawai.php:ro" \
    app php /tmp/import-pegawai.php < "$csv"
