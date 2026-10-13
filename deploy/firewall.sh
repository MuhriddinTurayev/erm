#!/usr/bin/env bash
# Oftob_Sohtumon — server tarmoq devori (nftables "inet profile_hosting" jadvali) uchun ruxsatlar.
#
# Ba'zi serverlarda hosting paneli "inet profile_hosting" jadvalini yaratadi: uning forward zanjiri "policy drop" —
# ro'yxatda bo'lmagan Docker trafigini jim tashlab yuboradi. Natijada:
#   * brauzerdan http://IP:PORT ochilmaydi (serverning o'zida curl ishlasa ham);
#   * konteynerdan Telegram, sun'iy intellekt (Gemini, ChatGPT, DeepSeek, Claude), Google va pochtaga chiqib bo'lmaydi.
# Skript faqat bizning Docker tarmog'imiz (br-oftob) uchun ruxsat qo'shadi, boshqa qoidalarga tegmaydi.
# Bunday jadval bo'lmasa, hech narsa qilmaydi.
#
#   bash deploy/firewall.sh install  — qoidalarni qo'shadi va doimiy qiladi (systemd timer har 2 daqiqada tekshiradi)
#   bash deploy/firewall.sh apply    — faqat qoidalarni qo'shadi (timer shuni chaqiradi)
#   bash deploy/firewall.sh status   — joriy holat va konteynerdan internetga chiqish tekshiruvi
#   bash deploy/firewall.sh remove   — bizning qoidalarni va timer'ni olib tashlaydi
set -u
FAMILY="inet"
TABLE="profile_hosting"
BR="br-oftob"
WAN="${OFTOB_WAN_IF:-$(ip route show default 2>/dev/null | awk '/default/ {for (i = 1; i <= NF; i++) if ($i == "dev") {print $(i + 1); exit}}')}"
WAN="${WAN:-eth0}"

rules() {
    # teg|qoida (teg — izoh: qoida ikki marta qo'shilmasligi va keyin topib o'chirish uchun)
    cat <<EOF
oftob-in-web|iifname "$WAN" oifname "$BR" tcp dport 80 ct state new accept
oftob-return|iifname "$WAN" oifname "$BR" ct state established,related accept
oftob-reply|iifname "$BR" oifname "$WAN" ct state established,related accept
oftob-out-web|iifname "$BR" oifname "$WAN" tcp dport { 80, 443 } accept
oftob-out-mail|iifname "$BR" oifname "$WAN" tcp dport { 465, 587 } accept
oftob-out-dns|iifname "$BR" oifname "$WAN" meta l4proto { tcp, udp } th dport 53 accept
oftob-out-icmp|iifname "$BR" oifname "$WAN" meta l4proto icmp accept
oftob-internal|iifname "$BR" oifname "$BR" accept
EOF
}

# profile_hosting jadvalidagi forward hook'li zanjirlar (odatda bitta: forward)
forward_chains() {
    nft list table "$FAMILY" "$TABLE" 2>/dev/null | awk '$1 == "chain" {c = $2} /hook forward/ {print c}'
}

do_apply() {
    if ! command -v nft >/dev/null 2>&1; then
        echo "  nft topilmadi — nftables ishlatilmaydi, qo'shimcha ruxsat kerak emas."
        return 0
    fi
    local chains chain current tag rule added=0
    chains=$(forward_chains)
    if [ -z "$chains" ]; then
        echo "  profile_hosting jadvali yo'q — qo'shimcha ruxsat kerak emas."
        return 0
    fi
    for chain in $chains; do
        current=$(nft list chain "$FAMILY" "$TABLE" "$chain" 2>/dev/null)
        while IFS='|' read -r tag rule; do
            [ -z "$tag" ] && continue
            grep -q "comment \"$tag\"" <<<"$current" && continue
            # insert — zanjir boshiga: oxirida aniq "drop" qoidasi bo'lsa ham bizning ruxsat avval tekshiriladi
            if nft "insert rule $FAMILY $TABLE $chain $rule comment \"$tag\""; then
                added=$((added + 1))
            else
                echo "  XATO: qoida qo'shilmadi ($chain): $rule" >&2
            fi
        done < <(rules)
    done
    if [ "$added" -gt 0 ]; then
        echo "  profile_hosting: $added ta Oftob qoidasi qo'shildi ($BR <-> $WAN)."
    else
        echo "  profile_hosting: Oftob qoidalari joyida ($BR <-> $WAN)."
    fi
    return 0
}

