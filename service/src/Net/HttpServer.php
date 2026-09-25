<?php

declare(strict_types=1);

namespace Claudia\Net;

use Claudia\Games\GameManager;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * Sirve las páginas de los juegos (public/) y la API de polling, que es el transporte
 * de respaldo cuando el navegador del MOTD no soporta WebSocket.
 *
 *   GET  /juegos/<juego>/?t=TOKEN          página del juego
 *   GET  /api/poll?t=TOKEN&since=SEQ       mensajes pendientes
 *   POST /api/send?t=TOKEN                 cuerpo JSON con la acción
 */
final class HttpServer
{
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

    public function handle(TcpConnection $c, Request $r): void
    {
        $path = rawurldecode($r->path());
        if (str_starts_with($path, '/api/')) {
            $c->send($this->api($r, substr($path, 5)));
            return;
        }
        $c->send($this->file($path));
    }

    private function api(Request $r, string $action): Response
    {
        $session = $this->games->get((string) $r->get('t', ''));
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
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'No encontrado');
        }
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $type = self::TYPES[$ext] ?? 'application/octet-stream';
        $cache = in_array($ext, ['html', 'js', 'css', 'json'], true) ? 'no-cache' : 'public, max-age=86400';
        return (new Response(200, ['Content-Type' => $type, 'Cache-Control' => $cache]))->withFile($full);
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ], (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
