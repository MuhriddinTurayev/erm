<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Получение сообщений боту без вебхука (на компьютере без https): getUpdates с запоминанием смещения.
 * Один получатель одновременно (блокировка), каждое обновление обрабатывается ровно один раз.
 */
class TelegramPoller
{
    public function __construct(private TelegramBot $bot) {}

    /** Бот работает в режиме опроса (токен есть, вебхук не включён) */
    public static function active(): bool
    {
        return Telegram::enabled() && PlatformSetting::get('telegram_mode') !== 'webhook';
    }

    /**
     * Одно обращение к Telegram. Если сейчас опрашивает другой процесс (планировщик или кнопка «Проверить»),
     * ничего не делаем: иначе оба получат одни и те же сообщения и расход запишется дважды.
     * $force — кнопка на сайте: опросить, даже если этот сайт уступил бота другому.
     */
    public function once(int $timeout = 25, bool $force = false): int
    {
        if (! self::active()) {
            return 0;
        }
        if (TelegramRelay::secondary()) {
            return TelegramRelay::pull($this->bot);   // второй сайт: свои сообщения забирает с основного
        }
        if (! $force && self::yielded()) {
            return 0;
        }
        $lock = Cache::lock('tg-poll', $timeout + 30);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $offset = (int) PlatformSetting::get('telegram_offset', '0');
            $n = 0;
            try {
                $updates = Telegram::updates($offset, $timeout);
            } catch (\RuntimeException $e) {
                // «Conflict: terminated by other getUpdates request» — тот же бот опрашивает другой сайт (сервер или компьютер)
                if (str_contains($e->getMessage(), 'Conflict')) {
                    Cache::put('tg-conflict-at', time(), 86400);
                }
                throw $e;
            }
            foreach ($updates as $u) {
                $offset = max($offset, (int) $u['update_id'] + 1);
                PlatformSetting::put('telegram_offset', (string) $offset);   // сразу, чтобы не обработать дважды
                if (TelegramRelay::forward($u)) {
                    continue;   // относится ко второму сайту (его код или его сотрудник) — он заберёт сам
                }
                if (TelegramBot::firstTime($u)) {
                    $this->bot->handle($u);
                    $n++;
                }
            }

            return $n;
        } finally {
            $lock->release();
        }
    }

    /**
     * Сообщения боту сейчас кто-то получает: планировщик или фоновый процесс слушал в последние 3 минуты.
     * Нет — бот молчит на всё, кроме кнопки «Проверить» на сайте.
     */
    public static function listening(): bool
    {
        return (int) Cache::get('tg-listen-at', 0) > time() - 180;
    }

    /**
     * Тот же бот получает сообщения ещё и на другом сайте. Telegram отдаёт каждое сообщение только одному
     * получателю, поэтому часть сообщений (и коды привязки) уходит туда.
     */
    public static function shared(): bool
    {
        return self::yielded() || (int) Cache::get('tg-conflict-at', 0) > time() - 900;
    }

    /** Копия на компьютере уступила бота основному сайту (тот же токен уже работает на сервере) */
    public static function yielded(): bool
    {
        return (bool) Cache::get('tg-yield');
    }

    /** Слушать ~$seconds секунд (для запуска планировщиком каждую минуту) */
    public function listen(int $seconds = 55): int
    {
        $relay = TelegramRelay::secondary();
        if (! self::active() || (self::yielded() && ! $relay)) {
            return 0;   // бот не настроен, работает через вебхук или его обслуживает другой сайт — слушать нечего
        }
        $end = time() + $seconds;
        $n = 0;
        $conflicts = 0;
        while (time() < $end - 2) {
            Cache::put('tg-listen-at', time(), 86400);   // «бот на связи» — для подсказки на странице «Мой Telegram»
            $t0 = microtime(true);
            try {
                $n += $this->once(min(25, max(1, $end - time() - 2)));
                $conflicts = 0;
            } catch (\Throwable $e) {
                if (Cache::add('tg-warn:'.md5($e->getMessage()), 1, 300)) {   // одна и та же ошибка — в журнал раз в 5 минут
                    Log::warning('Telegram poll: '.$e->getMessage());
                }
                // два сайта с одним ботом перехватывают сообщения друг у друга, и часть теряется.
                // Копия на компьютере уступает: бот остаётся у основного сайта (сервера), здесь — предупреждение
                if (str_contains($e->getMessage(), 'Conflict') && app()->environment('local') && ++$conflicts >= 2) {
                    Cache::put('tg-yield', time(), 86400);   // сутки; свой токен в настройках снимает сразу
                    Cache::forget('tg-listen-at');
                    break;
                }
            }
            // нет связи, ошибка или опрос занят другим процессом — подождать, а не крутить процессор;
            // второй сайт забирает сообщения с основного каждые 2 секунды
            if (microtime(true) - $t0 < 1) {
                sleep($relay ? 2 : 3);
            }
            if (! self::active()) {
                break;
            }
        }

        return $n;
    }

    /* ---------- компьютер (Open Server): фоновый процесс вместо bot.bat ---------- */

    /** Планировщик (schedule:work на сервере или bot.bat) или фоновый процесс работает */
    public static function workerAlive(): bool
    {
        return (int) Cache::get('sched-at', 0) > time() - 150 || (int) Cache::get('worker-at', 0) > time() - 150;
    }

    /** Фоновый процесс запускается сам: на компьютере с Windows, если ни планировщика, ни процесса нет */
    public static function autoMode(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Сайт открыли, а приёмом сообщений бота, отчётами и напоминаниями никто не занимается —
     * запускаем фоновый процесс без окна (раньше для этого нужно было вручную запускать bot.bat).
     */
    public static function autostart(): void
    {
        if (! self::autoMode() || self::workerAlive() || ! Cache::add('worker-spawn', 1, 60)) {
            return;   // на сервере работает служба-планировщик; запускаем не чаще раза в минуту
        }
        self::spawnWorker();
    }

    /** Запустить `artisan oftob:worker` отдельным процессом без окна; он переживает окончание запроса */
    public static function spawnWorker(): bool
    {
        $php = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'php-win.exe';   // PHP без консольного окна
        if (! is_file($php)) {
            return false;
        }
        try {
            $null = ['file', 'NUL', 'w'];
            $p = proc_open([$php, base_path('artisan'), 'oftob:worker'], [0 => ['file', 'NUL', 'r'], 1 => $null, 2 => $null], $pipes, base_path(), null,
                ['bypass_shell' => true, 'create_new_console' => true]);

            return is_resource($p);
        } catch (\Throwable $e) {
            Log::warning('Telegram worker start: '.$e->getMessage());

            return false;
        }
    }
}