do_install() {
    local self
    if ! command -v nft >/dev/null 2>&1 || [ -z "$(forward_chains)" ]; then
        echo "  profile_hosting jadvali yo'q — tarmoq devoriga qo'shimcha ruxsat kerak emas."
        return 0
    fi
    self=$(readlink -f "$0")
    cp "$self" /usr/local/sbin/oftob-firewall
    chmod 0755 /usr/local/sbin/oftob-firewall
    cat > /etc/systemd/system/oftob-firewall.service <<'EOF'
[Unit]
Description=Oftob_Sohtumon: nftables profile_hosting ruxsatlari (Docker br-oftob)
After=network-online.target docker.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/oftob-firewall apply
EOF
    cat > /etc/systemd/system/oftob-firewall.timer <<'EOF'
[Unit]
Description=Oftob_Sohtumon tarmoq devori qoidalarini muntazam tekshirish

[Timer]
OnBootSec=30s
OnUnitActiveSec=2min

[Install]
WantedBy=timers.target
EOF
    local timer=0
    if command -v systemctl >/dev/null 2>&1 && systemctl daemon-reload >/dev/null 2>&1 \
        && systemctl enable --now oftob-firewall.timer >/dev/null 2>&1; then
        timer=1
    fi
    do_apply
    if [ "$timer" -eq 1 ]; then
        echo "  Doimiy qilindi: server qayta yuklansa yoki panel qoidalarni qayta yozsa, 2 daqiqa ichida tiklanadi."
    else
        echo "  OGOHLANTIRISH: systemd timer yoqilmadi — server qayta yuklangandan keyin: bash deploy/firewall.sh apply" >&2
    fi
}

# konteynerdan tashqi xizmatlarga chiqish: istalgan HTTP javob kodi — yo'l ochiq, 000 — yopiq
egress() {
    local url code
    if ! docker inspect -f '{{.State.Running}}' oftob-app 2>/dev/null | grep -q true; then
        echo "  oftob-app ishlamayapti — internetga chiqish tekshirilmadi"
        return 0
    fi
    for url in https://api.telegram.org https://generativelanguage.googleapis.com https://api.openai.com https://oauth2.googleapis.com; do
        code=$(docker exec oftob-app curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$url" </dev/null 2>/dev/null || true)
        if [ -n "$code" ] && [ "$code" != "000" ]; then
            echo "  $url: ochiq (HTTP $code)"
        else
            echo "  $url: YOPIQ"
        fi
    done
}

do_status() {
    echo "WAN interfeys: $WAN, Docker tarmog'i: $BR"
    if ip link show "$BR" >/dev/null 2>&1; then echo "  $BR: bor"; else echo "  $BR: hali yo'q (konteynerlar ishga tushganda paydo bo'ladi)"; fi
    local chains
    chains=$(forward_chains)
    if [ -z "$chains" ]; then
        echo "  profile_hosting jadvali: yo'q"
    else
        echo "  profile_hosting forward zanjiri: $chains; Oftob qoidalari: $(nft list table "$FAMILY" "$TABLE" 2>/dev/null | grep -c 'comment "oftob-')"
    fi
    if systemctl is-active oftob-firewall.timer >/dev/null 2>&1; then echo "  timer: faol"; else echo "  timer: o'rnatilmagan"; fi
    echo "Konteynerdan internetga chiqish:"
    egress
}

do_remove() {
    systemctl disable --now oftob-firewall.timer >/dev/null 2>&1
    rm -f /etc/systemd/system/oftob-firewall.service /etc/systemd/system/oftob-firewall.timer /usr/local/sbin/oftob-firewall
    systemctl daemon-reload 2>/dev/null
    local chain h
    for chain in $(forward_chains); do
        nft -a list chain "$FAMILY" "$TABLE" "$chain" 2>/dev/null | grep 'comment "oftob-' | sed -n 's/.*# handle \([0-9]*\)$/\1/p' | while read -r h; do
            nft delete rule "$FAMILY" "$TABLE" "$chain" handle "$h"
        done
    done
    echo "Oftob qoidalari olib tashlandi."
}

case "${1:-apply}" in
    install) do_install ;;
    apply) do_apply ;;
    status) do_status ;;
    remove) do_remove ;;
    *) echo "Ishlatish: bash deploy/firewall.sh install|apply|status|remove"; exit 1 ;;
esac
