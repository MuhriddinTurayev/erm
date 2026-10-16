<?php

namespace App\Services\Ai;

use Anthropic\Client as AnthropicClient;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawMessageDeltaEvent;
use Anthropic\Messages\RawMessageStartEvent;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\TextDelta;
use App\Models\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Один вход для всех сервисов ИИ: Gemini, ChatGPT, DeepSeek, Claude и OpenAI-совместимые.
 * $messages — [['role' => 'user'|'assistant', 'content' => 'текст'], …]
 *
 * У Gemini лимиты бесплатного ключа — свои у каждой модели (у gemini-2.5-flash всего 20 запросов в день).
 * Кончился лимит или модель перегружена — запоминаем это и сразу берём следующую модель того же ключа.
 *
 * @return array{text: string, in: int, out: int, model: string}
 */
class AiGateway
{
    private const TIMEOUT = 120;

    private const GEMINI = 'https://generativelanguage.googleapis.com/v1beta';

    /** Запасные модели Gemini, если список моделей ключа получить не удалось */
    private const GEMINI_SPARE = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-flash-latest', 'gemini-flash-lite-latest'];

    /** Сколько моделей пробуем за один вопрос */
    private const TRIES = 6;

    public function chat(AiProvider $p, string $system, array $messages, ?int $maxTokens = null, bool $spare = true): array
    {
        $this->needKey($p);
        $messages = $this->normalize($messages);
        $max = $maxTokens ?: (int) $p->max_tokens ?: 4000;

        return $this->models($p, $spare, fn (string $m) => match ($p->driver) {
            'gemini' => $this->gemini($p, $m, $system, $messages, $max),
            'anthropic' => $this->anthropic($p, $m, $system, $messages, $max),
            'openai' => $this->openaiLike($p, $m, 'https://api.openai.com/v1', $system, $messages, $max, 'max_completion_tokens'),
            'deepseek' => $this->openaiLike($p, $m, 'https://api.deepseek.com/v1', $system, $messages, $max, 'max_tokens'),
            'compatible' => $this->openaiLike($p, $m, (string) $p->base_url, $system, $messages, $max, 'max_tokens'),
            default => throw new AiException('Unknown AI driver: '.$p->driver, 500),
        });
    }

    /**
     * Ответ по кусочкам — как печатает человек: $onText('кусок') вызывается, как только кусок пришёл.
     * $onText вернул false — пользователь ушёл, дальше не читаем.
     * На запасную модель переходим, только пока пользователю ещё ничего не показали.
     */
    public function stream(AiProvider $p, string $system, array $messages, callable $onText, ?int $maxTokens = null, bool $spare = true): array
    {
        $this->needKey($p);
        $messages = $this->normalize($messages);
        $max = $maxTokens ?: (int) $p->max_tokens ?: 4000;
        $sent = false;
        $emit = function (string $t) use (&$sent, $onText) {
            $sent = true;

            return $onText($t);
        };

        return $this->models($p, $spare, fn (string $m) => match ($p->driver) {
            'gemini' => $this->geminiStream($p, $m, $system, $messages, $max, $emit),
            'anthropic' => $this->anthropicStream($p, $m, $system, $messages, $max, $emit),
            'openai' => $this->openaiStream($p, $m, 'https://api.openai.com/v1', $system, $messages, $max, 'max_completion_tokens', true, $emit),
            'deepseek' => $this->openaiStream($p, $m, 'https://api.deepseek.com/v1', $system, $messages, $max, 'max_tokens', true, $emit),
            'compatible' => $this->openaiStream($p, $m, (string) $p->base_url, $system, $messages, $max, 'max_tokens', false, $emit),
            default => throw new AiException('Unknown AI driver: '.$p->driver, 500),
        }, function () use (&$sent) {
            return ! $sent;
        });
    }

