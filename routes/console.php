<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\DailyReportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 | php artisan oftob:user email@mail.uz пароль --name="Имя" --role=admin --company="Название компании"
 | Создаёт пользователя. Если компании ещё нет — создаёт и её.
 | --role=superadmin — администратор платформы (без компании).
 */
Artisan::command('oftob:user {email} {password} {--name=Руководитель} {--role=admin} {--company=}', function (string $email, string $password) {
    $role = $this->option('role');
    if ($role !== 'superadmin' && ! array_key_exists($role, config('oftob.roles'))) {
        $this->error('Роль: superadmin, '.implode(', ', array_keys(config('oftob.roles'))));

        return 1;
    }
    if (User::byEmail($email)) {
        $this->error('Такой email уже есть');

        return 1;
    }
    $ws = null;
    if ($role !== 'superadmin') {
        $ws = $this->option('company')
            ? Workspace::firstOrCreate(['name' => $this->option('company')], ['version' => 0])
            : (Workspace::first() ?? Workspace::create(['name' => 'Моя строительная компания', 'version' => 0]));
    }
    User::create(['workspace_id' => $ws?->id, 'name' => $this->option('name'), 'email' => $email, 'password' => $password, 'role' => $role]);
    $this->info("Готово: {$email} ({$role})".($ws ? " в компании «{$ws->name}»" : ''));

    return 0;
})->purpose('Создать пользователя Oftob_Sohtumon');

/* Забыли пароль (например, владельца платформы): php artisan oftob:password email@mail.uz НовыйПароль — «запомнить меня» на других устройствах сбрасывается */
Artisan::command('oftob:password {email} {password}', function (string $email, string $password) {
    $u = User::byEmail($email);
    if (! $u) {
        $this->error('Такого email нет');

        return 1;
    }
    if (mb_strlen($password) < 8) {
        $this->error('Пароль — не короче 8 символов');

        return 1;
    }
    $u->forceFill(['password' => $password, 'remember_token' => \Illuminate\Support\Str::random(60)])->save();
    $this->info("Готово: пароль для {$email} изменён");

    return 0;
})->purpose('Сменить пароль пользователя');

/* Ежедневные отчёты руководителям: проверка каждую минуту, отправка в выбранное компанией время */
Artisan::command('oftob:reports', function (DailyReportService $svc) {
    $n = $svc->runDue();
    if ($n) {
        $this->info("Отправлено отчётов: {$n}");
    }
})->purpose('Отправить ежедневные отчёты, у которых наступило время');

Schedule::command('oftob:reports')->everyMinute()->withoutOverlapping();

/* Telegram-бот без вебхука: слушает сообщения ~55 секунд, планировщик запускает каждую минуту */
Artisan::command('oftob:telegram {--once}', function (\App\Services\TelegramPoller $poller) {
    $n = $this->option('once') ? $poller->once(0) : $poller->listen(55);
    if ($n) {
        $this->info("Обработано сообщений: {$n}");
    }
})->purpose('Получить сообщения Telegram-бота (режим без вебхука)');

/* Напоминания: покупателям о платежах, исполнителям о задачах, бухгалтерии об оплатах */
Artisan::command('oftob:remind', function (\App\Services\Reminders $r) {
    $n = $r->run();
    if ($n) {
        $this->info("Отправлено напоминаний: {$n}");
    }
})->purpose('Ежедневные напоминания в Telegram');

Schedule::command('oftob:telegram')->everyMinute()->withoutOverlapping(2)->runInBackground();
Schedule::command('oftob:remind')->everyFifteenMinutes()->withoutOverlapping();
// очередь (отправка вебхуков): там, где нет отдельного обработчика очереди (OSPanel + bot.bat), её разбирает планировщик
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping(2)->runInBackground();

/* Демо-компании для проверки: php artisan oftob:demo (пересоздаёт только демо-компании, ваши данные не трогает) */
Artisan::command('oftob:demo', function () {
    $this->call('db:seed', ['--class' => \Database\Seeders\DemoSeeder::class, '--force' => true]);
})->purpose('Создать / пересоздать демо-компании Navruz Qurilish и Pamir Stroy');
