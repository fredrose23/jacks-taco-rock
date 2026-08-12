#!/usr/bin/env bash
#
# Respaldo automático de Jacks Taco Rock.
#   - Base de datos (mysqldump comprimido)
#   - Imágenes de productos subidas (assets/img/products)
#   - config real (con la contraseña) — para poder restaurar
#
# El CÓDIGO no se respalda aquí porque ya vive en git/GitHub.
# Rota los respaldos: conserva los últimos $KEEP.
#
# Uso:   deploy/backup.sh
# Cron:  0 3 * * *  /var/www/html/jacks-rock/deploy/backup.sh >> /home/jacks/jacks-rock-backups/backup.log 2>&1

set -euo pipefail

APP_DIR="/var/www/html/jacks-rock"
BK_DIR="/home/jacks/jacks-rock-backups"
KEEP=14                    # cuántos respaldos diarios conservar
STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BK_DIR"

# ── Leer credenciales de la BD desde config.php (sin ejecutarlo) ──
cfg="$APP_DIR/config/config.php"
DB_NAME=$(grep -oP "define\('DB_NAME',\s*'\K[^']+" "$cfg")
DB_USER=$(grep -oP "define\('DB_USER',\s*'\K[^']+" "$cfg")
DB_PASS=$(grep -oP "define\('DB_PASS',\s*'\K[^']+" "$cfg")
DB_HOST=$(grep -oP "define\('DB_HOST',\s*'\K[^']+" "$cfg")

# Archivo temporal de credenciales (para no pasar la contraseña por línea de comando)
CNF="$(mktemp)"
chmod 600 "$CNF"
printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_USER" "$DB_PASS" > "$CNF"
trap 'rm -f "$CNF"' EXIT

# ── 1) Base de datos ──
echo "[$(date '+%F %T')] Respaldando BD $DB_NAME…"
mysqldump --defaults-extra-file="$CNF" --single-transaction --no-tablespaces --routines --triggers "$DB_NAME" \
  | gzip > "$BK_DIR/db-$STAMP.sql.gz"

# ── 2) Imágenes subidas + config real ──
echo "[$(date '+%F %T')] Respaldando imágenes y config…"
tar -czf "$BK_DIR/data-$STAMP.tar.gz" -C "$APP_DIR" \
  assets/img/products \
  config/config.php \
  2>/dev/null || true

# ── 3) Rotación: conservar solo los últimos $KEEP de cada tipo ──
for prefix in db data; do
  ls -1t "$BK_DIR/$prefix"-*.gz 2>/dev/null | tail -n +$((KEEP+1)) | xargs -r rm -f
done

echo "[$(date '+%F %T')] ✔ Respaldo listo:"
echo "   $BK_DIR/db-$STAMP.sql.gz  ($(du -h "$BK_DIR/db-$STAMP.sql.gz" | cut -f1))"
echo "   $BK_DIR/data-$STAMP.tar.gz ($(du -h "$BK_DIR/data-$STAMP.tar.gz" | cut -f1))"