    /**
     * Вопрос по картинке (чек, накладная, фасад дома). DeepSeek картинки не понимает.
     * $maxTokens — запас на ответ: «думающим» моделям (Gemini 2.5) на разбор фасада нужно больше, чем на чек.
     */
    public function vision(AiProvider $p, string $prompt, string $bytes, string $mime, int $maxTokens = 800): array
    {
        $this->needKey($p);
        $b64 = base64_encode($bytes);

        return $this->models($p, true, function (string $model) use ($p, $prompt, $b64, $mime, $maxTokens) {
            switch ($p->driver) {
                case 'gemini':
                    $r = $this->send(fn () => Http::withHeaders(['x-goog-api-key' => $p->api_key])->acceptJson()->timeout(self::TIMEOUT)
                        ->post($this->geminiBase($p).'/models/'.rawurlencode($model).':generateContent', [
                            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => $mime, 'data' => $b64]]]]],
                            'generationConfig' => ['maxOutputTokens' => $maxTokens],
                        ]));
                    $j = $r->json();

                    return ['text' => implode('', array_column((array) data_get($j, 'candidates.0.content.parts', []), 'text')),
                        'in' => (int) data_get($j, 'usageMetadata.promptTokenCount', 0), 'out' => (int) data_get($j, 'usageMetadata.candidatesTokenCount', 0)];
                case 'openai':
                case 'compatible':
                    $base = $p->driver === 'openai' ? 'https://api.openai.com/v1' : (string) $p->base_url;
                    $r = $this->send(fn () => Http::withToken($p->api_key)->acceptJson()->timeout(self::TIMEOUT)->post(rtrim($base, '/').'/chat/completions', [
                        'model' => $model,
                        'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => $prompt], ['type' => 'image_url', 'image_url' => ['url' => "data:$mime;base64,$b64"]]]]],
                        $p->driver === 'openai' ? 'max_completion_tokens' : 'max_tokens' => $maxTokens,
                    ]), true);
                    $j = $r->json();

                    return ['text' => (string) data_get($j, 'choices.0.message.content', ''), 'in' => (int) data_get($j, 'usage.prompt_tokens', 0), 'out' => (int) data_get($j, 'usage.completion_tokens', 0)];
                case 'anthropic':
                    try {
                        $msg = $this->anthropicClient($p)->messages->create(
                            maxTokens: max(2000, $maxTokens),
                            messages: [MessageParam::with(role: 'user', content: [
                                ImageBlockParam::with(source: Base64ImageSource::with(data: $b64, mediaType: $mime)),
                                TextBlockParam::with(text: $prompt),
                            ])],
                            model: $model,
                            outputConfig: ['effort' => 'low'],
                        );
                    } catch (APIStatusException|APIConnectionException $e) {
                        throw $this->anthropicError($e);
                    }
                    $text = '';
                    foreach ($msg->content as $block) {
                        if ($block->type === 'text') {
                            $text .= $block->text;
                        }
                    }

                    return ['text' => $text, 'in' => $msg->usage->inputTokens, 'out' => $msg->usage->outputTokens];
                default:
                    throw new AiException(__('Это подключение ИИ не умеет читать фото'), 422);
            }
        });
    }

    /* ---------- выбор модели: основная, при лимите или перегрузке — запасные ---------- */

    private function models(AiProvider $p, bool $spare, callable $call, ?callable $canSpare = null): array
    {
        $list = $spare && $p->driver === 'gemini' ? $this->geminiChain($p) : [$p->model];
        $last = null;
        foreach ($list as $m) {
            try {
                return $call($m) + ['model' => $m];
            } catch (AiException $e) {
                $last = $e;
                if ($e->cool > 0) {
                    Cache::put($this->coolKey($p, $m), true, $e->cool);
                }
                if ($e->cool <= 0 || ($canSpare && ! $canSpare())) {
                    throw $e;
                }
            }
        }

        throw $last;
    }

    private function coolKey(AiProvider $p, string $model): string
    {
        return 'ai:cool:'.$p->id.':'.$model;
    }

    /** Модели для вопроса: основная, затем запасные; те, у которых сейчас лимит или перегрузка, пропускаем */
    private function geminiChain(AiProvider $p): array
    {
        $all = array_values(array_unique(array_merge([$p->model], $this->geminiModels($p))));
        $free = array_values(array_filter($all, fn ($m) => ! Cache::has($this->coolKey($p, $m))));

        return array_slice($free ?: [$p->model], 0, self::TRIES);
    }

    /** Текстовые модели Gemini Flash этого ключа: новые первыми, «lite» после обычных, «-latest» в конце */
    private function geminiModels(AiProvider $p): array
    {
        $key = 'ai:gm:'.$p->id.':'.substr(md5((string) $p->api_key), 0, 8);
        if (is_array($c = Cache::get($key))) {
            return $c;
        }
        $names = [];
        try {
            $r = Http::withHeaders(['x-goog-api-key' => $p->api_key])->acceptJson()->timeout(8)->get($this->geminiBase($p).'/models', ['pageSize' => 1000]);
            foreach ((array) $r->json('models') as $m) {
                $n = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
                if (in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)
                    && preg_match('/^gemini-\d+(?:\.\d+)?-flash(?:-lite)?$|^gemini-flash(?:-lite)?-latest$/', $n)) {
                    $names[] = $n;
                }
            }
        } catch (\Throwable) {
        }
        $rank = fn (string $n) => preg_match('/^gemini-(\d+(?:\.\d+)?)-flash(-lite)?$/', $n, $x)
            ? [0, -(float) $x[1], isset($x[2]) ? 1 : 0] : [1, 0, str_contains($n, 'lite') ? 1 : 0];
        usort($names, fn ($a, $b) => $rank($a) <=> $rank($b));
        $names = $names ?: self::GEMINI_SPARE;
        Cache::put($key, $names, $names === self::GEMINI_SPARE ? 600 : 86400);

        return $names;
    }

    /* ---------- общее ---------- */

    private function needKey(AiProvider $p): void
    {
        if (! $p->api_key) {
            throw new AiException(__('У подключения ИИ не указан ключ API'), 500);
        }
    }

    /** Только user/assistant, без пустых, первым — пользователь, подряд одинаковые роли склеиваются */
    private function normalize(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = trim((string) ($m['content'] ?? ''));
            if ($text === '' || (! $out && $role === 'assistant')) {
                continue;
            }
            if ($out && end($out)['role'] === $role) {
                $out[count($out) - 1]['content'] .= "\n\n".$text;
            } else {
                $out[] = ['role' => $role, 'content' => $text];
            }
        }
        if (! $out) {
            throw new AiException(__('Пустой вопрос'), 422);
        }

        return $out;
    }

    private function refused(): AiException
    {
        return new AiException(__('Сервис ИИ отказался отвечать на этот вопрос. Переформулируйте его.'), 422);
    }

    /* ---------- OpenAI ChatGPT, DeepSeek и совместимые ---------- */

    private function openaiLike(AiProvider $p, string $model, string $base, string $system, array $messages, int $max, string $maxKey): array
    {
        if ($base === '') {
            throw new AiException(__('Для OpenAI-совместимого подключения нужен адрес API'), 500);
        }
        $r = $this->send(fn () => Http::withToken($p->api_key)->acceptJson()->timeout(self::TIMEOUT)->post(rtrim($base, '/').'/chat/completions', [
            'model' => $model,
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
            $maxKey => $max,
        ]), true);
        $j = $r->json();
        $text = (string) data_get($j, 'choices.0.message.content', '');
        if ($text === '' && data_get($j, 'choices.0.finish_reason') === 'content_filter') {
            throw $this->refused();
        }

        return ['text' => $text, 'in' => (int) data_get($j, 'usage.prompt_tokens', 0), 'out' => (int) data_get($j, 'usage.completion_tokens', 0)];
    }

    private function openaiStream(AiProvider $p, string $model, string $base, string $system, array $messages, int $max, string $maxKey, bool $usage, callable $emit): array
    {
        if ($base === '') {
            throw new AiException(__('Для OpenAI-совместимого подключения нужен адрес API'), 500);
        }
        $body = ['model' => $model, 'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages), $maxKey => $max, 'stream' => true]
            + ($usage ? ['stream_options' => ['include_usage' => true]] : []);
        $text = '';
        $in = $out = 0;
        $filtered = false;
        $this->sse(rtrim($base, '/').'/chat/completions', ['Authorization: Bearer '.$p->api_key], $body, function (array $j) use (&$text, &$in, &$out, &$filtered, $emit) {
            if (! empty($j['usage'])) {
                $in = (int) ($j['usage']['prompt_tokens'] ?? 0);
                $out = (int) ($j['usage']['completion_tokens'] ?? 0);
            }
            $filtered = $filtered || data_get($j, 'choices.0.finish_reason') === 'content_filter';
            $t = (string) data_get($j, 'choices.0.delta.content', '');
            if ($t === '') {
                return true;
            }
            $text .= $t;

            return $emit($t);
        });
        if ($text === '' && $filtered) {
            throw $this->refused();
        }

        return ['text' => $text, 'in' => $in, 'out' => $out];
    }

    /* ---------- Google Gemini ---------- */

    private function geminiBase(AiProvider $p): string
    {
        return rtrim($p->base_url ?: self::GEMINI, '/');
    }

    private function geminiBody(string $model, string $system, array $messages, int $max, bool $quick): array
    {
        $cfg = ['maxOutputTokens' => $max];
        // в чате модели долго «думают» молча — ограничиваем размышления, чтобы ответ начинался быстрее
        if ($quick && preg_match('/^gemini-2\.5-flash$/', $model)) {
            $cfg['thinkingConfig'] = ['thinkingBudget' => 1024];
        } elseif ($quick && preg_match('/^gemini-(?:[3-9]|\d\d)/', $model)) {
            $cfg['thinkingConfig'] = ['thinkingLevel' => 'low'];
        }

        return [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => array_map(fn ($m) => ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]], $messages),
            'generationConfig' => $cfg,
        ];
    }

    /** Текст ответа без «мыслей» модели */
    private function geminiText(array $j): string
    {
        $t = '';
        foreach ((array) data_get($j, 'candidates.0.content.parts', []) as $x) {
            if (is_array($x) && empty($x['thought'])) {
                $t .= (string) ($x['text'] ?? '');
            }
        }

        return $t;
    }

    private function geminiBlocked(array $j): bool
    {
        return (bool) data_get($j, 'promptFeedback.blockReason') || data_get($j, 'candidates.0.finishReason') === 'SAFETY';
    }

    private function gemini(AiProvider $p, string $model, string $system, array $messages, int $max): array
    {
        $r = $this->send(fn () => Http::withHeaders(['x-goog-api-key' => $p->api_key])->acceptJson()->timeout(self::TIMEOUT)
            ->post($this->geminiBase($p).'/models/'.rawurlencode($model).':generateContent', $this->geminiBody($model, $system, $messages, $max, false)));
        $j = (array) $r->json();
        $text = $this->geminiText($j);
        if ($text === '' && $this->geminiBlocked($j)) {
            throw $this->refused();
        }

        return ['text' => $text, 'in' => (int) data_get($j, 'usageMetadata.promptTokenCount', 0), 'out' => (int) data_get($j, 'usageMetadata.candidatesTokenCount', 0)];
    }

    private function geminiStream(AiProvider $p, string $model, string $system, array $messages, int $max, callable $emit): array
    {
        $text = '';
        $in = $out = 0;
        $blocked = false;
        // обычное замыкание, не fn(): итоги ($text, $in…) должны меняться по ссылке, а fn() берёт копии
        $run = function (bool $quick) use ($p, $model, $system, $messages, $max, $emit, &$text, &$in, &$out, &$blocked) {
            $this->sse($this->geminiBase($p).'/models/'.rawurlencode($model).':streamGenerateContent?alt=sse', ['x-goog-api-key: '.$p->api_key],
                $this->geminiBody($model, $system, $messages, $max, $quick), function (array $j) use (&$text, &$in, &$out, &$blocked, $emit) {
                    $in = (int) data_get($j, 'usageMetadata.promptTokenCount', $in);
                    $out = (int) data_get($j, 'usageMetadata.candidatesTokenCount', $out);
                    $blocked = $blocked || $this->geminiBlocked($j);
                    $t = $this->geminiText($j);
                    if ($t === '') {
                        return true;
                    }
                    $text .= $t;

                    return $emit($t);
                });
        };
        try {
            $run(true);
        } catch (AiException $e) {
            // модель не принимает настройку размышлений — спрашиваем без неё
            if ($text !== '' || $e->cool > 0 || stripos($e->getMessage(), 'think') === false) {
                throw $e;
            }
            $run(false);
        }
        if ($text === '' && $blocked) {
            throw $this->refused();
        }

        return ['text' => $text, 'in' => $in, 'out' => $out];
    }

    /* ---------- Anthropic Claude (официальный PHP SDK) ---------- */

    private function anthropicClient(AiProvider $p): AnthropicClient
    {
        return new AnthropicClient(apiKey: $p->api_key, baseUrl: $p->base_url ?: null, requestOptions: ['timeout' => (float) self::TIMEOUT, 'maxRetries' => 1]);
    }

    private function anthropicError(\Throwable $e): AiException
    {
        return match (true) {
            $e instanceof AuthenticationException => new AiException(__('Неверный ключ API сервиса ИИ'), 502),
            $e instanceof PermissionDeniedException => new AiException(__('Нет доступа к выбранной модели с этим ключом'), 502),
            $e instanceof RateLimitException => new AiException(__('Слишком много запросов к ИИ — подождите минуту'), 429, 60),
            $e instanceof APIConnectionException => new AiException(__('Нет связи с сервисом ИИ'), 504, 30),
            $e instanceof APIStatusException && in_array($e->status, [500, 503, 529], true) => new AiException(__('Сервис ИИ сейчас перегружен — попробуйте через минуту'), 503, 300),
            $e instanceof APIStatusException => new AiException(__('Ошибка сервиса ИИ').' ('.($e->type?->value ?? 'error').'): '.mb_substr($e->getMessage(), 0, 200), 502),
            default => new AiException(__('Ошибка сервиса ИИ'), 502),
        };
    }

    private function anthropic(AiProvider $p, string $model, string $system, array $messages, int $max): array
    {
        try {
            $msg = $this->anthropicClient($p)->messages->create(
                maxTokens: max($max, 4000),
                messages: $messages,
                model: $model,
                system: $system,
                outputConfig: ['effort' => 'low'],
            );
        } catch (APIStatusException|APIConnectionException $e) {
            throw $this->anthropicError($e);
        }

        if ($msg->stopReason === 'refusal') {
            throw $this->refused();
        }
        $text = '';
        foreach ($msg->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        if ($msg->stopReason === 'max_tokens') {
            $text .= "\n\n…";
        }

        return ['text' => $text, 'in' => $msg->usage->inputTokens, 'out' => $msg->usage->outputTokens];
    }

    private function anthropicStream(AiProvider $p, string $model, string $system, array $messages, int $max, callable $emit): array
    {
        $text = '';
        $in = $out = 0;
        $stop = null;
        try {
            $stream = $this->anthropicClient($p)->messages->createStream(
                maxTokens: max($max, 4000),
                messages: $messages,
                model: $model,
                system: $system,
                outputConfig: ['effort' => 'low'],
            );
            foreach ($stream as $ev) {
                if ($ev instanceof RawMessageStartEvent) {
                    $in = $ev->message->usage->inputTokens;
                } elseif ($ev instanceof RawMessageDeltaEvent) {
                    $out = $ev->usage->outputTokens;
                    $stop = $ev->delta->stopReason;
                } elseif ($ev instanceof RawContentBlockDeltaEvent && $ev->delta instanceof TextDelta) {
                    $text .= $ev->delta->text;
                    if ($emit($ev->delta->text) === false) {
                        break;
                    }
                }
            }
        } catch (APIStatusException|APIConnectionException $e) {
            throw $this->anthropicError($e);
        }
        if ($text === '' && $stop === 'refusal') {
            throw $this->refused();
        }
        if ($stop === 'max_tokens') {
            $emit("\n\n…");
            $text .= "\n\n…";
        }

        return ['text' => $text, 'in' => $in, 'out' => $out];
    }

    /* ---------- HTTP ---------- */

    /** Запрос с понятными ошибками; $retry — «модель перегружена» (503) обычно проходит за секунды: одна повторная попытка */
    private function send(callable $fn, bool $retry = false): Response
    {
        try {
            /** @var Response $r */
            $r = $fn();
            if ($retry && $r->status() === 503) {
                usleep(1_500_000);
                $r = $fn();
            }
        } catch (ConnectionException) {
            throw new AiException(__('Нет связи с сервисом ИИ'), 504, 30);
        }
        if ($r->successful()) {
            return $r;
        }

        throw $this->fail($r->status(), $r->json(), $r->body());
    }

    /**
     * Потоковый запрос (Server-Sent Events): $onData(json) на каждую строку «data: {...}».
     * $onData вернул false — пользователь ушёл, соединение закрываем.
     */
    private function sse(string $url, array $headers, array $body, callable $onData): void
    {
        $buf = $errBody = '';
        $status = 0;
        $stop = false;
        $line = function (string $l) use ($onData, &$stop): bool {
            $l = rtrim($l, "\r");
            if (! str_starts_with($l, 'data:')) {
                return true;
            }
            $data = trim(substr($l, 5));
            if ($data === '' || $data === '[DONE]' || ! is_array($j = json_decode($data, true))) {
                return true;
            }
            if ($onData($j) === false) {
                $stop = true;

                return false;
            }

            return true;
        };
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: text/event-stream'], $headers),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => self::TIMEOUT + 60,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buf, &$errBody, &$status, $line) {
                $status = $status ?: (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($status >= 300) {
                    $errBody .= $chunk;

                    return strlen($chunk);
                }
                $buf .= $chunk;
                while (($i = strpos($buf, "\n")) !== false) {
                    $l = substr($buf, 0, $i);
                    $buf = substr($buf, $i + 1);
                    if (! $line($l)) {
                        return 0;   // меньше, чем пришло — curl прерывает загрузку
                    }
                }

                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = $status ?: (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        if ($stop) {
            return;
        }
        if ($status >= 300) {
            throw $this->fail($status, json_decode($errBody, true), $errBody);
        }
        if ($errno) {
            throw new AiException(__('Нет связи с сервисом ИИ'), 504, 30);
        }
        if ($buf !== '') {
            $line($buf);
        }
    }

    /** Ошибка сервиса — понятным текстом; лимит и перегрузка — с паузой для этой модели */
    private function fail(int $status, mixed $j, string $raw): AiException
    {
        if (is_array($j) && array_is_list($j)) {
            $j = $j[0] ?? [];   // Gemini в потоке присылает ошибку массивом
        }
        $msg = (string) (data_get($j, 'error.message') ?? data_get($j, 'message') ?? mb_substr($raw, 0, 300));
        if ($status === 429) {
            $all = is_array($j) ? (string) json_encode($j) : $raw;
            $delay = preg_match('/"retryDelay"\s*:\s*"(\d+)/', $all, $x) ? (int) $x[1] : 0;
            if (str_contains($all, 'PerDay')) {
                return new AiException(__('Дневной лимит ключа ИИ исчерпан. Завтра он обновится; чтобы не ждать, администратор платформы может подключить платный ключ или ещё один сервис ИИ.'), 429, min(86400, max(600, $delay ?: 3600)));
            }

            return new AiException(__('Слишком много запросов к ИИ — подождите минуту'), 429, max(30, min(600, $delay ?: 60)));
        }

        return match (true) {
            in_array($status, [401, 403], true) => new AiException(__('Неверный ключ API сервиса ИИ'), 502),
            $status === 402 => new AiException(__('На счёте сервиса ИИ закончились деньги'), 502),
            $status === 404 => new AiException(__('Модель ИИ не найдена').': '.mb_substr($msg, 0, 160), 502, 86400),
            in_array($status, [500, 502, 503, 504], true) => new AiException(__('Сервис ИИ сейчас перегружен — попробуйте через минуту'), 503, 300),
            default => new AiException(__('Ошибка сервиса ИИ').' ('.$status.'): '.mb_substr($msg, 0, 200), 502),
        };
    }
}
