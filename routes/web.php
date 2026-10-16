<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Company;
use App\Http\Controllers\GeoController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;

/* ---------- Сайт ---------- */
Route::get('/', [SiteController::class, 'home']);
Route::get('/blog', [SiteController::class, 'blog']);
Route::get('/blog/{slug}', [SiteController::class, 'post'])->where('slug', '[a-z0-9\-]+');
Route::get('/privacy', fn () => app(SiteController::class)->page('privacy'));
Route::get('/terms', fn () => app(SiteController::class)->page('terms'));
Route::get('/sitemap.xml', [SiteController::class, 'sitemap']);
Route::get('/robots.txt', [SiteController::class, 'robots']);
Route::get('/manifest.webmanifest', [SiteController::class, 'manifest']);
Route::post('/demo', [SiteController::class, 'demo'])->middleware('throttle:6,1');   // запись на бесплатный разбор
// документация API для разработчика, которому выдали ключ (без входа)
Route::get('/developers', fn () => view('developers', ['base' => url('/api/v1'), 'endpoints' => \App\Services\Api\Spec::endpoints()]));

/* ---------- Вход и регистрация ---------- */
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/register', [AuthController::class, 'showRegister']);
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:login');
    Route::get('/setup', [AuthController::class, 'showSetup']);
    Route::post('/setup', [AuthController::class, 'setup'])->middleware('throttle:login');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

/* ---------- Вход через Google и Telegram ---------- */
Route::middleware('throttle:login')->group(function () {
    Route::get('/auth/google', [\App\Http\Controllers\SocialController::class, 'google']);
    Route::get('/auth/google/callback', [\App\Http\Controllers\SocialController::class, 'googleCallback']);
    Route::get('/auth/telegram', [\App\Http\Controllers\SocialController::class, 'telegram']);
});

/* ---------- Безопасность аккаунта (любой пользователь) ---------- */
Route::middleware('auth')->prefix('security')->group(function () {
    Route::get('/', [\App\Http\Controllers\SecurityController::class, 'index']);
    Route::post('/sessions/others', [\App\Http\Controllers\SecurityController::class, 'revokeOthers']);
    Route::post('/sessions/{id}', [\App\Http\Controllers\SecurityController::class, 'revoke']);
    Route::post('/password', [\App\Http\Controllers\SecurityController::class, 'password'])->middleware('throttle:login');
    Route::post('/alerts', [\App\Http\Controllers\SecurityController::class, 'alerts']);
    Route::post('/google/unlink', [\App\Http\Controllers\SecurityController::class, 'unlinkGoogle']);
});
Route::post('/impersonate/stop', [AuthController::class, 'stopImpersonate'])->middleware('auth');

/* ---------- Внешние адреса (без входа, своя защита) ---------- */
Route::middleware('throttle:public')->group(function () {
    // Facebook / Instagram / WhatsApp: адрес вебхука свой у каждой компании
    Route::get('/webhook/meta/{hook}', [LeadController::class, 'metaVerify']);
    Route::post('/webhook/meta/{hook}', [LeadController::class, 'metaEvent']);
    // Заявки с сайта, Tilda, Make, Zapier
    Route::match(['post', 'options'], '/api/lead', [LeadController::class, 'siteLead']);
    // Телефон сотрудника: страница и отметки «Пришёл / Ушёл»
    // Telegram-бот (вебхук на хостинге)
    Route::post('/webhook/telegram/{secret}', [TelegramController::class, 'webhook']);
    // один бот на двух сайтах: второй сайт (копия на компьютере) забирает здесь свои сообщения (запрос подписан ключом бота)
    Route::post('/webhook/tg-relay', [TelegramController::class, 'relay']);
    // мини-приложение Telegram «Пришёл / Ушёл» (то же, что на GitHub Pages) — когда сайт на https, кнопка откроет его отсюда
    Route::get('/tg/geo', [TelegramController::class, 'geoApp']);
    // Шахматка для сайта застройщика и кабинет покупателя
    Route::get('/s/{key}', [PublicController::class, 'chess']);
    Route::post('/s/{key}', [PublicController::class, 'chessLead'])->middleware('throttle:10,1');
    Route::get('/b/{token}', [PublicController::class, 'cabinet']);
    Route::get('/b/{token}/3d', [PublicController::class, 'cabinet3d']);
    Route::get('/b/{token}/locate', [PublicController::class, 'cabinetLocate']);
    Route::get('/b/{token}/m/{id}', [PublicController::class, 'cabinetMedia'])->whereNumber('id');
    Route::get('/s/{key}/3d', [PublicController::class, 'chess3d']);
    Route::get('/s/{key}/locate', [PublicController::class, 'chessLocate']);
    Route::get('/s/{key}/m/{id}', [PublicController::class, 'chessMedia'])->whereNumber('id');
    Route::get('/b/{token}/photo/{log}/{i}', [PublicController::class, 'cabinetPhoto'])->whereNumber('i');
    Route::get('/w', [GeoController::class, 'page']);
    Route::get('/api/w/state', [GeoController::class, 'workerState']);
    Route::post('/api/w/event', [GeoController::class, 'workerEvent']);
});

