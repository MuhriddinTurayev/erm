<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Api\Webhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Отправка вебхука; не получилось — повтор через 30 с, 2 мин, 10 мин, 30 мин, 1 ч */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    private const PAUSES = [30, 120, 600, 1800, 3600];

    public int $tries = 6;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $d = WebhookDelivery::find($this->deliveryId);
        if (! $d || $d->ok) {
            return;
        }
        if (! Webhooks::deliver($d) && $this->attempts() < $this->tries) {
            $this->release(self::PAUSES[$this->attempts() - 1] ?? 3600);
        }
    }
}
