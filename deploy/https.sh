#!/usr/bin/env bash
# Oftob_Sohtumon — HTTPS manzil serverdagi mavjud nginx orqali (Let's Encrypt sertifikati, avtomatik yangilanadi).
#
#     bash deploy/https.sh                 — https://oftob.<server-ip-chiziqcha-bilan>.sslip.io (domen sotib olish shart emas)
#     bash deploy/https.sh erp             — boshqa prefiks: https://erp.37-60-247-120.sslip.io
#     bash deploy/https.sh erp.misol.uz    — o'z domeningiz (DNS'da A yozuvi server IP'siga yo'naltirilgan bo'lishi kerak)
#
# Serverda ISHLAB TURGAN nginx aniqlanadi (aaPanel: /www/server/nginx yoki oddiy /usr/sbin/nginx; konteynerlar ichidagisi emas)
# va Oftob uchun ALOHIDA fayl o'sha nginx'ning sayt papkasiga qo'shiladi — boshqa saytlarga tegilmaydi.
# Har o'zgarishdan oldin "nginx -t" tekshiriladi; xato bo'lsa faqat bizning fayl olib tashlanadi.
# Qayta ishga tushirish xavfsiz.

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
die()  { printf '\n\033[1;31mXATO: %s\033[0m\n' "$*" >&2; exit 1; }
env_get() { { grep -E "^$1=" .env 2>/dev/null || true; } | tail -n1 | cut -d= -f2- | tr -d "\"'"; }
env_set() { # KALIT QIYMAT — bor bo'lsa almashtiradi, yo'q bo'lsa qo'shadi
    if grep -qE "^$1=" .env; then sed -i "s#^$1=.*#$1=$2#" .env; else printf '%s=%s\n' "$1" "$2" >> .env; fi
}

WEBROOT=/var/www/oftob-acme
NGX_BIN=""
NGX_CONF=""
NGX_MASTER=""

# Ishlab turgan nginx (serverning o'zida, Docker konteyner ichida emas): bosh jarayon, dastur va asosiy sozlama fayli
detect_nginx() {
    local pid args host_ns
    host_ns=$(readlink /proc/1/ns/pid 2>/dev/null || true)
    for pid in $(pgrep -f '^nginx: master process' || true); do
        if [ "$(readlink "/proc/$pid/ns/pid" 2>/dev/null || true)" = "$host_ns" ]; then NGX_MASTER=$pid; break; fi
    done
    if [ -z "$NGX_MASTER" ]; then return 1; fi
    args=$(ps -o args= -p "$NGX_MASTER" 2>/dev/null || true)
    NGX_BIN=$(awk '{print $4}' <<<"$args")
    NGX_CONF=$(awk '{for (i = 1; i <= NF; i++) if ($i == "-c") {print $(i + 1); exit}}' <<<"$args")
    if [ -z "$NGX_BIN" ] || [ ! -x "$NGX_BIN" ]; then NGX_BIN=$(readlink -f "/proc/$NGX_MASTER/exe" 2>/dev/null || true); fi
    if [ -z "$NGX_BIN" ] || [ ! -x "$NGX_BIN" ]; then NGX_BIN=$(command -v nginx || true); fi
    if [ -z "$NGX_BIN" ]; then die "nginx dasturi topilmadi"; fi
    if [ -z "$NGX_CONF" ]; then
        NGX_CONF=$("$NGX_BIN" -V 2>&1 | grep -o -- '--conf-path=[^ ]*' | cut -d= -f2 || true)
        NGX_CONF="${NGX_CONF:-/etc/nginx/nginx.conf}"
    fi
    info "Ishlab turgan nginx: $NGX_BIN -c $NGX_CONF (PID $NGX_MASTER)"
}

# nginx yo'q bo'lsa va 80-port bo'sh bo'lsa — oddiy nginx o'rnatiladi; port band bo'lsa (Caddy, Traefik va h.k.) — to'xtaymiz
ensure_nginx() {
    if detect_nginx; then return 0; fi
    if ss -Hltn 2>/dev/null | awk '{print $4}' | grep -Eq '[:.]80$'; then
        die "80-portni nginx emas, boshqa dastur egallagan. Natijani yuboring: ss -ltnp | grep -E ':(80|443) '"
    fi
    info "Serverda nginx yo'q — o'rnatilmoqda..."
    apt-get update -qq </dev/null && apt-get install -y -qq nginx </dev/null >/dev/null || die "nginx o'rnatilmadi"
    systemctl enable --now nginx >/dev/null 2>&1 || true
    sleep 1
    detect_nginx || die "nginx o'rnatildi, lekin ishga tushmadi: systemctl status nginx"
}

ngx() { "$NGX_BIN" -c "$NGX_CONF" "$@"; }

