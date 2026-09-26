<?php

declare(strict_types=1);

namespace Claudia\Net;

use Claudia\Games\GameManager;
use Claudia\Log;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

/**
 * Sirve las páginas de los juegos (public/) y la API de polling, que es el transporte
 * de respaldo cuando el navegador del MOTD no soporta WebSocket.
 *
 *   GET  /juegos/<juego>/?t=TOKEN          página del juego
 *   GET  /api/poll?t=TOKEN&since=SEQ       mensajes pendientes
 *   POST /api/send?t=TOKEN                 cuerpo JSON con la acción
 *
 * El puerto está abierto a Internet: ningún pedido puede tirar una excepción hacia Workerman
 * (corta el proceso entero, con el plugin y el WebSocket adentro).
 */
final class HttpServer
{
    /** Tope de un pedido (cabeceras + cuerpo): lo que mandan las páginas pesa unos pocos KB. */
    public const MAX_REQUEST_BYTES = 65536;

    private const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
    ];

    private string $root;

    public function __construct(string $publicDir, private readonly GameManager $games)
    {
        $this->root = (string) realpath($publicDir);
    }

    public function attach(Worker $worker): void
    {
        $worker->onConnect = function (TcpConnection $c): void {
            // Workerman responde 413 y corta antes de juntar en memoria un pedido más grande.
            $c->maxPackageSize = self::MAX_REQUEST_BYTES;
        };
        $worker->onMessage = [$this, 'handle'];
    }

    public function handle(TcpConnection $c, Request $r): void
    {
        $c->send($this->respond($r));
    }

    public function respond(Request $r): Response
    {
        try {
            $path = rawurldecode($r->path());
            // Un byte nulo hace que realpath() lance ValueError.
            if (str_contains($path, "\0")) {
                return $this->notFound();
            }
            if (str_starts_with($path, '/api/')) {
                return $this->api($r, substr($path, 5));
            }
            return $this->file($path);
        } catch (\Throwable $e) {
            Log::error('Error atendiendo un pedido HTTP: ' . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Error interno');
        }
    }

    private function api(Request $r, string $action): Response
    {
        $token = $r->get('t', '');
        $session = is_string($token) ? $this->games->get($token) : null;
        if ($session === null) {
            return $this->json(['error' => 'expired'], 410);
        }
        if ($action === 'poll') {
            $session->touch();
            return $this->json(['messages' => $session->since((int) $r->get('since', 0))]);
        }
        if ($action === 'send' && $r->method() === 'POST') {
            $msg = json_decode($r->rawBody(), true);
            if (!is_array($msg)) {
                return $this->json(['error' => 'bad_json'], 400);
            }
            $this->games->receive($session, $msg);
            return $this->json(['ok' => true]);
        }
        return $this->json(['error' => 'not_found'], 404);
    }

    private function file(string $path): Response
    {
        if ($path === '/' || $path === '') {
            return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], "Claudia está funcionando.\n");
        }
        $full = realpath($this->root . str_replace('/', DIRECTORY_SEPARATOR, $path));
        if ($full !== false && is_dir($full)) {
            $full = realpath($full . DIRECTORY_SEPARATOR . 'index.html');
        }
        if ($full === false || !is_file($full) || !str_starts_with($full, $this->root . DIRECTORY_SEPARATOR)) {
            return $this->notFound();
        }
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $type = self::TYPES[$ext] ?? 'application/octet-stream';
        $cache = in_array($ext, ['html', 'js', 'css', 'json'], true) ? 'no-cache' : 'public, max-age=86400';
        return (new Response(200, ['Content-Type' => $type, 'Cache-Control' => $cache]))->withFile($full);
    }

    private function notFound(): Response
    {
        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'No encontrado');
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ], (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
