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
use Anthropic\Messages\TextBlockParam;
use App\Models\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Один вход для всех сервисов ИИ: Gemini, ChatGPT, DeepSeek, Claude и OpenAI-совместимые.
 * $messages — [['role' => 'user'|'assistant', 'content' => 'текст'], …]
 *
 * @return array{text: string, in: int, out: int}
 */
class AiGateway
{
    private const TIMEOUT = 120;

    public function chat(AiProvider $p, string $system, array $messages, ?int $maxTokens = null): array
    {
        if (! $p->api_key) {
            throw new AiException(__('У подключения ИИ не указан ключ API'), 500);
        }
        $messages = $this->normalize($messages);
        $max = $maxTokens ?: (int) $p->max_tokens ?: 4000;

        return match ($p->driver) {
            'gemini' => $this->gemini($p, $system, $messages, $max),
            'anthropic' => $this->anthropic($p, $system, $messages, $max),
            'openai' => $this->openaiLike($p, 'https://api.openai.com/v1', $system, $messages, $max, 'max_completion_tokens'),
            'deepseek' => $this->openaiLike($p, 'https://api.deepseek.com/v1', $system, $messages, $max, 'max_tokens'),
            'compatible' => $this->openaiLike($p, (string) $p->base_url, $system, $messages, $max, 'max_tokens'),
            default => throw new AiException('Unknown AI driver: '.$p->driver, 500),
        };
    }

    /**
     * Вопрос по картинке (чек, накладная, фасад дома). DeepSeek картинки не понимает.
     * $maxTokens — запас на ответ: «думающим» моделям (Gemini 2.5) на разбор фасада нужно больше, чем на чек.
     *
     * @return array{text: string, in: int, out: int}
     */
    public function vision(AiProvider $p, string $prompt, string $bytes, string $mime, int $maxTokens = 800): array
    {
        if (! $p->api_key) {
            throw new AiException(__('У подключения ИИ не указан ключ API'), 500);
        }
        $b64 = base64_encode($bytes);
        switch ($p->driver) {
            case 'gemini':
                $base = $p->base_url ?: 'https://generativelanguage.googleapis.com/v1beta';
                $r = $this->send(fn () => Http::withHeaders(['x-goog-api-key' => $p->api_key])->acceptJson()->timeout(self::TIMEOUT)
                    ->post(rtrim($base, '/').'/models/'.rawurlencode($p->model).':generateContent', [
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
                    'model' => $p->model,
                    'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => $prompt], ['type' => 'image_url', 'image_url' => ['url' => "data:$mime;base64,$b64"]]]]],
                    $p->driver === 'openai' ? 'max_completion_tokens' : 'max_tokens' => $maxTokens,
                ]));
                $j = $r->json();

                return ['text' => (string) data_get($j, 'choices.0.message.content', ''), 'in' => (int) data_get($j, 'usage.prompt_tokens', 0), 'out' => (int) data_get($j, 'usage.completion_tokens', 0)];
            case 'anthropic':
                $client = new AnthropicClient(apiKey: $p->api_key, baseUrl: $p->base_url ?: null, requestOptions: ['timeout' => (float) self::TIMEOUT, 'maxRetries' => 1]);
                try {
                    $msg = $client->messages->create(
                        maxTokens: max(2000, $maxTokens),
                        messages: [MessageParam::with(role: 'user', content: [
                            ImageBlockParam::with(source: Base64ImageSource::with(data: $b64, mediaType: $mime)),
                            TextBlockParam::with(text: $prompt),
                        ])],
                        model: $p->model,
                        outputConfig: ['effort' => 'low'],
                    );
                } catch (APIStatusException $e) {
                    throw new AiException(__('Ошибка сервиса ИИ').' ('.($e->type?->value ?? 'error').'): '.mb_substr($e->getMessage(), 0, 200), 502);
                } catch (APIConnectionException) {
                    throw new AiException(__('Нет связи с сервисом ИИ'), 504);
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

    /* ---------- OpenAI ChatGPT, DeepSeek и совместимые ---------- */
    private function openaiLike(AiProvider $p, string $base, string $system, array $messages, int $max, string $maxKey): array
    {
        if ($base === '') {
            throw new AiException(__('Для OpenAI-совместимого подключения нужен адрес API'), 500);
        }
        $body = [
            'model' => $p->model,
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
            $maxKey => $max,
        ];
        $r = $this->send(fn () => Http::withToken($p->api_key)->acceptJson()->timeout(self::TIMEOUT)
            ->post(rtrim($base, '/').'/chat/completions', $body));
        $j = $r->json();
        $text = (string) data_get($j, 'choices.0.message.content', '');
        if ($text === '' && data_get($j, 'choices.0.finish_reason') === 'content_filter') {
            throw new AiException(__('Сервис ИИ отказался отвечать на этот вопрос. Переформулируйте его.'), 422);
        }

        return ['text' => $text, 'in' => (int) data_get($j, 'usage.prompt_tokens', 0), 'out' => (int) data_get($j, 'usage.completion_tokens', 0)];
    }

    /* ---------- Google Gemini ---------- */
    private function gemini(AiProvider $p, string $system, array $messages, int $max): array
    {
        $base = $p->base_url ?: 'https://generativelanguage.googleapis.com/v1beta';
        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => array_map(fn ($m) => ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]], $messages),
            'generationConfig' => ['maxOutputTokens' => $max],
        ];
        $r = $this->send(fn () => Http::withHeaders(['x-goog-api-key' => $p->api_key])->acceptJson()->timeout(self::TIMEOUT)
            ->post(rtrim($base, '/').'/models/'.rawurlencode($p->model).':generateContent', $body));
        $j = $r->json();
        $parts = data_get($j, 'candidates.0.content.parts', []);
        $text = implode('', array_map(fn ($x) => empty($x['thought']) ? (string) ($x['text'] ?? '') : '', is_array($parts) ? $parts : []));
        if ($text === '' && (data_get($j, 'promptFeedback.blockReason') || data_get($j, 'candidates.0.finishReason') === 'SAFETY')) {
            throw new AiException(__('Сервис ИИ отказался отвечать на этот вопрос. Переформулируйте его.'), 422);
        }

        return ['text' => $text, 'in' => (int) data_get($j, 'usageMetadata.promptTokenCount', 0), 'out' => (int) data_get($j, 'usageMetadata.candidatesTokenCount', 0)];
    }

