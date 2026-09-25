<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\Log;
use Claudia\Net\Out;
use Claudia\Players\Session;
use Claudia\UserError;

/**
 * Registro de comandos de chat. El plugin recibe la lista de nombres en el handshake,
 * bloquea esos "say" y los reenvía acá como mensajes "cmd".
 */
final class CommandRouter
{
    /** @var array<string, array{handler:callable, login:bool, help:string, main:string, section:string}> */
    private array $commands = [];

    public function __construct(private readonly Out $out)
    {
    }

    /**
     * @param callable(CommandContext):void $handler
     * @param list<string> $aliases
     */
    public function register(string $name, callable $handler, string $help, string $section, bool $requiresLogin = true, array $aliases = []): void
    {
        foreach (array_merge([$name], $aliases) as $n) {
            $this->commands[mb_strtolower($n)] = [
                'handler' => $handler,
                'login' => $requiresLogin,
                'help' => $help,
                'main' => $name,
                'section' => $section,
            ];
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->commands);
    }

    public function has(string $name): bool
    {
        return isset($this->commands[mb_strtolower($name)]);
    }

    /** @return array<string, list<array{name:string, help:string}>> ayuda agrupada por sección */
    public function help(): array
    {
        $out = [];
        foreach ($this->commands as $key => $c) {
            if ($key !== $c['main']) {
                continue;
            }
            $out[$c['section']][] = ['name' => $c['main'], 'help' => $c['help']];
        }
        return $out;
    }

    public function dispatch(Session $session, string $name, string $args, bool $staff = false, bool $admin = false): void
    {
        $cmd = $this->commands[mb_strtolower($name)] ?? null;
        if ($cmd === null) {
            return;
        }
        $ctx = new CommandContext($session, $cmd['main'], $args, $staff, $admin, $this->out);
        try {
            if ($cmd['login']) {
                $ctx->userId();
            }
            ($cmd['handler'])($ctx);
        } catch (UserError $e) {
            $ctx->reply($e->getMessage());
        } catch (\Throwable $e) {
            Log::error("Error en /{$name}: " . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
            $ctx->reply('Se me trancó algo, probá de nuevo en un rato.');
        }
    }
}
