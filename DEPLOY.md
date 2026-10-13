# Oftob_Sohtumon — serverga o‘rnatish (Docker)

Dastur serverda **ikkita alohida konteynerda** ishlaydi va boshqa loyihalarga tegmaydi:

| Konteyner | Vazifasi |
|---|---|
| `oftob-app` | nginx + PHP 8.4 + rejalashtiruvchi (kunlik hisobot, Telegram-bot, eslatmalar) |
| `oftob-db` | MySQL 8, faqat ichki `oftob` tarmog‘ida (tashqariga ochilmaydi) |

Ma’lumotlar Docker volume’larida saqlanadi: `oftob_db` (baza), `oftob_storage` (3D, cheklar, fotolar), `oftob_uploads` (sayt rasmlari). Konteyner qayta yig‘ilsa ham ular o‘chmaydi.

## O‘rnatish

Serverga `root` bilan kiring va bitta buyruqni bajaring:

```bash
curl -fsSL https://raw.githubusercontent.com/MuhriddinTurayev/erm/main/deploy/install.sh | bash
```

Skript o‘zi:
1. kodni `/opt/oftob` ga yuklaydi;
2. tasodifiy parollar va kalitlar bilan `/opt/oftob/.env` yaratadi (faqat serverda saqlanadi);
3. bo‘sh portni topadi (standart `8090`; band bo‘lsa — keyingisi);
4. konteynerlarni yig‘ib ishga tushiradi va migratsiyalarni qo‘llaydi;
5. oxirida dastur manzili va **platforma egasini yaratish havolasini** (`/setup?key=...`) chiqaradi.

Qo‘shimcha parametrlar: `PORT=8095`, `DIR=/srv/oftob`, `DOMAIN=erp.misol.uz` (domen bilan — port faqat `127.0.0.1` da ochiladi, HTTPS’ni proksi beradi), `DEMO=1` (sinov uchun demo kompaniyalar, paroli `Demo2026!` — ochiq serverda ehtiyot bo‘ling).

```bash
curl -fsSL https://raw.githubusercontent.com/MuhriddinTurayev/erm/main/deploy/install.sh | DOMAIN=erp.misol.uz bash
```

## Domen va HTTPS

Domen server IP’siga yo‘naltirilgan bo‘lsa, serverdagi mavjud proksi (nginx) orqali ulang:

```nginx
server {
    server_name erp.misol.uz;
    client_max_body_size 64m;
    location / {
        proxy_pass http://127.0.0.1:8090;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 180s;
    }
}
```

So‘ng `certbot --nginx -d erp.misol.uz`. `.env` da `APP_URL=https://erp.misol.uz` va `SESSION_SECURE_COOKIE=true` qilib, `cd /opt/oftob && docker compose up -d` bajaring.

## Kundalik ishlar

| Nima | Buyruq |
|---|---|
| Yangilash (GitHub’dagi yangi kod) | `bash /opt/oftob/deploy/update.sh` |
| Zaxira nusxa (baza + fayllar) | `bash /opt/oftob/deploy/backup.sh` → `/opt/oftob-backups` |
| Har kecha avtomatik nusxa | `(crontab -l; echo "0 3 * * * bash /opt/oftob/deploy/backup.sh >/dev/null 2>&1") \| crontab -` |
| Jurnal | `cd /opt/oftob && docker compose logs -f app` |
| Artisan buyrug‘i | `cd /opt/oftob && docker compose exec app artisan ...` |
| Platforma egasini buyruq bilan yaratish | `docker compose exec app artisan oftob:user email parol --role=superadmin` |

## Muhim

- **Telegram-bot**: bitta bot tokeni bir vaqtda faqat bitta joyda (server **yoki** kompyuter) ishlashi mumkin. Tokenni serverga qo‘ygach, lokal nusxadan olib tashlang.
- `.env` faylini hech kimga bermang va GitHub’ga yuklamang (u `.gitignore` da).
