<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\Net\Out;
use Claudia\Players\Session;
use Claudia\UserError;

/**
 * Contexto de un comando de chat (/perfil, /prestamo, ...).
 */
final class CommandContext
{
    /** @var list<string> */
    public readonly array $argv;

    public function __construct(
        public readonly Session $session,
        public readonly string $name,
        public readonly string $args,
        public readonly bool $staff,
        public readonly bool $admin,
        private readonly Out $out,
    ) {
        $this->argv = preg_split('/\s+/u', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public function arg(int $i, ?string $default = null): ?string
    {
        return $this->argv[$i] ?? $default;
    }

    /** Argumentos desde $i hasta el final, como texto. */
    public function rest(int $i): string
    {
        return implode(' ', array_slice($this->argv, $i));
    }

    public function userId(): int
    {
        if ($this->session->userId === null) {
            throw new UserError('Tenés que loguearte primero: /login o /registrar.', 'not_logged');
        }
        return $this->session->userId;
    }

    public function reply(string $text): void
    {
        $this->out->chat($this->session->slot, $text);
    }

    public function console(string $text): void
    {
        $this->out->console($this->session->slot, $text);
    }

    public function out(): Out
    {
        return $this->out;
    }
}
