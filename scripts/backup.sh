#!/usr/bin/env bash
# Respaldo diario del ERP B2: base de datos (mysqldump) + documentos subidos (storage/app).
# Uso: scripts/backup.sh        (cron lo corre cada noche; conserva solo el último respaldo)
set -euo pipefail
umask 077

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${BACKUP_DIR:-/home/b2admin/backups}"
FECHA="$(date +%F_%H%M)"

mkdir -p "$DEST"

# La contraseña se lee dentro del contenedor, nunca queda en este script ni en el historial.
docker exec erp-b2-mysql-1 sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces "$MYSQL_DATABASE"' \
    | gzip > "$DEST/erp_b2_db_$FECHA.sql.gz"

tar czf "$DEST/erp_b2_archivos_$FECHA.tar.gz" -C "$APP_DIR/storage/app" .

# Un respaldo vacío o corrupto no sirve: se valida antes de borrar los antiguos.
gzip -t "$DEST/erp_b2_db_$FECHA.sql.gz"
tar tzf "$DEST/erp_b2_archivos_$FECHA.tar.gz" > /dev/null
[ "$(stat -c %s "$DEST/erp_b2_db_$FECHA.sql.gz")" -gt 1024 ]

# Solo después de validar el nuevo, se borran los respaldos anteriores.
find "$DEST" -maxdepth 1 -type f \( -name 'erp_b2_db_*.sql.gz' -o -name 'erp_b2_archivos_*.tar.gz' \) \
    ! -name "erp_b2_db_$FECHA.sql.gz" ! -name "erp_b2_archivos_$FECHA.tar.gz" -delete

echo "$(date '+%F %T') respaldo OK: erp_b2_db_$FECHA.sql.gz, erp_b2_archivos_$FECHA.tar.gz"