/* ---------- Программа компании ---------- */
Route::middleware(['auth', 'role:company'])->group(function () {
    Route::post('/api/locale', [AppController::class, 'locale']);

    Route::middleware('subscription')->group(function () {
        Route::get('/crm', [AppController::class, 'index']);

        Route::get('/api/state/version', [StateController::class, 'version']);
        Route::get('/api/state', [StateController::class, 'show']);
        Route::post('/api/state', [StateController::class, 'update']);
        Route::post('/api/ai/chat', [AiController::class, 'chat'])->middleware('throttle:30,1');

        Route::get('/api/inbox', [LeadController::class, 'inbox']);
        Route::get('/api/connect', [LeadController::class, 'connect']);
        Route::post('/api/connect', [LeadController::class, 'connectSave']);
        Route::post('/api/connect/check', [LeadController::class, 'connectCheck']);
        Route::post('/api/connect/subscribe', [LeadController::class, 'connectSubscribe']);
        Route::post('/api/test', [LeadController::class, 'test']);

        Route::post('/api/geo/sync', [GeoController::class, 'sync']);
        Route::get('/api/geo/events', [GeoController::class, 'events']);
        Route::post('/api/geo/close', [GeoController::class, 'close']);   // руководитель закрывает смену сотрудника

        // Telegram сотрудника, журнал работ, согласование, файлы
        Route::get('/me/telegram', [TelegramController::class, 'me']);
        Route::post('/me/telegram/check', [TelegramController::class, 'check']);
        Route::post('/me/telegram/unlink', [TelegramController::class, 'unlink']);
        Route::get('/company/journal', [Company\OpsController::class, 'journal']);
        Route::get('/company/approvals', [Company\OpsController::class, 'approvals']);
        Route::post('/company/approvals/{expense}', [Company\OpsController::class, 'decide']);
        Route::get('/files/receipt/{expense}', [Company\OpsController::class, 'receipt']);
        Route::get('/files/log/{log}/{i}', [Company\OpsController::class, 'logPhoto'])->whereNumber('i');
        Route::post('/api/expenses', [Company\OpsController::class, 'apiExpense']);
        Route::get('/api/expenses/pending', [Company\OpsController::class, 'apiPending']);
        Route::post('/api/buyer-link', [Company\OpsController::class, 'apiBuyerLink']);

        // ERP: мониторинг, деньги, контрагенты, склад, снабжение, задачи
        Route::prefix('company')->group(function () {
            Route::middleware('module:monitoring')->group(function () {
                Route::get('/monitor', [Company\MonitorController::class, 'index']);
                Route::post('/monitor/widgets', [Company\MonitorController::class, 'saveWidgets']);
            });
            Route::get('/forecast', [Company\MonitorController::class, 'forecast'])->middleware('module:forecast');
            // ИИ-анализ раздела: правила сразу, разбор ИИ — вторым запросом (права на раздел — в контроллере)
            Route::get('/insights/{topic}', [Company\InsightController::class, 'show'])->whereIn('topic', \App\Services\Ai\Insights::TOPICS)->middleware('throttle:40,1');
            Route::post('/journal/photo', [Company\MonitorController::class, 'photo'])->middleware('module:journal');

            // объекты: каждый отдельно — команда, учётные записи, 3D-модели и панорамы
            Route::middleware('module:objects')->group(function () {
                Route::get('/objects', [Company\ObjectController::class, 'index']);
                // объекты и перечень помещений ведут руководитель и администратор объектов (проверка — в контроллере)
                Route::post('/objects', [Company\InventoryController::class, 'create']);
                Route::post('/objects/{pid}/info', [Company\InventoryController::class, 'info']);
                Route::delete('/objects/{pid}', [Company\InventoryController::class, 'destroy']);   // только руководитель, подтверждение — название
                Route::post('/objects/{pid}/inventory', [Company\InventoryController::class, 'save']);
                Route::post('/objects/{pid}/inventory/convert', [Company\InventoryController::class, 'convert']);
                Route::post('/objects/{pid}/inventory/undo', [Company\InventoryController::class, 'undo']);
                Route::post('/objects/{pid}/inventory/ready', [Company\InventoryController::class, 'ready']);
                Route::post('/objects/{pid}/units/{uid}', [Company\InventoryController::class, 'unit']);   // комнаты и площадь квартиры — из привязки к 3D
                Route::get('/objects/{pid}', [Company\ObjectController::class, 'show']);
                Route::get('/objects/{pid}/3d', [Company\ObjectController::class, 'viewer']);
                Route::get('/objects/{pid}/interiors', [Company\ObjectController::class, 'interiors']);   // интерьеры по планировкам: загрузить, поправить, удалить
                Route::post('/objects/{pid}/interiors/same', [Company\ObjectController::class, 'interiorsSame']);   // интерьер квартиры — такой же у отмеченных
                // квартира на 3D-модели дома; привязка корпусов к модели
                Route::get('/objects/{pid}/locate', [Company\LocateController::class, 'show']);
                Route::post('/media/{media}/locate', [Company\LocateController::class, 'save']);
                Route::post('/media/{media}/locate/ai', [Company\LocateController::class, 'ai'])->middleware('throttle:12,1');
                Route::post('/objects/{pid}/account', [Company\ObjectController::class, 'account']);
                Route::post('/objects/{pid}/users/{user}/password', [Company\ObjectController::class, 'resetPassword']);
                Route::post('/objects/{pid}/media', [Company\ObjectController::class, 'mediaUpload']);
                Route::post('/media/{media}', [Company\ObjectController::class, 'mediaUpdate']);
                Route::delete('/media/{media}', [Company\ObjectController::class, 'mediaDelete']);
                Route::post('/media/{media}/patch', [Company\ObjectController::class, 'mediaPatch']);   // название, комната, планировка — из 3D и «Дизайна»
                Route::get('/media/{media}/file', [Company\ObjectController::class, 'mediaFile']);
            });
            Route::middleware('module:sales')->group(function () {
                Route::get('/sales', [Company\SalesController::class, 'index']);
                Route::post('/sales/{pid}/apt/{apt}', [Company\SalesController::class, 'save']);
                Route::post('/sales/{pid}/apt/{apt}/pay', [Company\SalesController::class, 'pay']);
                Route::delete('/sales/{pid}/apt/{apt}/pay/{pay}', [Company\SalesController::class, 'payDelete']);
                Route::post('/sales/{pid}/apt/{apt}/link', [Company\SalesController::class, 'buyerLink']);
            });

            Route::middleware('module:cash')->group(function () {
                Route::get('/cash', [Company\CashController::class, 'index']);
                Route::post('/cash', [Company\CashController::class, 'store']);
                Route::post('/cash/transfer', [Company\CashController::class, 'transfer']);
                Route::delete('/cash/{op}', [Company\CashController::class, 'destroy']);
                Route::middleware('role:admin')->group(function () {
                    Route::post('/cash/desks', [Company\CashController::class, 'desks']);
                    Route::post('/cash/categories', [Company\CashController::class, 'categories']);
                    Route::post('/cash/rate', [Company\CashController::class, 'rate']);
                });
            });

            Route::middleware('module:payments')->group(function () {
                Route::get('/payments', [Company\PaymentController::class, 'index']);
                Route::post('/payments', [Company\PaymentController::class, 'store']);
                Route::post('/payments/bulk', [Company\PaymentController::class, 'bulk']);
                Route::post('/payments/chain', [Company\PaymentController::class, 'chain'])->middleware('role:admin');
                Route::get('/payments/{pr}', [Company\PaymentController::class, 'show']);
                Route::post('/payments/{pr}/decide', [Company\PaymentController::class, 'decide']);
                Route::post('/payments/{pr}/pay', [Company\PaymentController::class, 'pay']);
                Route::post('/payments/{pr}/cancel', [Company\PaymentController::class, 'cancel']);
            });

            Route::middleware('module:counterparties')->group(function () {
                Route::get('/parties', [Company\PartyController::class, 'index']);
                Route::post('/parties', [Company\PartyController::class, 'store']);
                Route::get('/parties/{cp}', [Company\PartyController::class, 'show']);
                Route::put('/parties/{cp}', [Company\PartyController::class, 'update']);
                Route::post('/parties/{cp}/status', [Company\PartyController::class, 'status']);
                Route::delete('/parties/{cp}', [Company\PartyController::class, 'destroy']);
            });
            Route::middleware('module:contracts')->group(function () {
                Route::get('/contracts', [Company\PartyController::class, 'contracts']);
                Route::post('/contracts', [Company\PartyController::class, 'contractStore']);
                Route::get('/contracts/{c}', [Company\PartyController::class, 'contractShow']);
                Route::put('/contracts/{c}', [Company\PartyController::class, 'contractUpdate']);
            });

            Route::middleware('module:warehouse')->group(function () {
                Route::get('/stock', [Company\StockController::class, 'index']);
                Route::post('/stock/move', [Company\StockController::class, 'move']);
                Route::post('/stock/items', [Company\StockController::class, 'itemSave']);
                Route::get('/stock/items/{item}', [Company\StockController::class, 'item']);
                Route::post('/stock/warehouses', [Company\StockController::class, 'warehouseSave']);
                Route::get('/stock/labels', [Company\StockController::class, 'labels']);
            });
            Route::middleware('module:supply')->group(function () {
                Route::get('/supply', [Company\StockController::class, 'supply']);
                Route::post('/supply', [Company\StockController::class, 'supplyStore']);
                Route::post('/supply/{sr}', [Company\StockController::class, 'supplyAction']);
                Route::get('/supply/{sr}/photo/{i}', [Company\StockController::class, 'supplyPhoto'])->whereNumber('i');
            });
            Route::middleware('module:tenders')->group(function () {
                Route::get('/tenders', [Company\StockController::class, 'tenders']);
                Route::post('/tenders', [Company\StockController::class, 'tenderStore']);
                Route::get('/tenders/{t}', [Company\StockController::class, 'tender']);
                Route::post('/tenders/{t}/bid', [Company\StockController::class, 'bid']);
                Route::post('/tenders/{t}/award', [Company\StockController::class, 'award']);
            });

            Route::middleware('module:tasks')->group(function () {
                Route::get('/tasks', [Company\WorkController::class, 'tasks']);
                Route::post('/tasks', [Company\WorkController::class, 'taskSave']);
                Route::post('/tasks/bulk', [Company\WorkController::class, 'taskBulk']);
                Route::get('/tasks/{task}', [Company\WorkController::class, 'taskShow']);
                Route::post('/tasks/{task}/status', [Company\WorkController::class, 'taskStatus']);
                Route::delete('/tasks/{task}', [Company\WorkController::class, 'taskDelete']);
            });
            Route::middleware('module:services')->group(function () {
                Route::get('/services', [Company\WorkController::class, 'services']);
                Route::post('/services', [Company\WorkController::class, 'serviceSave']);
                Route::get('/services/{so}', [Company\WorkController::class, 'service']);
            });

            // комментарии и файлы к любой записи
            Route::post('/thread/{type}/{id}/comment', [Company\WorkController::class, 'comment'])->whereNumber('id');
            Route::post('/thread/{type}/{id}/files', [Company\WorkController::class, 'upload'])->whereNumber('id');
            Route::get('/files/{att}', [Company\WorkController::class, 'file']);
            Route::delete('/files/{att}', [Company\WorkController::class, 'fileDelete']);

            // инструкция: кто что вносит
            Route::get('/guide', fn () => view('company.guide'));

            // права ролей на разделы
            Route::post('/roles', [Company\SettingsController::class, 'roles'])->middleware('role:admin');
        });

        // Панель компании: деньги и отчёты
        Route::middleware('role:money')->group(function () {
            Route::get('/company', fn () => redirect('/company/overview'));
            Route::get('/company/overview', [Company\DashboardController::class, 'overview']);
            Route::get('/company/reports', [Company\ReportController::class, 'index']);
            Route::get('/company/reports/{report}', [Company\ReportController::class, 'show']);
            Route::get('/company/reports/{report}/{type}', [Company\ReportController::class, 'file'])->where('type', 'pdf|docx');
        });

        Route::middleware('role:admin')->group(function () {
            Route::post('/company/reports/run', [Company\ReportController::class, 'run']);
            Route::get('/company/settings', [Company\SettingsController::class, 'edit']);
            Route::post('/company/settings', [Company\SettingsController::class, 'update']);
            Route::post('/company/telegram/link', [Company\SettingsController::class, 'telegramLink']);
            Route::post('/company/settings/ops', [Company\SettingsController::class, 'updateOps']);
            Route::post('/company/settings/lists', [Company\SettingsController::class, 'lists']);   // справочники: способы и условия оплаты, источники, единицы
            Route::get('/company/audit', [Company\OpsController::class, 'audit']);
            // API и интеграции: ключи выдаёт администратор платформы; руководитель видит их, просит новый, может срочно отозвать
            Route::get('/company/api', [Company\ApiController::class, 'index']);
            Route::post('/company/api/request', [Company\ApiController::class, 'request'])->middleware('throttle:10,1');
            Route::post('/company/api/tokens/{id}/revoke', [Company\ApiController::class, 'tokenRevoke'])->whereNumber('id');
            Route::get('/company/users', [Company\UserController::class, 'index']);
            Route::post('/company/users', [Company\UserController::class, 'store']);
            Route::put('/company/users/{user}', [Company\UserController::class, 'update']);
            Route::delete('/company/users/{user}', [Company\UserController::class, 'destroy']);
            Route::get('/users', fn () => redirect('/company/users'));
        });
    });

    // тариф доступен и когда подписка закончилась
    Route::get('/company/billing', [Company\DashboardController::class, 'billing']);
    Route::post('/company/billing', [Company\DashboardController::class, 'requestPlan']);
});