    /* ---------- Anthropic Claude (официальный PHP SDK) ---------- */
    private function anthropic(AiProvider $p, string $system, array $messages, int $max): array
    {
        $client = new AnthropicClient(apiKey: $p->api_key, baseUrl: $p->base_url ?: null, requestOptions: ['timeout' => (float) self::TIMEOUT, 'maxRetries' => 1]);
        try {
            $msg = $client->messages->create(
                maxTokens: max($max, 4000),
                messages: $messages,
                model: $p->model,
                system: $system,
                outputConfig: ['effort' => 'low'],
            );
        } catch (AuthenticationException) {
            throw new AiException(__('Неверный ключ API сервиса ИИ'), 502);
        } catch (PermissionDeniedException) {
            throw new AiException(__('Нет доступа к выбранной модели с этим ключом'), 502);
        } catch (RateLimitException) {
            throw new AiException(__('Слишком много запросов к ИИ — подождите минуту'), 429);
        } catch (APIStatusException $e) {
            throw new AiException(__('Ошибка сервиса ИИ').' ('.($e->type?->value ?? 'error').'): '.mb_substr($e->getMessage(), 0, 200), 502);
        } catch (APIConnectionException) {
            throw new AiException(__('Нет связи с сервисом ИИ'), 504);
        }

        if ($msg->stopReason === 'refusal') {
            throw new AiException(__('Сервис ИИ отказался отвечать на этот вопрос. Переформулируйте его.'), 422);
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

    /** HTTP-запрос с понятными ошибками */
    private function send(callable $fn): Response
    {
        try {
            /** @var Response $r */
            $r = $fn();
            // «модель перегружена» (503) у Gemini обычно проходит за секунды — одна повторная попытка
            if ($r->status() === 503) {
                usleep(2_000_000);
                $r = $fn();
            }
        } catch (ConnectionException) {
            throw new AiException(__('Нет связи с сервисом ИИ'), 504);
        }
        if ($r->successful()) {
            return $r;
        }
        $msg = (string) (data_get($r->json(), 'error.message') ?? data_get($r->json(), 'message') ?? $r->body());
        throw new AiException(match (true) {
            in_array($r->status(), [401, 403], true) => __('Неверный ключ API сервиса ИИ'),
            $r->status() === 429 => __('Слишком много запросов к ИИ — подождите минуту'),
            $r->status() === 402 => __('На счёте сервиса ИИ закончились деньги'),
            default => __('Ошибка сервиса ИИ').' ('.$r->status().'): '.mb_substr($msg, 0, 200),
        }, $r->status() === 429 ? 429 : 502);
    }
}
