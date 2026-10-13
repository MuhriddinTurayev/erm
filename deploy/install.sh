#!/usr/bin/env bash
# Oftob_Sohtumon — Docker o'rnatilgan serverga (Ubuntu) o'rnatish. root nomidan:
#   curl -fsSL https://raw.githubusercontent.com/MuhriddinTurayev/erm/main/deploy/install.sh | bash
# Parametrlar (ixtiyoriy, buyruq oldidan: ... | HTTPS=1 DEMO=1 bash):
#   HTTPS=1               — https://oftob.<ip>.sslip.io manzili: serverdagi nginx + Let's Encrypt (deploy/https.sh)
#   DOMAIN=erp.misol.uz   — o'z domeningiz bilan HTTPS (DNS'da A yozuvi server IP'siga yo'naltirilgan bo'lsin)
#   DEMO=1                — sinov uchun demo kompaniyalar (paroli Demo2026! — ochiq serverda ehtiyot bo'ling)
#   PORT=8090  DIR=/opt/oftob  ADMIN_EMAIL=admin@oftob.uz
# Qayta ishga tushirish xavfsiz: .env, baza va fayllar saqlanadi.
# Hammasi main() ichida: "curl | bash" rejimida bash skriptni oxirigacha o'qib olgandan keyin ishga tushadi.

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m    OGOHLANTIRISH: %s\033[0m\n' "$*" >&2; }
die()  { printf '\n\033[1;31mXATO: %s\033[0m\n' "$*" >&2; exit 1; }
env_get() { { grep -E "^$1=" .env 2>/dev/null || true; } | tail -n1 | cut -d= -f2- | tr -d "\"'"; }

port_busy() {
    ss -Hltn 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]$1\$" && return 0
    docker ps --format '{{.Ports}}' 2>/dev/null | grep -Eq "[:.]$1->" && return 0
    return 1
}

