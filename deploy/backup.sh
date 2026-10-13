#!/usr/bin/env bash
# Oftob_Sohtumon — zaxira nusxa: MySQL bazasi + kompaniyalar fayllari (3D, cheklar, fotolar) va sayt rasmlari.
# Oxirgi 14 tasi saqlanadi (/opt/oftob-backups).
#   bash /opt/oftob/deploy/backup.sh
# Har kecha 03:00 da:  (crontab -l 2>/dev/null; echo "0 3 * * * bash /opt/oftob/deploy/backup.sh >/dev/null 2>&1") | crontab -
#
# Tiklash (faqat kerak bo'lsa):
#   gunzip -c /opt/oftob-backups/db-YYYYMMDD-HHMMSS.sql.gz | docker exec -i oftob-db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
set -euo pipefail
OUT="${BACKUP_DIR:-/opt/oftob-backups}"
KEEP="${KEEP:-14}"
mkdir -p "$OUT"
chmod 700 "$OUT"
TS="$(date +%Y%m%d-%H%M%S)"
TMP="$OUT/.db-$TS.sql.gz"

# baza: dump MySQL konteyneri ichida olinadi (parol server buyruq qatoriga chiqmaydi)
if ! docker exec oftob-db sh -c 'exec mysqldump --single-transaction --quick --routines --no-tablespaces --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' 2>/dev/null </dev/null | gzip > "$TMP"; then
    rm -f "$TMP"
    echo "XATO: bazaning nusxasi olinmadi" >&2
    exit 1
fi
# to'liqlik: mysqldump oxirida "Dump completed" yozadi
if ! gzip -dc "$TMP" | tail -n 1 | grep -q 'Dump completed'; then
    rm -f "$TMP"
    echo "XATO: bazaning nusxasi to'liq emas" >&2
    exit 1
fi
mv -f "$TMP" "$OUT/db-$TS.sql.gz"

# fayllar: storage (3D-modellar, cheklar, fotolar, ilovalar) va sayt rasmlari; kesh, sessiyalar va jurnallarsiz.
# Dasturning o'z image'idan foydalanamiz — qo'shimcha image yuklab olinmaydi.
docker run --rm --entrypoint tar -v oftob_storage:/s:ro -v oftob_uploads:/u:ro -v "$OUT":/b oftob-app:latest \
    czf "/b/files-$TS.tar.gz" --exclude=s/framework --exclude=s/logs -C / s u </dev/null
chmod 600 "$OUT/db-$TS.sql.gz" "$OUT/files-$TS.tar.gz"

# eski nusxalar o'chiriladi
ls -1t "$OUT"/db-*.sql.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | xargs -r rm -f
ls -1t "$OUT"/files-*.tar.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | xargs -r rm -f

echo "Nusxa: $OUT/db-$TS.sql.gz ($(du -h "$OUT/db-$TS.sql.gz" | cut -f1)) va $OUT/files-$TS.tar.gz ($(du -h "$OUT/files-$TS.tar.gz" | cut -f1))"
