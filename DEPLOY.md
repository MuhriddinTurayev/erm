# Oftob_Sohtumon — serverga o‘rnatish (Docker)

Dastur serverda **ikkita alohida konteynerda** ishlaydi va boshqa loyihalarga tegmaydi:

| Konteyner | Vazifasi |
|---|---|
| `oftob-app` | nginx + PHP 8.4 + rejalashtiruvchi (kunlik hisobot, Telegram-bot, eslatmalar) |
| `oftob-db` | MySQL 8, faqat ichki `oftob` tarmog‘ida (tashqariga ochilmaydi) |

Ma’lumotlar Docker volume’larida saqlanadi: `oftob_db` (baza), `oftob_storage` (3D, cheklar, fotolar), `oftob_uploads` (sayt rasmlari). Konteyner qayta yig‘ilsa ham ular o‘chmaydi.

Tashqaridan dastur **HTTPS manzil** orqali ochiladi: serverdagi mavjud nginx so‘rovni `127.0.0.1:8090` dagi konteynerga uzatadi. Domen sotib olish shart emas — `https://oftob.<ip-chiziqcha-bilan>.sslip.io` ishlatiladi (masalan `https://oftob.37-60-247-120.sslip.io`), sertifikat bepul (Let’s Encrypt) va avtomatik yangilanadi.

## Bitta buyruq bilan o‘rnatish

Serverga `root` bilan kiring:

```bash
curl -fsSL https://raw.githubusercontent.com/MuhriddinTurayev/erm/main/deploy/install.sh | HTTPS=1 bash
```

Skript: kodni `/opt/oftob` ga yuklaydi → tasodifiy parollar bilan `.env` yaratadi → tarmoq devoriga ruxsat qo‘shadi → konteynerlarni yig‘ib ishga tushiradi → platforma egasini (`admin@oftob.uz`) yaratadi → HTTPS’ni sozlaydi va oxirida manzil hamda **bir martalik parolni** chiqaradi.

Parametrlar: `HTTPS=1` (sslip.io manzili), `DOMAIN=erp.misol.uz` (o‘z domeningiz), `DEMO=1` (demo kompaniyalar, paroli `Demo2026!` — ochiq serverda ehtiyot bo‘ling), `ADMIN_EMAIL=siz@pochta.uz`, `PORT=8095`, `DIR=/srv/oftob`.

## Qadamma-qadam o‘rnatish

Har bir buyruq alohida, hammasi `root` nomidan.

**1. Docker borligini tekshirish** (ikkalasi ham versiya chiqarishi kerak):

```bash
docker --version
docker compose version
```

**2. Dasturni yuklab olish:**

```bash
git clone https://github.com/MuhriddinTurayev/erm.git /opt/oftob
cd /opt/oftob
```

**3. Sozlamalar fayli `.env`** — `IP` o‘rniga serveringiz manzilini yozing, parollar avtomatik yaratiladi:

```bash
IP=203.0.113.10
PORT=8090
cat > .env <<EOF
APP_NAME=Oftob_Sohtumon
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=http://$IP:$PORT
APP_LOCALE=uz
APP_FALLBACK_LOCALE=ru
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
SESSION_SECURE_COOKIE=false
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
OFTOB_PORT=$PORT
OFTOB_BIND=0.0.0.0
EOF
chmod 600 .env
```

**4. Tarmoq devori** (serverda `profile_hosting` jadvali bo‘lsa, faqat bizning tarmoq uchun ruxsat qo‘shadi; bo‘lmasa hech narsa qilmaydi):

```bash
bash deploy/firewall.sh install
```

**5. Yig‘ish va ishga tushirish** (birinchi marta 3–6 daqiqa), so‘ng holat — `oftob-app` yonida `(healthy)` chiqishi kerak:

```bash
docker compose up -d --build
docker compose ps
```

**6. HTTPS manzil** (`.env` dagi manzilni ham o‘zi yangilaydi):

```bash
bash deploy/https.sh
```

**7. Platforma egasi** — parol avtomatik yaratiladi va ekranga chiqadi:

