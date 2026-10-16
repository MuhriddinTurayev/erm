<?php

namespace App\Services\Ai;

/**
 * Ошибка сервиса ИИ с понятным пользователю текстом.
 * $cool > 0 — модель временно недоступна (лимит, перегрузка): на столько секунд её пропускаем и берём запасную.
 */
class AiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502, public readonly int $cool = 0)
    {
        parent::__construct($message);
    }
}
