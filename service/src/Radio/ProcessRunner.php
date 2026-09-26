<?php

declare(strict_types=1);

namespace Claudia\Radio;

/**
 * Procesos externos (yt-dlp, ffmpeg) sin bloquear el servicio: se lanzan y se consultan en cada tick.
 */
interface ProcessRunner
{
    /**
     * @param list<string> $cmd programa y argumentos (sin shell)
     * @param array<string, string> $env variables de entorno extra
     * @return int identificador del proceso
     */
    public function start(array $cmd, array $env = []): int;

    /** @return array{code:int, out:string, err:string}|null null mientras sigue corriendo */
    public function poll(int $id): ?array;

    public function kill(int $id): void;
}