# Sayt fayllari qo'shiladigan papka (nginx sozlamasidagi "include .../*" qatoridan)
detect_vhost_dir() {
    local inc dir
    while read -r inc; do
        dir=$(dirname "$inc")
        case "$dir" in
            */vhost/nginx|*/sites-enabled|*/conf.d)
                if [ -d "$dir" ]; then echo "$dir"; return 0; fi ;;
        esac
    done < <(ngx -T 2>/dev/null | awk '$1 == "include" {gsub(";", "", $2); print $2}' | grep -E '\*' || true)
    return 1
}

reload_nginx() {
    if ! ngx -t >/dev/null 2>&1; then return 1; fi
    if ngx -s reload >/dev/null 2>&1; then sleep 1; return 0; fi
    if kill -HUP "$NGX_MASTER" 2>/dev/null; then sleep 2; return 0; fi
    return 1
}

# umumiy proksi sozlamalari: 3D-modellar va fotolar uchun katta hajm, AI va hisobotlar uchun uzoq kutish
proxy_block() { # port proto
    cat <<EOF
    location / {
        proxy_pass http://127.0.0.1:$1;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $2;
        proxy_read_timeout 180s;
        proxy_send_timeout 180s;
    }
EOF
}

write_http_conf() { # conf domain port
    {
        cat <<EOF
# Oftob_Sohtumon (Docker, 127.0.0.1:$3) — deploy/https.sh yaratgan. Boshqa saytlarga ta'sir qilmaydi.
server {
    listen 80;
    listen [::]:80;
    server_name $2;
    client_max_body_size 64m;

    location ^~ /.well-known/acme-challenge/ {
        root $WEBROOT;
        default_type "text/plain";
    }

EOF
        proxy_block "$3" '$scheme'
        echo "}"
    } > "$1"
}

write_https_conf() { # conf domain port
    {
        cat <<EOF
# Oftob_Sohtumon (Docker, 127.0.0.1:$3) — deploy/https.sh yaratgan. Boshqa saytlarga ta'sir qilmaydi.
server {
    listen 80;
    listen [::]:80;
    server_name $2;

    location ^~ /.well-known/acme-challenge/ {
        root $WEBROOT;
        default_type "text/plain";
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name $2;
    client_max_body_size 64m;

    ssl_certificate     /etc/letsencrypt/live/$2/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$2/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    location ^~ /.well-known/acme-challenge/ {
        root $WEBROOT;
        default_type "text/plain";
    }

EOF
        proxy_block "$3" https
        echo "}"
    } > "$1"
}

app_code() { # port — sayt konteyneri javobi (200 — ishlayapti)
    curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:$1/up" 2>/dev/null || true
}

main() {
    set -Eeu
    cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.."
    local arg="${1:-oftob}" port ip domain vdir conf code resolved token dc

    say "1/6 Tekshiruv"
    if [ "$(id -u)" -ne 0 ]; then die "root sifatida ishga tushiring"; fi
    if [ ! -s .env ]; then die ".env topilmadi — avval o'rnating: bash deploy/install.sh"; fi
    if docker compose version >/dev/null 2>&1; then dc="docker compose -p oftob"; else dc="docker-compose -p oftob"; fi
    if ! command -v certbot >/dev/null 2>&1; then
        info "certbot o'rnatilmoqda..."
        apt-get update -qq </dev/null && apt-get install -y -qq certbot </dev/null >/dev/null || die "certbot o'rnatilmadi"
    fi
    port=$(env_get OFTOB_PORT)
    port="${port:-8090}"
    ip=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") {print $(i + 1); exit}}' || true)
    if [ -z "$ip" ]; then die "Server IP manzili aniqlanmadi"; fi
    if [[ "$arg" == *.* ]]; then
        domain="${arg,,}"
    elif [[ "$arg" =~ ^[a-z0-9-]{1,30}$ ]]; then
        domain="$arg.${ip//./-}.sslip.io"
    else
        die "prefiks faqat kichik harf, raqam va '-' bo'lishi mumkin (yoki to'liq domen yozing)"
    fi
    resolved=$(getent ahostsv4 "$domain" | awk 'NR == 1 {print $1}' || true)
    if [ "$resolved" != "$ip" ]; then die "$domain DNS'da $ip ga yo'naltirilmagan (hozir: ${resolved:-topilmadi}). Birozdan keyin qayta urinib ko'ring."; fi
    info "Manzil: https://$domain (DNS: $resolved)"
    code=$(app_code "$port")
    if [ "$code" != "200" ]; then die "Sayt 127.0.0.1:$port da javob bermayapti (HTTP ${code:-000}). Avval tekshiring: cd $(pwd) && $dc ps"; fi
    info "Sayt ishlayapti (127.0.0.1:$port)"
    ensure_nginx
    vdir=$(detect_vhost_dir) || die "nginx sayt papkasi aniqlanmadi. Natijani yuboring: $NGX_BIN -c $NGX_CONF -T | grep include"
    conf="$vdir/oftob.conf"
    info "Sayt fayli: $conf"
    if ! ngx -t >/dev/null 2>&1; then
        ngx -t || true
        die "nginx sozlamasi hozirning o'zida xato beryapti (bizdan oldin) — hech narsa o'zgartirilmadi."
    fi

    say "2/6 nginx: Oftob sayti (HTTP)"
    mkdir -p "$WEBROOT/.well-known/acme-challenge"
    chmod -R 755 "$WEBROOT"
    if [ -f "$conf" ]; then cp "$conf" "/root/oftob-nginx.conf.bak.$(date +%s)"; fi
    if [ -f "/etc/letsencrypt/live/$domain/fullchain.pem" ]; then
        write_https_conf "$conf" "$domain" "$port"
    else
        write_http_conf "$conf" "$domain" "$port"
    fi
    if ! reload_nginx; then
        ngx -t || true
        rm -f "$conf"
        reload_nginx || true
        die "nginx Oftob faylini qabul qilmadi — fayl olib tashlandi, boshqa saytlar o'zgarmadi. Yuqoridagi xabarni yuboring."
    fi
    info "Qo'shildi va nginx qayta yuklandi"

    say "3/6 Tekshiruv: domen shu nginx'ga kelyaptimi"
    token="oftob-check-$(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    echo "$token" > "$WEBROOT/.well-known/acme-challenge/$token"
    chmod 644 "$WEBROOT/.well-known/acme-challenge/$token"
    code=$(curl -s --max-time 10 "http://$domain/.well-known/acme-challenge/$token" || true)
    rm -f "$WEBROOT/.well-known/acme-challenge/$token"
    if [ "$code" != "$token" ]; then
        die "http://$domain so'rovi Oftob fayliga yetib kelmadi (javob: ${code:0:80}). Natijani yuboring: $NGX_BIN -c $NGX_CONF -T | grep -nE 'include|server_name'"
    fi
    info "OK: domen to'g'ri ishlayapti"

    say "4/6 HTTPS sertifikati (Let's Encrypt)"
    if [ -f "/etc/letsencrypt/live/$domain/fullchain.pem" ]; then
        info "Sertifikat allaqachon bor"
    else
        if ! certbot certonly --webroot -w "$WEBROOT" -d "$domain" --non-interactive --agree-tos --keep-until-expiring \
                --deploy-hook "$NGX_BIN -c $NGX_CONF -s reload" </dev/null; then
            info "Qayta urinish (email'siz ro'yxatdan o'tish bilan)..."
            certbot certonly --webroot -w "$WEBROOT" -d "$domain" --non-interactive --agree-tos --keep-until-expiring \
                --deploy-hook "$NGX_BIN -c $NGX_CONF -s reload" --register-unsafely-without-email </dev/null \
                || die "Sertifikat olinmadi. Yuqoridagi certbot xabarini yuboring."
        fi
    fi
    cp "$conf" "/root/oftob-nginx.conf.bak.$(date +%s)"
    write_https_conf "$conf" "$domain" "$port"
    if ! reload_nginx; then
        ngx -t || true
        write_http_conf "$conf" "$domain" "$port"
        reload_nginx || true
        die "HTTPS sozlamasi qabul qilinmadi — HTTP holatiga qaytarildi. Yuqoridagi xabarni yuboring."
    fi
    info "HTTPS yoqildi"

    say "5/6 Sozlamalar (.env): yangi manzil, xavfsiz cookie, port faqat ichkarida"
    cp .env ".env.bak.$(date +%s)"
    chmod 600 .env.bak.* 2>/dev/null || true
    env_set APP_URL "https://$domain"
    env_set SESSION_SECURE_COOKIE true
    env_set OFTOB_BIND 127.0.0.1
    info "APP_URL=https://$domain"
    $dc up -d --no-deps --force-recreate app </dev/null
    for _ in $(seq 1 60); do
        code=$(app_code "$port")
        if [ "$code" = "200" ]; then break; fi
        sleep 3
    done

    say "6/6 Yakuniy tekshiruv"
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "https://$domain/up" || true)
    info "https://$domain/up → HTTP $code"
    if [ "$code" != "200" ]; then die "HTTPS orqali javob kelmadi. Natijani yuboring: cd $(pwd) && $dc ps; $NGX_BIN -c $NGX_CONF -t"; fi

    printf '\n\033[1;32m================================================================\033[0m\n'
    printf "\033[1;32m HTTPS tayyor!\033[0m\n"
    printf "   Sayt:                     https://%s\n" "$domain"
    printf "   Kirish:                   https://%s/login\n" "$domain"
    printf "   Google redirect URI:      https://%s/auth/google/callback\n" "$domain"
    printf '\033[1;32m================================================================\033[0m\n'
}

main "$@"
exit $?
