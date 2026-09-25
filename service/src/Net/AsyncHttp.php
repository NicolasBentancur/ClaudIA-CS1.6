<?php

declare(strict_types=1);

namespace Claudia\Net;

use Claudia\Log;

/**
 * Cliente HTTP(S) no bloqueante basado en curl_multi. tick() se llama desde un Timer
 * del event loop de Workerman; los callbacks corren en ese mismo hilo.
 */
final class AsyncHttp
{
    private \CurlMultiHandle $mh;

    /** @var array<int, array{0:\CurlHandle, 1:callable}> */
    private array $pending = [];

    public function __construct(private readonly ?string $caBundle = null)
    {
        $this->mh = curl_multi_init();
    }

    /**
     * @param array<string,string> $headers
     * @param callable(int $status, string $body, ?string $error):void $callback
     */
    public function post(string $url, array $headers, string $body, float $timeout, callable $callback): void
    {
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "{$k}: {$v}";
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_ENCODING => '',
        ]);
        if ($this->caBundle !== null && $this->caBundle !== '' && is_file($this->caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        } elseif (defined('CURLSSLOPT_NATIVE_CA')) {
            // Usa el almacén de certificados del sistema (necesario en Windows sin curl.cainfo).
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        curl_multi_add_handle($this->mh, $ch);
        $this->pending[spl_object_id($ch)] = [$ch, $callback];
        $this->tick();
    }

    public function busy(): bool
    {
        return $this->pending !== [];
    }

    public function tick(): void
    {
        if ($this->pending === []) {
            return;
        }
        do {
            $status = curl_multi_exec($this->mh, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($this->mh)) {
            $ch = $info['handle'];
            $id = spl_object_id($ch);
            if (!isset($this->pending[$id])) {
                continue;
            }
            [, $callback] = $this->pending[$id];
            unset($this->pending[$id]);
            $body = (string) curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = $info['result'] !== CURLE_OK ? (curl_error($ch) ?: 'curl error ' . $info['result']) : null;
            curl_multi_remove_handle($this->mh, $ch);
            try {
                $callback($code, $body, $error);
            } catch (\Throwable $e) {
                Log::error('Error en callback HTTP: ' . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
            }
        }
    }
}
