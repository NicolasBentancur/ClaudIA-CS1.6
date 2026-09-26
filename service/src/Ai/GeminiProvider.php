<?php

declare(strict_types=1);

namespace Claudia\Ai;

use Claudia\Config;
use Claudia\Net\AsyncHttp;

/**
 * Google Gemini vía REST generateContent con salida estructurada (responseJsonSchema).
 */
final class GeminiProvider implements Provider
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
            $done(null, 'sin API key de Gemini');
            return;
        }
        $cfg = $this->config->array('ai.gemini');
        $generation = [
            'temperature' => (float) ($model['temperature'] ?? $cfg['temperature'] ?? 1.0),
            'maxOutputTokens' => (int) ($model['max_output_tokens'] ?? $cfg['max_output_tokens'] ?? 2048),
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => $prompt['schema'],
        ];
        $thinking = $model['thinking_level'] ?? $cfg['thinking_level'] ?? null;
        if (is_string($thinking) && $thinking !== '') {
            $generation['thinkingConfig'] = ['thinkingLevel' => $thinking];
        }
        $threshold = (string) ($cfg['safety_threshold'] ?? 'BLOCK_ONLY_HIGH');
        $safety = [];
        foreach ((array) ($cfg['safety_categories'] ?? []) as $category) {
            $safety[] = ['category' => (string) $category, 'threshold' => $threshold];
        }
        $body = [
            'systemInstruction' => ['parts' => [['text' => $prompt['system']]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt['user']]]]],
            'generationConfig' => $generation,
        ];
        if ($safety !== []) {
            $body['safetySettings'] = $safety;
        }
        $url = str_replace('{model}', rawurlencode((string) $model['model']), (string) ($cfg['endpoint'] ?? ''));
        $this->http->post(
            $url,
            ['Content-Type' => 'application/json', 'x-goog-api-key' => $apiKey],
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
                if (isset($res['promptFeedback']['blockReason'])) {
                    $done(null, 'bloqueado: ' . $res['promptFeedback']['blockReason']);
                    return;
                }
                $candidate = $res['candidates'][0] ?? null;
                $finish = (string) ($candidate['finishReason'] ?? '');
                $text = '';
                foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
                    if (!($part['thought'] ?? false)) {
                        $text .= (string) ($part['text'] ?? '');
                    }
                }
                $json = JsonReply::parse($text);
                if ($json === null) {
                    $done(null, "respuesta no JSON (finishReason {$finish})");
                    return;
                }
                $done($json, null);
            }
        );
    }
}
