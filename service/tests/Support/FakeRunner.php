<?php

declare(strict_types=1);

namespace Claudia\Tests\Support;

use Claudia\Radio\ProcessRunner;

/**
 * Procesos simulados: cada start() queda "corriendo" hasta que el test lo termina con finish().
 * $onFinish permite simular lo que el programa deja en disco (el audio bajado, los trozos).
 */
final class FakeRunner implements ProcessRunner
{
    /** @var array<int, array{cmd:list<string>, result:?array}> */
    public array $procs = [];
    private int $next = 1;

    public function start(array $cmd, array $env = []): int
    {
        $id = $this->next++;
        $this->procs[$id] = ['cmd' => $cmd, 'result' => null];
        return $id;
    }

    public function poll(int $id): ?array
    {
        return $this->procs[$id]['result'] ?? null;
    }

    public function kill(int $id): void
    {
        unset($this->procs[$id]);
    }

    /** Último proceso lanzado (sin terminar). */
    public function running(): ?int
    {
        foreach (array_reverse($this->procs, true) as $id => $p) {
            if ($p['result'] === null) {
                return $id;
            }
        }
        return null;
    }

    public function finish(int $id, int $code, string $out = '', string $err = ''): void
    {
        $this->procs[$id]['result'] = ['code' => $code, 'out' => $out, 'err' => $err];
    }
}
