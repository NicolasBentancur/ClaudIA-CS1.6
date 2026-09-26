<?php

declare(strict_types=1);

namespace Claudia\Ai;

use Claudia\Config;
use Claudia\Net\AsyncHttp;

/**
 * Groq (API compatible con OpenAI) en modo JSON. El esquema se describe en el prompt.
 */
final class GroqProvider implements Provider
{
    /** @param \Closure():string $apiKey se lee en cada pedido: amx_claudia_reload toma una key nueva de secrets.json */
    public function __construct(
        private readonly AsyncHttp $http,
        private readonly Config $config,
        private readonly \Closure $apiKey,
    ) {
    }

    public function generate(array $prompt, array $model, callable $done): void
    {
        $apiKey = ($this->apiKey)();
        if ($apiKey === '') {
            $done(null, 'sin API key de Groq');
            return;
        }
        $cfg = $this->config->array('ai.groq');
        $system = $prompt['system'] . "\n\nRespondé ÚNICAMENTE con un objeto JSON válido que cumpla este JSON Schema:\n"
            . json_encode($prompt['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = [
            'model' => (string) $model['model'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt['user']],
            ],
            'temperature' => (float) ($model['temperature'] ?? $cfg['temperature'] ?? 0.9),
            'max_completion_tokens' => (int) ($model['max_tokens'] ?? $cfg['max_tokens'] ?? 1024),
            'response_format' => ['type' => 'json_object'],
        ];
        foreach ((array) ($model['extra'] ?? []) as $k => $v) {
            $body[$k] = $v;
        }
        $this->http->post(
            (string) ($cfg['endpoint'] ?? 'https://api.groq.com/openai/v1/chat/completions'),
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $apiKey],
            (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (float) ($model['timeout'] ?? 12),
            function (int $status, string $raw, ?string $error) use ($done): void {
                if ($error !== null) {
                    $done(null, $error);
                    return;
                }
                $res = json_decode($raw, true);
                if ($status !== 200 || !is_array($res)) {
                    $msg = is_array($res) ? ($res['error']['message'] ?? '') : '';
                    $done(null, "HTTP {$status} " . substr((string) $msg, 0, 200));
                    return;
                }
                $text = (string) ($res['choices'][0]['message']['content'] ?? '');
                $json = JsonReply::parse($text);
                $json === null ? $done(null, 'respuesta no JSON') : $done($json, null);
            }
        );
    }
}
