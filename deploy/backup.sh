#!/usr/bin/env bash
# Oftob_Sohtumon — резервная копия: база MySQL + файлы компаний (3D, чеки, фото). Хранятся последние 14 копий.
#   bash /opt/oftob/deploy/backup.sh
# Каждую ночь в 03:00:   (crontab -l 2>/dev/null; echo "0 3 * * * bash /opt/oftob/deploy/backup.sh >/dev/null 2>&1") | crontab -
set -euo pipefail
DIR="${DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
OUT="${BACKUP_DIR:-/opt/oftob-backups}"
KEEP="${KEEP:-14}"
cd "$DIR"
mkdir -p "$OUT"
chmod 700 "$OUT"
TS="$(date +%Y%m%d-%H%M%S)"

# база: дамп изнутри контейнера MySQL (пароль не попадает в командную строку сервера)
docker exec oftob-db sh -c 'exec mysqldump --single-transaction --quick --routines -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' 2>/dev/null | gzip > "$OUT/db-$TS.sql.gz"

# файлы: том storage (3D-модели, чеки, фото, вложения) и картинки сайта
docker run --rm -v oftob_storage:/s:ro -v oftob_uploads:/u:ro -v "$OUT":/b alpine sh -c "tar czf /b/files-$TS.tar.gz -C / s u"

# старые копии — удаляем
ls -1t "$OUT"/db-*.sql.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | xargs -r rm -f
ls -1t "$OUT"/files-*.tar.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | xargs -r rm -f

echo "Копия: $OUT/db-$TS.sql.gz и $OUT/files-$TS.tar.gz"
