<?php

declare(strict_types=1);

namespace Claudia\Net;

use Claudia\Log;
use Claudia\UserError;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

/**
 * Conexión TCP con el plugin AMXX. Protocolo: una línea JSON por mensaje.
 *
 *   plugin -> servicio: {"id":7,"type":"auth.login","data":{...}}   (id 0 = no espera respuesta)
 *   servicio -> plugin: {"id":7,"ok":true,"data":{...}}             (respuesta)
 *                       {"id":7,"ok":false,"error":"code","message":"..."}
 *                       {"type":"print","data":{...}}                  (evento)
 *
 * El primer mensaje tiene que ser "hello" con el secreto compartido.
 */
final class PluginLink
{
    private ?TcpConnection $active = null;

    /** @var array<string, callable(array):(array|null)> */
    private array $handlers = [];

    /** @var array<string, list<callable>> */
    private array $lifecycle = ['connected' => [], 'disconnected' => []];

    /** @var callable():array */
    private $helloData;

    public function __construct(private readonly string $secret)
    {
        $this->helloData = fn () => [];
    }

    /** @param callable(array):(array|null) $handler */
    public function on(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function onConnected(callable $cb): void
    {
        $this->lifecycle['connected'][] = $cb;
    }

    public function onDisconnected(callable $cb): void
    {
        $this->lifecycle['disconnected'][] = $cb;
    }

    /** @param callable():array $provider datos que se devuelven en el hello (lista de comandos, etc.) */
    public function setHelloData(callable $provider): void
    {
        $this->helloData = $provider;
    }

    public function connected(): bool
    {
        return $this->active !== null;
    }

    public function attach(Worker $worker): void
    {
        $worker->onConnect = function (TcpConnection $c): void {
            if (!in_array($c->getRemoteIp(), ['127.0.0.1', '::1'], true)) {
                Log::warning('Conexión de plugin rechazada (no es localhost)', ['ip' => $c->getRemoteIp()]);
            }
            $c->context->authed = false;
        };
        $worker->onMessage = function (TcpConnection $c, string $line): void {
            $this->handleLine($c, $line);
        };
        $worker->onClose = function (TcpConnection $c): void {
            if ($this->active === $c) {
                $this->active = null;
                Log::info('Plugin desconectado');
                foreach ($this->lifecycle['disconnected'] as $cb) {
                    $cb();
                }
            }
        };
    }

    public function push(string $type, array $data): void
    {
        if ($this->active === null) {
            return;
        }
        $this->active->send(json_encode(['type' => $type, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function handleLine(TcpConnection $c, string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }
        $msg = json_decode($line, true);
        if (!is_array($msg) || !isset($msg['type'])) {
            Log::warning('Mensaje inválido del plugin', ['line' => substr($line, 0, 200)]);
            return;
        }
        $id = (int) ($msg['id'] ?? 0);
        $type = (string) $msg['type'];
        $data = is_array($msg['data'] ?? null) ? $msg['data'] : [];

        if (!$c->context->authed) {
            if ($type !== 'hello' || !hash_equals($this->secret, (string) ($data['secret'] ?? ''))) {
                Log::warning('Handshake inválido, cerrando conexión', ['ip' => $c->getRemoteIp()]);
                $this->reply($c, $id, false, null, 'bad_secret', 'Secreto inválido');
                $c->close();
                return;
            }
            $c->context->authed = true;
            if ($this->active !== null && $this->active !== $c) {
                $old = $this->active;
                $this->active = null;
                $old->close();
            }
            $this->active = $c;
            Log::info('Plugin conectado', ['server' => $data['server'] ?? '?', 'map' => $data['map'] ?? '?']);
            $this->reply($c, $id, true, ($this->helloData)());
            foreach ($this->lifecycle['connected'] as $cb) {
                $cb($data);
            }
            return;
        }

        $handler = $this->handlers[$type] ?? null;
        if ($handler === null) {
            Log::warning('Tipo de mensaje desconocido', ['type' => $type]);
            $this->reply($c, $id, false, null, 'unknown_type', "Tipo desconocido: {$type}");
            return;
        }
        try {
            $result = $handler($data);
            $this->reply($c, $id, true, $result ?? []);
        } catch (UserError $e) {
            $this->reply($c, $id, false, null, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error("Error procesando '{$type}': " . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
            $this->reply($c, $id, false, null, 'internal', 'Error interno');
        }
    }

    private function reply(TcpConnection $c, int $id, bool $ok, ?array $data, string $error = '', string $message = ''): void
    {
        if ($id <= 0) {
            return;
        }
        $payload = ['id' => $id, 'ok' => $ok];
        if ($ok) {
            $payload['data'] = (object) ($data ?? []);
        } else {
            $payload['error'] = $error;
            $payload['message'] = $message;
        }
        $c->send(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
