<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Models\User;
use App\Models\Workspace;

/** Запросы компаний к ИИ: выбор подключения, лимиты тарифа, учёт расхода */
class AiService
{
    public function __construct(private AiGateway $gateway) {}

    /** Подключение для компании: выбранное ею, иначе — основное */
    public function providerFor(?Workspace $ws, ?int $wantedId = null): ?AiProvider
    {
        foreach ([$wantedId, $ws?->ai_provider_id] as $id) {
            if ($id && ($p = AiProvider::active()->whereKey($id)->first())) {
                return $p;
            }
        }

        return AiProvider::default();
    }

    /** Проверка тарифа перед запросом из чата */
    public function assertAllowed(Workspace $ws): void
    {
        if (! $ws->aiAllowed()) {
            throw new AiException(__('ИИ не входит в ваш тариф. Перейдите на тариф выше.'), 403);
        }
        $limit = $ws->aiLimit();
        if ($limit > 0 && $ws->aiUsedThisMonth() >= $limit) {
            throw new AiException(__('Лимит запросов к ИИ на этот месяц исчерпан (:n). Перейдите на тариф выше.', ['n' => $limit]), 429);
        }
    }

    /**
     * Прочитать чек/накладную с фото: сумма, продавец, что куплено, дата. null — не удалось.
     *
     * @return array{amount: float, who: string, desc: string, date: ?string}|null
     */
    public function readReceipt(Workspace $ws, ?User $user, string $bytes, string $mime): ?array
    {
        if (! $ws->aiAllowed()) {
            return null;
        }
        $p = $this->providerFor($ws);
        if ($p && $p->driver === 'deepseek') {   // DeepSeek не читает картинки — берём другое подключение
            $p = AiProvider::active()->where('driver', '!=', 'deepseek')->first();
        }
        if (! $p) {
            return null;
        }
        $prompt = 'Это фото чека, накладной или счёта на строительный материал/услугу. Найди итоговую сумму к оплате. '
            .'Ответь ТОЛЬКО JSON без пояснений: {"amount": число (итог, без пробелов), "currency": "UZS|TJS|USD|RUB", "who": "продавец/поставщик", "desc": "что купили, коротко", "date": "YYYY-MM-DD или null"}. '
            .'Если суммы не видно — {"amount": 0}.';
        $t = microtime(true);
        $log = ['workspace_id' => $ws->id, 'user_id' => $user?->id, 'ai_provider_id' => $p->id, 'driver' => $p->driver, 'model' => $p->model, 'kind' => 'receipt'];
        try {
            $r = $this->gateway->vision($p, $prompt, $bytes, $mime);
        } catch (AiException $e) {
            AiUsage::create($log + ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500), 'ms' => (int) ((microtime(true) - $t) * 1000)]);

