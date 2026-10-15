# Oftob_Sohtumon — Laravel 13

Ko'p qavatli uylar qurilishi uchun CRM: kotlovandan kvartiralar sotuvigacha.
CRM для строительства многоэтажных домов: от котлована до продажи квартир.

---

## 🆕 SaaS platforma (2026-10)

| Manzil | Kim uchun | Nima bor |
|---|---|---|
| `/` | hamma | Sayt: imkoniyatlar, tariflar, FAQ (ru / uz / tg) |
| `/register` | yangi kompaniya | Ro'yxatdan o'tish, 14 kun bepul |
| `/crm` | kompaniya xodimlari | Dastur (barcha obyektlar, kompaniya kassasi, AI-maslahatchi…) |
| `/company/overview` | rahbar, buxgalter, kassir | Kassa: bugun / kecha / 7 kun / oy, nimaga ketdi, obyektlar bo'yicha |
| `/company/reports` | rahbar | Kunlik hisobotlar (Telegram + email, AI xulosasi bilan) |
| `/company/users`, `/company/settings`, `/company/billing` | rahbar | Foydalanuvchilar, rollar va obyektlarga ruxsat, sozlamalar, tarif |
| `/admin` | super admin | Kompaniyalar, to'lovlar, tariflar, AI ulanishlari (Gemini, ChatGPT, DeepSeek, Claude), Telegram-bot |

