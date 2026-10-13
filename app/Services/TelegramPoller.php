<?php

namespace App\Services;

use App\Models\PlatformSetting;

/** Получение сообщений боту без вебхука (на компьютере без https): getUpdates с запоминанием смещения */
class TelegramPoller
{
    public function __construct(private TelegramBot $bot) {}

    public function once(int $timeout = 25): int
    {
        if (! Telegram::enabled() || PlatformSetting::get('telegram_mode') === 'webhook') {
            return 0;
        }
        $offset = (int) PlatformSetting::get('telegram_offset', '0');
        $n = 0;
        foreach (Telegram::updates($offset, $timeout) as $u) {
            $offset = max($offset, (int) $u['update_id'] + 1);
            PlatformSetting::put('telegram_offset', (string) $offset);   // сразу, чтобы не обработать дважды
            $this->bot->handle($u);
            $n++;
        }

        return $n;
    }

    /** Слушать ~$seconds секунд (для запуска планировщиком каждую минуту) */
    public function listen(int $seconds = 55): int
    {
        $end = time() + $seconds;
        $n = 0;
        while (time() < $end - 2) {
            $n += $this->once(min(25, max(1, $end - time() - 2)));
        }

        return $n;
    }
}
