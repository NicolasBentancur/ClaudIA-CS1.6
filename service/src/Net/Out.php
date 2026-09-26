<?php

declare(strict_types=1);

namespace Claudia\Net;

use Claudia\Util\Text;

/**
 * Salida hacia el juego. Traduce llamadas de alto nivel a eventos del protocolo
 * que el plugin ejecuta (print, sound, motd, auth.state).
 *
 * Destinos: 0 = todos, 1..32 = slot de jugador, -1 = consola del servidor.
 */
final class Out
{
    public const ALL = 0;
    public const SERVER = -1;

    /** Límite práctico de client_print_color (191 bytes) menos margen para color. */
    private const CHAT_BYTES = 186;
    private const CONSOLE_BYTES = 250;

    /** @var callable(string, array):void */
    private $sink;

    public function __construct(callable $sink, private string $tag = '{green}[Claudia]{default} ')
    {
        $this->sink = $sink;
    }

    public function setTag(string $tag): void
    {
        $this->tag = $tag;
    }

    /** Mensaje de sistema con la etiqueta [Claudia]. $text puede tener {green}/{default}/{team}. */
    public function chat(int $to, string $text): void
    {
        $this->rawChat($to, $this->tag . $text);
    }

    /** Mensaje de chat tal cual (con tags de color), partido si es largo. */
    public function rawChat(int $to, string $text): void
    {
        $text = Text::colors($text);
        foreach ($this->splitKeepingColor($text, self::CHAT_BYTES) as $line) {
            ($this->sink)('print', ['to' => $to, 'channel' => 'chat', 'text' => $line]);
        }
    }

    public function console(int $to, string $text): void
    {
        foreach (explode("\n", $text) as $row) {
            foreach (Text::splitBytes(Text::sanitize($row), self::CONSOLE_BYTES) ?: [''] as $line) {
                ($this->sink)('print', ['to' => $to, 'channel' => 'console', 'text' => $line]);
            }
        }
    }

    /** Reproduce un sonido (ruta relativa a sound/, sin extensión) solo para ese jugador. */
    public function sound(int $slot, string $sound): void
    {
        if ($slot > 0 && $sound !== '') {
            ($this->sink)('sound', ['slot' => $slot, 'sound' => $sound]);
        }
    }

    public function motd(int $slot, string $title, string $url): void
    {
        ($this->sink)('motd', ['slot' => $slot, 'title' => $title, 'url' => $url]);
    }

    /** Tag del grupo que el plugin antepone al nick en el chat ("" = sin tag). */
    public function chatTag(int $slot, string $tag): void
    {
        ($this->sink)('chat.tag', ['slot' => $slot, 'tag' => $tag]);
    }

    /**
     * Mientras está activo, el plugin no muestra lo que escribe el jugador en el chat y lo manda
     * como "input" (formularios por chat, ej. crear un grupo). Los /comandos siguen funcionando.
     */
    public function capture(int $slot, bool $on): void
    {
        ($this->sink)('input.capture', ['slot' => $slot, 'on' => $on]);
    }

    /**
     * Menú de HUD (newmenu de AMXX). $items son los textos (pueden tener \w \y \r \d de color);
     * el plugin devuelve "menu.select" con el índice elegido. La paginación la hace el plugin.
     * @param list<string> $items
     */
    public function menu(int $slot, int $id, string $title, array $items, int $page = 0): void
    {
        ($this->sink)('menu', ['slot' => $slot, 'id' => $id, 'title' => $title, 'items' => $items, 'page' => $page]);
    }

    /** Pide un texto con messagemode (el plugin lo devuelve como "menu.input"). */
    public function prompt(int $slot, string $label): void
    {
        ($this->sink)('prompt', ['slot' => $slot, 'label' => Text::colors($this->tag . $label)]);
    }

    /** Ejecuta en el cliente un comando de la lista blanca del plugin (ej. "amxmodmenu"). */
    public function clientCommand(int $slot, string $command): void
    {
        ($this->sink)('exec', ['slot' => $slot, 'cmd' => $command]);
    }

    /** Evento para otro plugin de Claudia (llega por el forward claudia_event). */
    public function event(string $type, array $data): void
    {
        ($this->sink)($type, $data);
    }

    public function authState(int $slot, bool $registered, bool $logged): void
    {
        ($this->sink)('auth.state', ['slot' => $slot, 'registered' => $registered, 'logged' => $logged]);
    }

    /**
     * Parte en líneas; cada línea nueva arranca con el último color usado en la anterior
     * para que el mensaje no pierda el formato.
     * @return list<string>
     */
    private function splitKeepingColor(string $text, int $max): array
    {
        $lines = [];
        $color = '';
        foreach (Text::splitBytes($text, $max) as $i => $chunk) {
            $line = $i === 0 ? $chunk : $color . $chunk;
            if (preg_match_all('/[\x01\x03\x04]/', $chunk, $m) && $m[0] !== []) {
                $color = end($m[0]);
            }
            $lines[] = $line;
        }
        return $lines;
    }
}
