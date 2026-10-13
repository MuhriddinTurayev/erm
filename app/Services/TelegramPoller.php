<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

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
     */
    public function once(int $timeout = 25): int
    {
        if (! self::active()) {
            return 0;
        }
        $lock = Cache::lock('tg-poll', $timeout + 30);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $offset = (int) PlatformSetting::get('telegram_offset', '0');
            $n = 0;
            foreach (Telegram::updates($offset, $timeout) as $u) {
                $offset = max($offset, (int) $u['update_id'] + 1);
                PlatformSetting::put('telegram_offset', (string) $offset);   // сразу, чтобы не обработать дважды
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

    /** Слушать ~$seconds секунд (для запуска планировщиком каждую минуту) */
    public function listen(int $seconds = 55): int
    {
        if (! self::active()) {
            return 0;   // бот не настроен или работает через вебхук — слушать нечего (раньше цикл крутился вхолостую)
        }
        $end = time() + $seconds;
        $n = 0;
        while (time() < $end - 2) {
            $t0 = microtime(true);
            try {
                $n += $this->once(min(25, max(1, $end - time() - 2)));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Telegram poll: '.$e->getMessage());
            }
            // нет связи, ошибка или опрос занят другим процессом — подождать, а не крутить процессор
            if (microtime(true) - $t0 < 1) {
                sleep(3);
            }
            if (! self::active()) {
                break;
            }
        }

        return $n;
    }
}
