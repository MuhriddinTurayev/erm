<?php

namespace App\Services\Ai;

/** Ошибка сервиса ИИ с понятным пользователю текстом */
class AiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502)
    {
        parent::__construct($message);
    }
}
