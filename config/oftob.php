<?php

return [
    // Мини-приложение Telegram «Пришёл / Ушёл» (docs/tg/geo.html) на https; пусто — свой сайт на https или GitHub Pages
    'tg_geo_url' => env('TG_GEO_URL', ''),

    // Версия Graph API (Facebook / Instagram / WhatsApp)
    'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),

    // Роли пользователей компании (названия — ключи перевода)
    'roles' => [
        'admin' => 'Руководитель (всё)',
        // заводит объекты, корпуса, подъезды, квартиры, планировки, цены, 3D и панорамы — готовит объект к продаже; денег и сделок не видит
        'catalog' => 'Администратор объектов',
        'accountant' => 'Бухгалтер',
        'cashier' => 'Кассир',
        'manager' => 'Менеджер по продажам',
        'foreman' => 'Прораб',
        'viewer' => 'Только просмотр',
    ],

    // Какие разделы программы видит роль (null — все)
    'role_pages' => [
        'admin' => null,
        'viewer' => null,
        'accountant' => ['home', 'objects', 'cash', 'dashboard', 'finance', 'smeta', 'accounting', 'fleet', 'materials', 'staff', 'attend', 'labour',
            'sell', 'sales', 'schedule', 'legal', 'ai'],
        'cashier' => ['home', 'objects', 'cash', 'accounting'],
        'catalog' => ['home', 'objects', 'build', 'project', 'sell', 'apt', 'design', 'site', 'ai'],
        'manager' => ['home', 'objects', 'sell', 'leads', 'sales', 'apt', 'design', 'site', 'schedule', 'ai'],
        'foreman' => ['home', 'objects', 'build', 'project', 'prep', 'ground', 'geology', 'pit', 'attend', 'staff', 'labour', 'materials', 'fleet',
            'hiring', 'safety', 'schedule', 'site', 'ai'],
    ],

    // Разделы ERP (панель компании): ключ => [название, иконка]
    'modules' => [
        'monitoring' => ['Мониторинг', 'gauge'],
        'objects' => ['Объекты и 3D', 'building-2'],
        'sales' => ['Продажи квартир', 'key-round'],
        'cash' => ['Кассы и счета', 'wallet'],
        'payments' => ['Заявки на оплату', 'file-check'],
        'forecast' => ['Прогноз денег', 'trending-up'],
        'counterparties' => ['Контрагенты', 'contact'],
        'contracts' => ['Договоры', 'file-pen-line'],
        'warehouse' => ['Склад', 'warehouse'],
        'supply' => ['Снабжение', 'truck'],
        'tenders' => ['Тендеры', 'gavel'],
        'tasks' => ['Задачи', 'list-checks'],
        'services' => ['Сервисные заявки', 'wrench'],
        'journal' => ['Журнал и фотоотчёты', 'camera'],
        'approvals' => ['Согласование расходов', 'shield-check'],
    ],

    // Какие разделы ERP видит роль по умолчанию (руководитель компании может изменить)
    'role_modules' => [
        'viewer' => ['objects', 'sales', 'monitoring', 'cash', 'payments', 'forecast', 'counterparties', 'contracts', 'warehouse', 'supply', 'tenders', 'tasks', 'services', 'journal', 'approvals'],
        'accountant' => ['objects', 'sales', 'monitoring', 'cash', 'payments', 'forecast', 'counterparties', 'contracts', 'warehouse', 'supply', 'tenders', 'tasks', 'services', 'journal', 'approvals'],
        'cashier' => ['objects', 'sales', 'cash', 'payments', 'counterparties', 'tasks', 'approvals'],
        'catalog' => ['objects', 'tasks'],   // шахматку с ценами и статусами видит в «Объекты → Помещения»; деньги продаж — нет
        'manager' => ['objects', 'sales', 'counterparties', 'contracts', 'tasks', 'services', 'journal'],
        'foreman' => ['objects', 'payments', 'warehouse', 'supply', 'tasks', 'services', 'journal'],
    ],

    // Права администраторов платформы, которых назначает владелец
    'admin_perms' => [
        'companies' => 'Компании и вход в их кабинет',
        'payments' => 'Оплаты и продление тарифов',
        'plans' => 'Тарифы',
        'ai' => 'ИИ-подключения',
        'site' => 'Сайт: тексты, блог, SEO',
        'settings' => 'Настройки платформы и Telegram',
        'api' => 'API: ключи компаниям и партнёрам',
    ],

    // Языки интерфейса
    // порядок — как в переключателе языка: таджикский первым
    'locales' => [
        'tg' => ['Тоҷикӣ', 'TJ'],
        'ru' => ['Русский', 'RU'],
        'uz' => ["O'zbekcha", 'UZ'],
    ],

    'timezones' => [
        'Asia/Tashkent' => 'Ташкент (UTC+5)',
        'Asia/Samarkand' => 'Самарканд (UTC+5)',
        'Asia/Dushanbe' => 'Душанбе (UTC+5)',
        'Asia/Almaty' => 'Алматы (UTC+5)',
        'Asia/Bishkek' => 'Бишкек (UTC+6)',
        'Europe/Moscow' => 'Москва (UTC+3)',
    ],

    // Максимальный размер данных одной компании (байт)
    'max_state_bytes' => 25 * 1024 * 1024,
];
