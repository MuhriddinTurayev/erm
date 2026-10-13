#!/usr/bin/env bash
# Oftob_Sohtumon — установка на сервер с Docker (Ubuntu). Запуск от root:
#   curl -fsSL https://raw.githubusercontent.com/MuhriddinTurayev/erm/main/deploy/install.sh | bash
# Параметры (необязательно): DOMAIN=erp.example.uz  PORT=8090  DIR=/opt/oftob  DEMO=1 (демо-компании для проверки)
set -euo pipefail

REPO="${REPO:-https://github.com/MuhriddinTurayev/erm.git}"
DIR="${DIR:-/opt/oftob}"
PORT="${PORT:-8090}"
DOMAIN="${DOMAIN:-}"

say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mОшибка: %s\033[0m\n' "$*" >&2; exit 1; }

command -v docker >/dev/null || die "Docker не установлен"
if docker compose version >/dev/null 2>&1; then DC="docker compose -p oftob"; elif command -v docker-compose >/dev/null; then DC="docker-compose -p oftob"; else die "Нет docker compose (плагин compose)"; fi
command -v git >/dev/null || { say "Ставлю git"; apt-get update -qq && apt-get install -y -qq git; }
command -v openssl >/dev/null || { apt-get update -qq && apt-get install -y -qq openssl; }

# 1) код программы
if [ -d "$DIR/.git" ]; then
    say "Обновляю код в $DIR"
    git -C "$DIR" pull --ff-only
else
    say "Скачиваю программу в $DIR"
    git clone --depth 1 "$REPO" "$DIR"
fi
cd "$DIR"

# 2) свободный порт (если 8090 занят другим проектом — берём следующий)
port_busy() { ss -ltnH 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]$1\$"; }
if [ ! -f .env ]; then
    while port_busy "$PORT"; do PORT=$((PORT + 1)); done
fi

# 3) настройки: секреты генерируются один раз и хранятся только на сервере
if [ ! -f .env ]; then
    say "Создаю настройки (.env) со случайными паролями"
    # внешний IP сервера: адрес, с которого сервер выходит в интернет (не адрес сети Docker)
    IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1)}' | head -1)"
    [ -n "$IP" ] || IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
    if [ -n "$DOMAIN" ]; then URL="https://$DOMAIN"; BIND="127.0.0.1"; SECURE=true; else URL="http://$IP:$PORT"; BIND="0.0.0.0"; SECURE=false; fi
    cat > .env <<EOF
# Oftob_Sohtumon — настройки сервера (не публиковать!)
APP_NAME=Oftob_Sohtumon
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=$URL
APP_LOCALE=uz
APP_FALLBACK_LOCALE=ru
APP_FAKER_LOCALE=ru_RU
# первый вход: откройте $URL/setup?key=<SETUP_KEY> и создайте владельца платформы
SETUP_KEY=$(openssl rand -hex 12)

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=oftob
DB_USERNAME=oftob
DB_PASSWORD=$(openssl rand -hex 16)
DB_ROOT_PASSWORD=$(openssl rand -hex 20)

SESSION_DRIVER=database
SESSION_LIFETIME=720
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=$SECURE
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log
MAIL_MAILER=log
META_GRAPH_VERSION=v23.0

# порт на сервере (за прокси с доменом — только 127.0.0.1)
OFTOB_PORT=$PORT
OFTOB_BIND=$BIND
EOF
    chmod 600 .env
fi

# 4) сборка и запуск
say "Собираю и запускаю контейнеры (первый раз — 3–6 минут)"
$DC up -d --build

say "Жду, пока программа запустится"
for i in $(seq 1 90); do
    st="$(docker inspect -f '{{.State.Health.Status}}' oftob-app 2>/dev/null || echo starting)"
    [ "$st" = "healthy" ] && break
    if [ "$i" = 90 ]; then $DC logs --tail 80 app; die "Программа не запустилась — журнал выше"; fi
    sleep 4
done

if [ "${DEMO:-0}" = "1" ]; then
    say "Создаю демо-компании (пароль у всех Demo2026!)"
    $DC exec -T app artisan oftob:demo --no-interaction || true
fi

# 5) владелец платформы: если его ещё нет — создаём с надёжным паролем (показывается один раз)
set -a; . ./.env; set +a
OWNERS="$($DC exec -T db sh -c 'mysql -N -s -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "select count(*) from users where role=\"superadmin\"" 2>/dev/null' | tr -d '\r' || true)"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@oftob.uz}"
ADMIN_PASS=""
if [ "$OWNERS" = "0" ]; then
    ADMIN_PASS="$(openssl rand -base64 15 | tr -d '/+=\n')"
    if ! $DC exec -T app artisan oftob:user "$ADMIN_EMAIL" "$ADMIN_PASS" --role=superadmin --name="Владелец платформы" >/dev/null; then
        ADMIN_PASS=""
        echo "  Владельца создать не удалось — откройте $APP_URL/setup?key=$SETUP_KEY (если пользователей нет) или выполните:"
        echo "  cd $DIR && $DC exec app artisan oftob:user email пароль --role=superadmin"
    fi
fi

# 6) готово
say "Готово!"
echo "  Программа:          $APP_URL"
if [ -n "$ADMIN_PASS" ]; then
    echo "  Владелец платформы: $ADMIN_EMAIL"
    echo "  Пароль:             $ADMIN_PASS   (сохраните; сменить — в разделе «Безопасность»)"
fi
if [ "${DEMO:-0}" = "1" ]; then
    echo "  Демо-входы:         direktor@navruz.demo, direktor@mosgrad.demo, direktor@pamir.demo — пароль Demo2026!"
fi
echo "  Каталог:            $DIR   (настройки — $DIR/.env)"
echo "  Обновление:         bash $DIR/deploy/update.sh"
echo "  Резервная копия:    bash $DIR/deploy/backup.sh"
echo "  Журнал:             cd $DIR && $DC logs -f app"
