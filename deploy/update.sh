#!/usr/bin/env bash
# Oftob_Sohtumon — serverda yangilash: GitHub'dan yangi kod, qayta yig'ish, migratsiyalar.
# Baza, fayllar (Docker volume'lari), .env va HTTPS sozlamasi saqlanadi.
#   bash /opt/oftob/deploy/update.sh
# Tartib: zaxira nusxa (olinmasa — yangilash to'xtaydi) → yangi kod → yig'ish → qayta ishga tushirish → tekshiruv.

say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mXATO: %s\033[0m\n' "$*" >&2; exit 1; }

main() {
    set -euo pipefail
    local DIR DC br i
    DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)"
    cd "$DIR"
    [ -s .env ] || die ".env topilmadi — avval o'rnating: bash $DIR/deploy/install.sh"
    if docker compose version >/dev/null 2>&1; then DC="docker compose -p oftob"; else DC="docker-compose -p oftob"; fi

    say "1/5 Zaxira nusxa"
    if docker inspect -f '{{.State.Running}}' oftob-db 2>/dev/null | grep -q true; then
        bash "$DIR/deploy/backup.sh" || die "Zaxira nusxa olinmadi — yangilash to'xtatildi (ma'lumotlarga tegilmadi)"
    else
        echo "    Baza konteyneri ishlamayapti — nusxa olinmadi"
    fi

    say "2/5 Yangi kod"
    git pull --ff-only </dev/null

    say "3/5 Yig'ish (sayt shu vaqtda ishlashda davom etadi)"
    $DC build </dev/null

    say "4/5 Qayta ishga tushirish (migratsiyalar konteyner ishga tushganda qo'llanadi)"
    # tarmoq ko'prigi nomi doimiy bo'lishi kerak (br-oftob): eski tarmoq bo'lsa — qayta yaratiladi, ma'lumotlar saqlanadi
    if docker network inspect oftob_oftob >/dev/null 2>&1; then
        br="$(docker network inspect -f '{{index .Options "com.docker.network.bridge.name"}}' oftob_oftob 2>/dev/null || true)"
        if [ "$br" != "br-oftob" ]; then echo "    Docker tarmog'i yangilanmoqda (br-oftob)"; $DC down </dev/null; fi
    fi
    $DC up -d </dev/null
    docker image prune -f >/dev/null 2>&1 || true

    say "5/5 Tekshiruv"
    for i in $(seq 1 90); do
        if [ "$(docker inspect -f '{{.State.Health.Status}}' oftob-app 2>/dev/null || true)" = "healthy" ]; then
            echo "    Tayyor: $(grep -E '^APP_URL=' .env | tail -n1 | cut -d= -f2-)"
            return 0
        fi
        sleep 4
    done
    $DC logs --tail 80 app </dev/null
    die "Dastur ishga tushmadi — jurnal yuqorida"
}

main "$@"
exit $?
