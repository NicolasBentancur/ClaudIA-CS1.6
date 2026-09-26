<?php

declare(strict_types=1);

namespace Claudia\Net;

use Claudia\Games\GameManager;
use Claudia\Log;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

/**
 * WebSocket de los juegos. El primer mensaje de la página es {"type":"hello","token":"..."}.
 * Igual que HttpServer: está abierto a Internet, así que ningún mensaje puede tirar una
 * excepción hacia Workerman.
 */
final class WsServer
{
    /** Tope de un mensaje de la página: el más grande (un giro de ruleta con 200 apuestas) ronda los 12 KB. */
    public const MAX_MESSAGE_BYTES = 65536;

    public function __construct(private readonly GameManager $games)
    {
    }

    public function attach(Worker $worker): void
    {
        $worker->onConnect = function (TcpConnection $c): void {
            // Workerman corta la conexión antes de juntar en memoria un frame más grande. Sin tope
            // (10 MB por defecto), un frame de ~10 MB sin token agotaba el memory_limit de 128M en el
            // json_decode y moría el proceso.
            $c->maxPackageSize = self::MAX_MESSAGE_BYTES;
        };
        $worker->onMessage = function (TcpConnection $c, string $data): void {
            try {
                $this->message($c, $data);
            } catch (\Throwable $e) {
                Log::error('Error atendiendo un mensaje WebSocket: ' . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
            }
        };
        $worker->onClose = function (TcpConnection $c): void {
            $this->games->detachWs($c);
        };
    }

    private function message(TcpConnection $c, string $data): void
    {
        $msg = json_decode($data, true);
        if (!is_array($msg)) {
            return;
        }
        $known = $c->context->gameToken ?? null;
        $session = $known !== null ? $this->games->get($known) : null;
        if ($session === null) {
            $token = $msg['token'] ?? '';
            $session = is_string($token) ? $this->games->attachWs($token, $c) : null;
            if ($session === null) {
                $c->send((string) json_encode(['type' => 'expired']));
                $c->close();
                return;
            }
            $c->context->gameToken = $token;
        }
        $this->games->receive($session, $msg);
    }
}
