<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Запросы к Graph API Meta (Facebook, Instagram) от имени страницы компании */
class MetaGraph
{
    public function __construct(private Integration $in) {}

    public function call(string $path, string $method = 'GET', array $params = []): array
    {
        $token = $this->in->page_token;
        if (! $token) {
            throw new RuntimeException('Не указан токен страницы (Page Access Token)');
        }
        $q = ['access_token' => $token] + $params;
        if ($this->in->app_secret) {
            $q['appsecret_proof'] = hash_hmac('sha256', $token, $this->in->app_secret);
        }
        $url = 'https://graph.facebook.com/'.config('oftob.graph_version').'/'.ltrim($path, '/');
        $r = $method === 'GET' ? Http::timeout(15)->get($url, $q) : Http::timeout(15)->asForm()->post($url, $q);
        $j = $r->json() ?? [];
        if ($r->failed() || isset($j['error'])) {
            throw new RuntimeException($j['error']['message'] ?? ('Graph API '.$r->status()));
        }

        return $j;
    }

    /** Имя собеседника из Instagram / Messenger */
    public function profile(string $id, bool $ig): array
    {
        try {
            $j = $this->call($id, 'GET', ['fields' => $ig ? 'name,username' : 'first_name,last_name']);

            return $ig
                ? ['name' => $j['name'] ?? ($j['username'] ?? ''), 'username' => $j['username'] ?? '']
                : ['name' => trim(($j['first_name'] ?? '').' '.($j['last_name'] ?? '')), 'username' => ''];
        } catch (\Throwable) {
            return ['name' => '', 'username' => ''];
        }
    }
}