```bash
PASS=$(openssl rand -base64 15 | tr -d '/+='); docker compose exec app artisan oftob:user admin@oftob.uz "$PASS" --role=superadmin --name="Platforma egasi" && echo "PAROL: $PASS"
```

**8. Demo kompaniyalar** (ixtiyoriy, faqat sinov uchun):

```bash
docker compose exec app artisan oftob:demo
```

**9. Har kecha avtomatik zaxira nusxa** (03:00 da, `/opt/oftob-backups` ga):

```bash
(crontab -l 2>/dev/null; echo "0 3 * * * bash /opt/oftob/deploy/backup.sh >/dev/null 2>&1") | crontab -
```

## Tarmoq devori haqida

Ba’zi serverlarda hosting paneli nftables’da `inet profile_hosting` jadvalini yaratadi: u ro‘yxatda bo‘lmagan Docker trafigini jim tashlab yuboradi. Belgilari: brauzerda `http://IP:PORT` ochilmaydi (`ERR_CONNECTION_TIMED_OUT`), konteynerdan Telegram, sun’iy intellekt va Google’ga chiqib bo‘lmaydi. `deploy/firewall.sh` faqat `br-oftob` ko‘prigi uchun ruxsat qo‘shadi va systemd timer orqali har 2 daqiqada tekshirib turadi.

```bash
bash /opt/oftob/deploy/firewall.sh status    # holat va konteynerdan internetga chiqish tekshiruvi
```

## Domen va HTTPS

| Nima | Buyruq |
|---|---|
| sslip.io manzili (standart) | `bash /opt/oftob/deploy/https.sh` → `https://oftob.<ip>.sslip.io` |
| Boshqa prefiks | `bash /opt/oftob/deploy/https.sh erp` → `https://erp.<ip>.sslip.io` |
| O‘z domeningiz | `bash /opt/oftob/deploy/https.sh erp.misol.uz` (avval DNS’da A yozuvini server IP’siga yo‘naltiring) |

Skript serverda ishlab turgan nginx’ni topadi (aaPanel yoki oddiy), uning sayt papkasiga alohida `oftob.conf` qo‘shadi, har o‘zgarishdan oldin `nginx -t` bilan tekshiradi va xato bo‘lsa faqat o‘z faylini olib tashlaydi — boshqa saytlarga tegmaydi. So‘ng `.env` da `APP_URL`, `SESSION_SECURE_COOKIE=true` va `OFTOB_BIND=127.0.0.1` ni o‘rnatadi.

Google bilan kirish uchun Google Console → Authorized redirect URIs: `https://<manzil>/auth/google/callback`.

## Kundalik ishlar

Buyruqlar `/opt/oftob` papkasida bajariladi (`cd /opt/oftob`).

| Nima | Buyruq |
|---|---|
| Yangilash (GitHub’dagi yangi kod; avval zaxira nusxa olinadi) | `bash /opt/oftob/deploy/update.sh` |
| Zaxira nusxa (baza + fayllar) | `bash /opt/oftob/deploy/backup.sh` → `/opt/oftob-backups` |
| Holat | `docker compose ps` |
| Jurnal (chiqish — Ctrl+C) | `docker compose logs -f app` |
| Qayta ishga tushirish | `docker compose restart app` |
| Artisan buyrug‘i | `docker compose exec app artisan ...` |
| Platforma egasini buyruq bilan yaratish | `docker compose exec app artisan oftob:user email parol --role=superadmin` |

## Muhim

- **Telegram-bot**: bitta bot tokeni bir vaqtda faqat bitta joyda (server **yoki** kompyuter) ishlashi mumkin. Tokenni serverga qo‘ygach, lokal nusxadan olib tashlang.
- `.env` faylini hech kimga bermang va GitHub’ga yuklamang (u `.gitignore` da).
- Artisan’ni `php artisan` emas, `artisan` deb chaqiring: u fayllarni sayt bilan bir xil foydalanuvchi (`www-data`) nomidan yaratadi.
