<?php

declare(strict_types=1);

namespace Claudia\Menus;

use Claudia\Log;
use Claudia\Net\Out;
use Claudia\Players\Session;
use Claudia\UserError;
use Closure;

/**
 * Menús abiertos por jugador. El plugin muestra el menú y devuelve la opción elegida
 * ("menu.select") o el texto pedido con messagemode ("menu.input").
 *
 * Lo que devuelve una acción decide qué se muestra después:
 *   Closure        abre ese menú (fn (Session): Menu)
 *   self::STAY     vuelve a mostrar el menú actual (en la misma página)
 *   null           no muestra nada (ej. se abrió un juego en el MOTD)
 */
final class MenuService
{
    public const STAY = 'stay';

    /** @var array<int, array{id:int, builder:Closure, menu:Menu, page:int, uid:?int, prompt:?Closure}> por slot */
    private array $state = [];
    private int $seq = 0;

    public function __construct(private readonly Out $out)
    {
    }

    /** @param Closure(Session):Menu $builder */
    public function open(Session $s, Closure $builder, int $page = 0): void
    {
        $menu = $builder($s);
        if ($menu->labels() === []) {
            unset($this->state[$s->slot]);
            return;
        }
        $id = ++$this->seq;
        $this->state[$s->slot] = ['id' => $id, 'builder' => $builder, 'menu' => $menu, 'page' => $page, 'uid' => $s->userId, 'prompt' => null];
        $this->out->menu($s->slot, $id, $menu->renderTitle(), $menu->labels(), $page);
    }

    /** Vuelve a armar y mostrar el menú actual (en la página donde estaba). */
    public function refresh(Session $s): void
    {
        $st = $this->state[$s->slot] ?? null;
        if ($st !== null) {
            $this->open($s, $st['builder'], $st['page']);
        }
    }

    public function current(Session $s): ?Menu
    {
        return $this->state[$s->slot]['menu'] ?? null;
    }

    public function select(Session $s, int $id, int $index, int $page = 0): void
    {
        $st = $this->state[$s->slot] ?? null;
        if ($st === null || $st['id'] !== $id || $st['uid'] !== $s->userId) {
            return;
        }
        $this->state[$s->slot]['page'] = $page;
        $menu = $st['menu'];
        if ($menu->back !== null && $index === count($menu->items)) {
            $this->open($s, $menu->back);
            return;
        }
        $item = $menu->items[$index] ?? null;
        if ($item === null) {
            return;
        }
        if ($item['disabled'] !== null) {
            $this->out->chat($s->slot, $item['disabled']);
            $this->refresh($s);
            return;
        }
        if ($item['action'] === null) {
            $this->refresh($s);
            return;
        }
        $this->run($s, fn () => ($item['action'])($s));
    }

    /**
     * Pide un texto al jugador (monto, nick, descripción...). $onText recibe (Session, string)
     * y devuelve lo mismo que una acción. Texto vacío = cancelar.
     * @param Closure(Session, string):mixed $onText
     */
    public function prompt(Session $s, string $label, Closure $onText): mixed
    {
        if (!isset($this->state[$s->slot])) {
            // Sin menú abierto (ej. llamado desde un comando): se crea un estado mínimo.
            $this->state[$s->slot] = ['id' => ++$this->seq, 'builder' => fn () => new Menu(''), 'menu' => new Menu(''), 'page' => 0, 'uid' => $s->userId, 'prompt' => null];
        }
        $this->state[$s->slot]['prompt'] = $onText;
        $this->out->prompt($s->slot, $label . ' {default}(vacío para cancelar)');
        return null;
    }

    public function input(Session $s, string $text): void
    {
        $st = $this->state[$s->slot] ?? null;
        if ($st === null || $st['prompt'] === null || $st['uid'] !== $s->userId) {
            return;
        }
        $this->state[$s->slot]['prompt'] = null;
        $text = trim($text);
        if ($text === '') {
            $this->out->chat($s->slot, 'Cancelado.');
            $this->refresh($s);
            return;
        }
        $this->run($s, fn () => ($st['prompt'])($s, $text));
    }

    /** El jugador cerró el menú. */
    public function close(Session $s): void
    {
        if (isset($this->state[$s->slot]) && $this->state[$s->slot]['prompt'] === null) {
            unset($this->state[$s->slot]);
        }
    }

    public function drop(int $slot): void
    {
        unset($this->state[$slot]);
    }

    /** Ejecuta una acción y muestra lo que corresponda después. */
    private function run(Session $s, Closure $action): void
    {
        try {
            $next = $action();
        } catch (UserError $e) {
            $this->out->chat($s->slot, $e->getMessage());
            $next = self::STAY;
        } catch (\Throwable $e) {
            Log::error('Error en menú: ' . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
            $this->out->chat($s->slot, 'Se me trancó el menú, probá de nuevo.');
            return;
        }
        // Si la acción pidió un texto, el menú se vuelve a mostrar cuando llegue la respuesta.
        if (($this->state[$s->slot]['prompt'] ?? null) !== null) {
            return;
        }
        if ($next instanceof Closure) {
            $this->open($s, $next);
        } elseif ($next === self::STAY) {
            $this->refresh($s);
        }
    }
}