            return null;
        }
        AiUsage::create(['model' => $r['model'] ?? $p->model] + $log + ['ok' => true, 'input_tokens' => $r['in'], 'output_tokens' => $r['out'], 'ms' => (int) ((microtime(true) - $t) * 1000)]);
        if (! preg_match('/\{.*\}/s', $r['text'], $m)) {
            return null;
        }
        $j = json_decode($m[0], true);
        $amount = (float) preg_replace('/[^\d.]/', '', (string) ($j['amount'] ?? 0));
        if ($amount <= 0) {
            return null;
        }

        return ['amount' => $amount, 'who' => mb_substr((string) ($j['who'] ?? ''), 0, 120), 'desc' => mb_substr((string) ($j['desc'] ?? ''), 0, 200),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($j['date'] ?? '')) ? $j['date'] : null];
    }

    /**
     * Вопрос по картинке с учётом расхода (фасад дома для привязки квартир). null — ИИ не подключён или не ответил.
     */
    public function see(Workspace $ws, ?User $user, string $kind, string $prompt, string $bytes, string $mime, int $maxTokens = 800): ?string
    {
        $p = $this->providerFor($ws);
        if ($p && $p->driver === 'deepseek') {   // DeepSeek не видит картинки — берём другое подключение
            $p = AiProvider::active()->where('driver', '!=', 'deepseek')->first();
        }
        if (! $p) {
            return null;
        }
        $t = microtime(true);
        $log = ['workspace_id' => $ws->id, 'user_id' => $user?->id, 'ai_provider_id' => $p->id, 'driver' => $p->driver, 'model' => $p->model, 'kind' => $kind];
        try {
            $r = $this->gateway->vision($p, $prompt, $bytes, $mime, $maxTokens);
        } catch (AiException $e) {
            AiUsage::create($log + ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500), 'ms' => (int) ((microtime(true) - $t) * 1000)]);

            return null;
        }
        AiUsage::create(['model' => $r['model'] ?? $p->model] + $log + ['ok' => true, 'input_tokens' => $r['in'], 'output_tokens' => $r['out'], 'ms' => (int) ((microtime(true) - $t) * 1000)]);

        return $r['text'];
    }

    /** @return array{text: string, provider: array} */
    public function ask(?Workspace $ws, ?User $user, string $kind, string $system, array $messages, ?int $providerId = null, ?int $maxTokens = null): array
    {
        // проверка подключения администратором — только выбранная модель, без запасных
        $strict = $kind === 'test';

        return $this->each($ws, $user, $kind, $providerId, $strict,
            fn (AiProvider $p) => $this->gateway->chat($p, $system, $messages, $maxTokens, ! $strict));
    }

    /**
     * Ответ по кусочкам (чат): $onText('кусок') — сразу показать пользователю; вернул false — пользователь ушёл.
     *
     * @return array{text: string, provider: array}
     */
    public function stream(?Workspace $ws, ?User $user, string $kind, string $system, array $messages, ?int $providerId, callable $onText): array
    {
        $sent = false;
        $emit = function (string $t) use (&$sent, $onText) {
            $sent = true;

            return $onText($t);
        };

        return $this->each($ws, $user, $kind, $providerId, false,
            fn (AiProvider $p) => $this->gateway->stream($p, $system, $messages, $emit), function () use (&$sent) {
                return ! $sent;
            });
    }

    /**
     * Спросить выбранное подключение; не ответило (лимит, перегрузка, нет связи, кончились деньги) —
     * следующее включённое подключение платформы. Каждая попытка — в журнал расхода.
     */
    private function each(?Workspace $ws, ?User $user, string $kind, ?int $providerId, bool $strict, callable $call, ?callable $canSpare = null): array
    {
        $first = $this->providerFor($ws, $providerId);
        if (! $first) {
            throw new AiException(__('ИИ ещё не подключён. Администратор платформы должен добавить ключ Gemini, ChatGPT, DeepSeek или Claude.'), 503);
        }
        $list = $strict ? collect([$first]) : collect([$first])->merge(AiProvider::active()->whereKeyNot($first->id)->get());
        $last = null;
        foreach ($list as $p) {
            $t = microtime(true);
            $log = ['workspace_id' => $ws?->id, 'user_id' => $user?->id, 'ai_provider_id' => $p->id, 'driver' => $p->driver, 'model' => $p->model, 'kind' => $kind];
            try {
                $r = $call($p);
            } catch (AiException $e) {
                AiUsage::create($log + ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500), 'ms' => (int) ((microtime(true) - $t) * 1000)]);
                $last = $e;
                // отказ отвечать на вопрос другой сервис не исправит; начатый ответ не начинаем заново
                if ($e->status === 422 || ($canSpare && ! $canSpare())) {
                    throw $e;
                }

                continue;
            }
            AiUsage::create(['model' => $r['model'] ?? $p->model] + $log + ['ok' => true, 'input_tokens' => $r['in'], 'output_tokens' => $r['out'], 'ms' => (int) ((microtime(true) - $t) * 1000)]);

            return ['text' => $r['text'], 'provider' => $p->publicInfo()];
        }

        throw $last;
    }
}
