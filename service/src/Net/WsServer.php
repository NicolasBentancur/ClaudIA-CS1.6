<?php

declare(strict_types=1);

namespace Claudia\Net;

use Claudia\Games\GameManager;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

/**
 * WebSocket de los juegos. El primer mensaje de la página es {"type":"hello","token":"..."}.
 */
final class WsServer
{
    public function __construct(private readonly GameManager $games)
    {
    }

    public function attach(Worker $worker): void
    {
        $worker->onMessage = function (TcpConnection $c, string $data): void {
            $msg = json_decode($data, true);
            if (!is_array($msg)) {
                return;
            }
            $known = $c->context->gameToken ?? null;
            $session = $known !== null ? $this->games->get($known) : null;
            if ($session === null) {
                $token = (string) ($msg['token'] ?? '');
                $session = $this->games->attachWs($token, $c);
                if ($session === null) {
                    $c->send((string) json_encode(['type' => 'expired']));
                    $c->close();
                    return;
                }
                $c->context->gameToken = $token;
            }
            $this->games->receive($session, $msg);
        };
        $worker->onClose = function (TcpConnection $c): void {
            $this->games->detachWs($c);
        };
    }
}