main() {
    set -euo pipefail
    local REPO="${REPO:-https://github.com/MuhriddinTurayev/erm.git}"
    local DIR="${DIR:-/opt/oftob}" PORT="${PORT:-8090}" DOMAIN="${DOMAIN:-}" HTTPS="${HTTPS:-0}" DEMO="${DEMO:-0}"
    local ADMIN_EMAIL="${ADMIN_EMAIL:-admin@oftob.uz}" ADMIN_PASS="" DC IP URL BIND SECURE st i owners br

    say "1/7 Tekshiruv"
    [ "$(id -u)" -eq 0 ] || die "root nomidan ishga tushiring"
    command -v docker >/dev/null || die "Docker o'rnatilmagan: curl -fsSL https://get.docker.com | sh"
    docker info >/dev/null 2>&1 || die "Docker xizmati ishlamayapti: systemctl start docker"
    if docker compose version >/dev/null 2>&1; then DC="docker compose -p oftob"
    elif command -v docker-compose >/dev/null; then DC="docker-compose -p oftob"
    else die "docker compose yo'q: apt-get install -y docker-compose-plugin"; fi
    command -v git >/dev/null || { apt-get update -qq </dev/null && apt-get install -y -qq git </dev/null; }
    command -v openssl >/dev/null || { apt-get update -qq </dev/null && apt-get install -y -qq openssl </dev/null; }

    say "2/7 Dastur kodi ($DIR)"
    if [ -d "$DIR/.git" ]; then
        git -C "$DIR" pull --ff-only </dev/null
    else
        git clone --depth 1 "$REPO" "$DIR" </dev/null
    fi
    cd "$DIR"

    say "3/7 Sozlamalar (.env)"
    if [ -f .env ]; then
        echo "    .env bor — saqlanadi (parollar o'zgarmaydi)"
    else
        # bo'sh port (8090 band bo'lsa — keyingisi)
        while port_busy "$PORT"; do PORT=$((PORT + 1)); done
        # serverning tashqi IP manzili (Docker tarmog'iniki emas)
        IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1)}' | head -1 || true)"
        [ -n "$IP" ] || IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
        if [ -n "$DOMAIN" ]; then URL="https://$DOMAIN"; BIND="127.0.0.1"; SECURE=true; else URL="http://$IP:$PORT"; BIND="0.0.0.0"; SECURE=false; fi
        (
            umask 077
            cat > .env <<EOF
# Oftob_Sohtumon — server sozlamalari (hech kimga bermang!)
APP_NAME=Oftob_Sohtumon
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=$URL
APP_LOCALE=uz
APP_FALLBACK_LOCALE=ru
APP_FAKER_LOCALE=ru_RU
# zaxira yo'l: $URL/setup?key=<SETUP_KEY> (foydalanuvchilar hali yo'q bo'lsa)
SETUP_KEY=$(openssl rand -hex 12)

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
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

# serverdagi port (HTTPS proksi orqali ishlaganda — faqat 127.0.0.1)
OFTOB_PORT=$PORT
OFTOB_BIND=$BIND
EOF
        )
        echo "    .env yaratildi: tasodifiy parollar va kalitlar, port $PORT"
    fi
    PORT="$(env_get OFTOB_PORT)"; PORT="${PORT:-8090}"

    say "4/7 Tarmoq devori (faqat bizning Docker tarmog'i uchun ruxsat)"
    bash deploy/firewall.sh install || warn "firewall.sh bajarilmadi — keyin qo'lda: bash $DIR/deploy/firewall.sh install"

    say "5/7 Yig'ish va ishga tushirish (birinchi marta 3–6 daqiqa)"
    $DC build </dev/null
    # tarmoq ko'prigi nomi doimiy bo'lishi kerak (br-oftob): eski tarmoq bo'lsa — qayta yaratiladi, ma'lumotlar saqlanadi
    if docker network inspect oftob_oftob >/dev/null 2>&1; then
        br="$(docker network inspect -f '{{index .Options "com.docker.network.bridge.name"}}' oftob_oftob 2>/dev/null || true)"
        if [ "$br" != "br-oftob" ]; then echo "    Docker tarmog'i yangilanmoqda (br-oftob)"; $DC down </dev/null; fi
    fi
    $DC up -d </dev/null
    for i in $(seq 1 90); do
        st="$(docker inspect -f '{{.State.Health.Status}}' oftob-app 2>/dev/null || echo starting)"
        [ "$st" = "healthy" ] && break
        if [ "$i" = 90 ]; then $DC logs --tail 80 app </dev/null; die "Dastur ishga tushmadi — jurnal yuqorida"; fi
        sleep 4
    done
    echo "    Dastur ishlayapti (127.0.0.1:$PORT)"

    say "6/7 Ma'lumotlar"
    if [ "$DEMO" = "1" ]; then
        echo "    Demo kompaniyalar yaratilmoqda (paroli Demo2026!)..."
        $DC exec -T app artisan oftob:demo --no-interaction </dev/null || warn "demo yaratilmadi"
    fi
    # platforma egasi: hali yo'q bo'lsa — ishonchli parol bilan yaratiladi (parol bir marta ko'rsatiladi)
    owners="$($DC exec -T db sh -c 'mysql -N -s -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "select count(*) from users where role=\"superadmin\"" 2>/dev/null' </dev/null | tr -dc '0-9' || true)"
    if [ "$owners" = "0" ]; then
        ADMIN_PASS="$(openssl rand -base64 15 | tr -d '/+=\n')"
        if ! $DC exec -T app artisan oftob:user "$ADMIN_EMAIL" "$ADMIN_PASS" --role=superadmin --name="Platforma egasi" </dev/null >/dev/null; then
            ADMIN_PASS=""
            warn "platforma egasi yaratilmadi — qo'lda: cd $DIR && docker compose exec app artisan oftob:user email parol --role=superadmin"
        fi
    elif [ -n "$owners" ]; then
        echo "    Platforma egasi bor — o'zgartirilmaydi"
    else
        warn "platforma egasini tekshirib bo'lmadi — kerak bo'lsa: cd $DIR && docker compose exec app artisan oftob:user email parol --role=superadmin"
    fi

    say "7/7 HTTPS"
    if [ -n "$DOMAIN" ]; then
        bash deploy/https.sh "$DOMAIN" || warn "HTTPS sozlanmadi — xabarni yuboring yoki qayta urinib ko'ring: bash $DIR/deploy/https.sh $DOMAIN"
    elif [ "$HTTPS" = "1" ]; then
        bash deploy/https.sh || warn "HTTPS sozlanmadi — xabarni yuboring yoki qayta urinib ko'ring: bash $DIR/deploy/https.sh"
    else
        echo "    O'tkazib yuborildi. HTTPS manzil uchun: bash $DIR/deploy/https.sh  →  https://oftob.<ip>.sslip.io"
    fi

    say "Tayyor!"
    echo "  Dastur:             $(env_get APP_URL)"
    if [ -n "$ADMIN_PASS" ]; then
        echo "  Platforma egasi:    $ADMIN_EMAIL"
        echo "  Parol:              $ADMIN_PASS   (saqlab qo'ying; o'zgartirish — «Xavfsizlik» bo'limida)"
    fi
    if [ "$DEMO" = "1" ]; then
        echo "  Demo kirishlar:     direktor@navruz.demo, direktor@mosgrad.demo, direktor@pamir.demo — parol Demo2026!"
    fi
    echo "  Papka:              $DIR   (sozlamalar — $DIR/.env)"
    echo "  Yangilash:          bash $DIR/deploy/update.sh"
    echo "  Zaxira nusxa:       bash $DIR/deploy/backup.sh"
    echo "  Jurnal:             cd $DIR && docker compose logs -f app"
}

main "$@"
exit $?