- Rollar: rahbar, buxgalter, kassir, sotuv menejeri, prorab, faqat ko'rish. Prorab/menejerni alohida obyektlarga cheklash mumkin.
- Tarif cheklovlari: obyektlar, foydalanuvchilar, oylik AI so'rovlari. Muddat tugasa — faqat tarif sahifasi ochiq.
- Kunlik hisobotlar rejalashtiruvchi orqali ketadi: `php artisan schedule:work` (start.bat o'zi ishga tushiradi) yoki hostingda cron `* * * * * php artisan schedule:run`.
- Telegram-bot vebhuksiz (kompyuterda) xabarlarni ham shu rejalashtiruvchi orqali oladi. Open Server'da sayt alohida ishlaydi — botga javob berish uchun loyiha papkasidagi **`bot.bat`** ni ishga tushiring va oynasini yopmang. Serverda (Docker) rejalashtiruvchi o'zi ishlaydi.
- Super admin yaratish: `php artisan oftob:user email parol --role=superadmin`.

---

## 🇺🇿 O'zbekcha

### Nima o'zgardi
- **Server — Laravel 13 (PHP).** Barcha ma'lumotlar endi brauzerda emas, **ma'lumotlar bazasida** saqlanadi (SQLite yoki MySQL).
- **Login va parol** bilan kirish, **foydalanuvchilar va rollar**: rahbar, sotuv menejeri, buxgalter, prorab, faqat ko'rish.
- **Bir nechta xodim birga ishlaydi**: o'zgarishlar serverga avtomatik saqlanadi, boshqalarning o'zgarishlari har 15 soniyada yangilanadi. Bir vaqtda tahrirlansa — loyihalar bo'yicha birlashtiriladi.
- **Lidlar**: Facebook / Instagram (reklama formalari va Direct), Messenger, WhatsApp Business, sayt / Tilda / Make / Zapier — Laravel'da. Har bir kompaniyaning o'z webhook manzili bor, Meta kalitlari bazada **shifrlangan** holda saqlanadi.
- **Xodimlar kelishi va GPS**: telefon sahifasi `/w?t=...`, belgilar bazada saqlanadi.
- **Interfeys o'zgarmagan** — barcha bo'limlar, 3D, 360°, AI-maslahatchi (JavaScript, `public/app/`).

### Talablar
- PHP **8.3** yoki yangiroq (kengaytmalar: `pdo_sqlite` yoki `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`)
- Composer 2
- `php.ini`: `post_max_size = 32M`, `upload_max_filesize = 32M` (katta loyihalar uchun)

### O'rnatish (kompyuterda)
```bash
composer install
copy .env.example .env          # Linux/Mac: cp .env.example .env
php artisan key:generate
type nul > database\database.sqlite    # Linux/Mac: touch database/database.sqlite
php artisan migrate
php artisan serve
```
So'ng brauzerda **http://localhost:8000** ni oching → birinchi marta **kompaniya va rahbar** yaratiladi (`/setup` sahifasi).
Windows'da shunchaki **`start.bat`** faylini ishga tushirsangiz ham bo'ladi — u yuqoridagilarning hammasini o'zi qiladi.

### Hostingga joylash
1. Fayllarni serverga yuklang, **domen `public/` papkaga** qarashi kerak.
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://sizning-domen.uz`, MySQL ma'lumotlari.
3. `composer install --no-dev --optimize-autoloader`, `php artisan key:generate`, `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`.
4. `storage/` va `bootstrap/cache/` papkalariga yozish huquqi bering.
5. **HTTPS majburiy** — telefonlarda GPS va Meta webhook faqat https bilan ishlaydi.

### Foydalanuvchilar
- Dasturda yuqori o'ng burchakda **👥 Пользователи** (faqat rahbar uchun) — qo'shish, rol, parol, o'chirish.
- Yoki buyruq bilan: `php artisan oftob:user email@mail.uz parol123 --name="Ism" --role=manager`

### Eski versiyadan ma'lumotlarni ko'chirish
Eski dasturda: **Настройки → Скачать резервную копию** (JSON). Yangi dasturda: **Настройки → Загрузить из файла**.

---

## 🇷🇺 Русский

### Что изменилось
- **Сервер — Laravel 13 (PHP).** Все данные теперь хранятся **в базе данных** (SQLite или MySQL), а не в браузере.
- **Вход по логину и паролю**, **пользователи и роли**: руководитель, менеджер по продажам, бухгалтер, прораб, только просмотр.
- **Несколько сотрудников работают вместе**: изменения сами сохраняются на сервер, чужие изменения подтягиваются каждые 15 секунд. При одновременной правке изменения объединяются по проектам.
- **Лиды**: Facebook / Instagram (формы в рекламе и Direct), Messenger, WhatsApp Business, сайт / Tilda / Make / Zapier — на Laravel. У каждой компании свой адрес вебхука, ключи Meta хранятся в базе **зашифрованными**.
- **Приход сотрудников и GPS**: страница для телефона `/w?t=...`, отметки хранятся в базе.
- **Интерфейс не менялся** — все разделы, 3D, 360°, ИИ-консультант (JavaScript, папка `public/app/`).

### Требования
- PHP **8.3** или новее (расширения: `pdo_sqlite` или `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`)
- Composer 2
- `php.ini`: `post_max_size = 32M`, `upload_max_filesize = 32M` (для больших проектов)

### Установка на компьютере
```bash
composer install
copy .env.example .env          # Linux/Mac: cp .env.example .env
php artisan key:generate
type nul > database\database.sqlite    # Linux/Mac: touch database/database.sqlite
php artisan migrate
php artisan serve
```
Откройте **http://localhost:8000** → при первом входе создаются **компания и руководитель** (страница `/setup`).
На Windows можно просто запустить **`start.bat`** — он сделает всё сам.

### Размещение на хостинге
1. Загрузите файлы, **домен должен смотреть в папку `public/`**.
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://ваш-домен.uz`, данные MySQL.
3. `composer install --no-dev --optimize-autoloader`, `php artisan key:generate`, `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`.
4. Права на запись для `storage/` и `bootstrap/cache/`.
5. **HTTPS обязателен** — GPS на телефонах и вебхуки Meta работают только по https.

### Пользователи
- В программе справа вверху **👥 Пользователи** (только у руководителя): добавить, роль, пароль, удалить.
- Или командой: `php artisan oftob:user email@mail.uz пароль123 --name="Имя" --role=manager`

### Перенос данных из старой версии
В старой программе: **Настройки → Скачать резервную копию** (JSON). В новой: **Настройки → Загрузить из файла**.

---

## Структура / Tuzilma
| Папка | Что внутри |
|---|---|
| `app/Http/Controllers` | `AuthController` вход и первый запуск · `StateController` данные CRM · `LeadController` заявки Meta и сайта · `GeoController` приход и GPS · `UserController` пользователи |
| `app/Models` | `Workspace` (компания), `User`, `Integration`, `LeadInbox`, `WorkerToken`, `WorkSite`, `Checkin` |
| `app/Services/MetaGraph.php` | запросы к Graph API (Facebook, Instagram) |
| `database/migrations` | таблицы базы данных |
| `resources/views` | вход, первый запуск, пользователи, программа, страница телефона сотрудника |
| `public/app` | интерфейс программы (JavaScript, CSS); `js/server.js` — связь с сервером |
| `routes/web.php` | все адреса |

### Адреса API
| Адрес | Доступ | Назначение |
|---|---|---|
| `GET/POST /api/state`, `GET /api/state/version` | вход | данные CRM компании (с защитой от перезаписи, 409) |
| `GET /api/inbox?since=` | вход | новые заявки |
| `GET/POST /api/connect`, `/api/connect/check`, `/api/connect/subscribe`, `/api/test` | вход | подключения Meta |
| `POST /api/geo/sync`, `GET /api/geo/events?since=` | вход | ссылки сотрудников, объекты, отметки |
| `GET/POST /webhook/meta/{hook}` | Meta (подпись App Secret) | события Facebook / Instagram / WhatsApp |
| `POST /api/lead?key=` | ключ компании | заявки с сайта, Tilda, Make, Zapier |
| `GET /w?t=`, `GET /api/w/state`, `POST /api/w/event` | личный ключ сотрудника | телефон: «Пришёл / Ушёл» |
