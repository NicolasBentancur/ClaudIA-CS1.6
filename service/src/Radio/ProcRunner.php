<?php

declare(strict_types=1);

namespace Claudia\Radio;

/**
 * ProcessRunner con proc_open: los argumentos van como array (sin pasar por la shell, así el texto
 * que escribe un jugador nunca se interpreta como comando) y la salida se lee sin bloquear.
 */
final class ProcRunner implements ProcessRunner
{
    /** @var array<int, array{proc:resource, pipes:array, out:string, err:string}> */
    private array $procs = [];
    private int $next = 1;

    public function start(array $cmd, array $env = []): int
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $spec, $pipes, null, $env === [] ? null : array_merge(getenv(), $env), ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('No se pudo ejecutar ' . basename($cmd[0]));
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $id = $this->next++;
        $this->procs[$id] = ['proc' => $proc, 'pipes' => $pipes, 'out' => '', 'err' => ''];
        return $id;
    }

    public function poll(int $id): ?array
    {
        if (!isset($this->procs[$id])) {
            return ['code' => -1, 'out' => '', 'err' => 'proceso desconocido'];
        }
        $p = &$this->procs[$id];
        // Leer siempre (si el buffer del pipe se llena, el proceso se queda esperando).
        $p['out'] .= (string) stream_get_contents($p['pipes'][1]);
        $p['err'] .= (string) stream_get_contents($p['pipes'][2]);
        if (strlen($p['err']) > 20000) {
            $p['err'] = substr($p['err'], -10000);
        }
        $status = proc_get_status($p['proc']);
        if ($status['running']) {
            return null;
        }
        $p['out'] .= (string) stream_get_contents($p['pipes'][1]);
        $p['err'] .= (string) stream_get_contents($p['pipes'][2]);
        fclose($p['pipes'][1]);
        fclose($p['pipes'][2]);
        proc_close($p['proc']);
        $result = ['code' => (int) $status['exitcode'], 'out' => $p['out'], 'err' => $p['err']];
        unset($this->procs[$id]);
        return $result;
    }

    public function kill(int $id): void
    {
        if (!isset($this->procs[$id])) {
            return;
        }
        $p = $this->procs[$id];
        proc_terminate($p['proc']);
        foreach ([1, 2] as $i) {
            if (is_resource($p['pipes'][$i])) {
                fclose($p['pipes'][$i]);
            }
        }
        proc_close($p['proc']);
        unset($this->procs[$id]);
    }
}
