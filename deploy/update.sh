#!/usr/bin/env bash
# Oftob_Sohtumon — обновление на сервере: новый код с GitHub, пересборка, миграции (данные и файлы сохраняются в томах)
#   bash /opt/oftob/deploy/update.sh
set -euo pipefail
DIR="${DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$DIR"
if docker compose version >/dev/null 2>&1; then DC="docker compose -p oftob"; else DC="docker-compose -p oftob"; fi

echo "==> Резервная копия перед обновлением"
bash "$DIR/deploy/backup.sh" || echo "  (копия не создана — продолжаю)"

echo "==> Новый код"
git pull --ff-only

echo "==> Пересборка и перезапуск (миграции применяются при запуске контейнера)"
$DC up -d --build
docker image prune -f >/dev/null 2>&1 || true

for i in $(seq 1 90); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' oftob-app 2>/dev/null)" = "healthy" ] && { echo "==> Готово"; exit 0; }
    sleep 4
done
$DC logs --tail 80 app
echo "Программа не запустилась — журнал выше" >&2
exit 1
