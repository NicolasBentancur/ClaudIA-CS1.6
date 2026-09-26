<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Net\HttpServer;
use Claudia\Tests\Support\AppTestCase;
use Workerman\Protocols\Http\Request;

final class HttpServerTest extends AppTestCase
{
    private function statusOf(string $target): int
    {
        $server = new HttpServer(dirname(__DIR__) . '/public', $this->app->games);
        return $server->respond(new Request("GET {$target} HTTP/1.1\r\nHost: localhost\r\n\r\n"))->getStatusCode();
    }

    public function testNullByteInPathIsNotFound(): void
    {
        // Antes, realpath() lanzaba ValueError y Workerman cortaba el proceso entero.
        $this->assertSame(404, $this->statusOf('/%00'));
        $this->assertSame(404, $this->statusOf('/juegos/ruleta/%00/../index.html'));
    }

    public function testServesGamePagesButNothingOutsidePublic(): void
    {
        $this->assertSame(200, $this->statusOf('/juegos/ruleta/'));
        $this->assertSame(404, $this->statusOf('/../composer.json'));
        $this->assertSame(404, $this->statusOf('/%2e%2e/composer.json'));
    }

    public function testApiRejectsUnknownOrMalformedTokens(): void
    {
        $this->assertSame(410, $this->statusOf('/api/poll?t=nada'));
        $this->assertSame(410, $this->statusOf('/api/poll?t[]=x'));
    }
}