/* ---------- Администрирование платформы: владелец и назначенные им администраторы ---------- */
Route::middleware(['auth', 'role:superadmin'])->prefix('admin')->group(function () {
    Route::get('/', [Admin\DashboardController::class, 'index']);

    Route::middleware('perm:companies')->group(function () {
        Route::get('/companies', [Admin\CompanyController::class, 'index']);
        Route::get('/companies/{workspace}', [Admin\CompanyController::class, 'show']);
        Route::put('/companies/{workspace}', [Admin\CompanyController::class, 'update']);
        Route::post('/companies/{workspace}/impersonate', [Admin\CompanyController::class, 'impersonate']);
        Route::post('/companies/{workspace}/users/{user}/role', [Admin\AdminsController::class, 'companyRole']);
        Route::delete('/companies/{workspace}', [Admin\CompanyController::class, 'destroy'])->middleware('perm:owner');
    });
    // заявки с сайта на бесплатный разбор
    Route::middleware('perm:companies')->group(function () {
        Route::get('/leads', [Admin\CompanyController::class, 'leads']);
        Route::post('/leads/{lead}', [Admin\CompanyController::class, 'leadUpdate']);
    });
    Route::middleware('perm:payments')->group(function () {
        Route::post('/companies/{workspace}/pay', [Admin\CompanyController::class, 'pay']);
        Route::get('/payments', [Admin\SettingsController::class, 'payments']);
    });

    Route::middleware('perm:plans')->group(function () {
        Route::get('/plans', [Admin\PlanController::class, 'index']);
        Route::post('/plans', [Admin\PlanController::class, 'store']);
        Route::put('/plans/{plan}', [Admin\PlanController::class, 'update']);
        Route::delete('/plans/{plan}', [Admin\PlanController::class, 'destroy']);
    });

    Route::middleware('perm:ai')->group(function () {
        Route::get('/ai', [Admin\AiProviderController::class, 'index']);
        Route::post('/ai', [Admin\AiProviderController::class, 'store']);
        Route::put('/ai/{provider}', [Admin\AiProviderController::class, 'update']);
        Route::delete('/ai/{provider}', [Admin\AiProviderController::class, 'destroy']);
        Route::post('/ai/{provider}/test', [Admin\AiProviderController::class, 'test']);
    });

    Route::middleware('perm:settings')->group(function () {
        Route::get('/settings', [Admin\SettingsController::class, 'edit']);
        Route::post('/settings', [Admin\SettingsController::class, 'update']);
    });

    // сайт: тексты на трёх языках, правовые страницы, SEO, блог
    Route::middleware('perm:site')->group(function () {
        Route::get('/site', [Admin\SiteContentController::class, 'index']);
        Route::post('/site', [Admin\SiteContentController::class, 'save']);
        Route::post('/site/reset', [Admin\SiteContentController::class, 'reset']);
        Route::post('/site/pages', [Admin\SiteContentController::class, 'pages']);
        Route::post('/site/seo', [Admin\SiteContentController::class, 'seo']);
        Route::get('/posts', [Admin\SiteContentController::class, 'posts']);
        Route::post('/posts', [Admin\SiteContentController::class, 'postSave']);
        Route::get('/posts/{post}', [Admin\SiteContentController::class, 'postEdit']);
        Route::post('/posts/{post}', [Admin\SiteContentController::class, 'postSave']);
        Route::delete('/posts/{post}', [Admin\SiteContentController::class, 'postDelete']);
    });

    // API: ключи компаниям и партнёрам (с ролью в компании), вебхуки ключей, заявки компаний, общий выключатель
    Route::middleware('perm:api')->prefix('api')->group(function () {
        Route::get('/', [Admin\ApiController::class, 'index']);
        Route::get('/company/{workspace}', [Admin\ApiController::class, 'company']);
        Route::post('/toggle', [Admin\ApiController::class, 'toggle']);
        Route::post('/tokens', [Admin\ApiController::class, 'tokenSave']);
        Route::post('/tokens/{token}/revoke', [Admin\ApiController::class, 'tokenRevoke']);
        Route::post('/requests/{workspace}/dismiss', [Admin\ApiController::class, 'requestDismiss']);
        Route::post('/hooks', [Admin\ApiController::class, 'hookSave']);
        Route::post('/hooks/{webhook}/toggle', [Admin\ApiController::class, 'hookToggle']);
        Route::post('/hooks/{webhook}/test', [Admin\ApiController::class, 'hookTest'])->middleware('throttle:20,1');
        Route::post('/hooks/{webhook}/secret', [Admin\ApiController::class, 'hookSecret']);
        Route::delete('/hooks/{webhook}', [Admin\ApiController::class, 'hookDelete']);
        Route::post('/deliveries/{delivery}/retry', [Admin\ApiController::class, 'deliveryRetry'])->middleware('throttle:20,1');
    });

    // администраторы платформы — только владелец
    Route::middleware('perm:owner')->group(function () {
        Route::get('/admins', [Admin\AdminsController::class, 'index']);
        Route::post('/admins', [Admin\AdminsController::class, 'store']);
        Route::put('/admins/{user}', [Admin\AdminsController::class, 'update']);
        Route::delete('/admins/{user}', [Admin\AdminsController::class, 'destroy']);
    });
});
