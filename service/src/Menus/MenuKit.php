<?php

declare(strict_types=1);

namespace Claudia\Menus;

use Claudia\App;
use Claudia\Players\Session;
use Closure;

/**
 * Piezas comunes para armar menús: ejecutar /comandos, pedir textos, confirmar y elegir jugadores.
 * Los menús reutilizan los comandos de chat, así la lógica y los mensajes son los mismos.
 */
final class MenuKit
{
    public function __construct(private readonly App $app)
    {
    }

    /** Acción: ejecuta /$name $args y vuelve a mostrar el menú. */
    public function cmd(string $name, string $args = ''): Closure
    {
        return fn (Session $s) => $this->run($s, $name, $args);
    }

    /** Acción: ejecuta /$name $args y cierra el menú (ej. juegos que abren el MOTD). */
    public function cmdClose(string $name, string $args = ''): Closure
    {
        return function (Session $s) use ($name, $args) {
            $this->app->commands->dispatch($s, $name, $args);
            return null;
        };
    }

    public function run(Session $s, string $name, string $args = ''): string
    {
        $this->app->commands->dispatch($s, $name, $args);
        return MenuService::STAY;
    }

    /** Ejecuta el comando y después abre $next. */
    public function runThen(Session $s, string $name, string $args, Closure $next): Closure
    {
        $this->app->commands->dispatch($s, $name, $args);
        return $next;
    }

    /**
     * Acción que pide un texto (messagemode) y se lo pasa a $onText(Session, string).
     * @param Closure(Session, string):mixed $onText
     */
    public function ask(string $label, Closure $onText): Closure
    {
        return fn (Session $s) => $this->app->menus->prompt($s, $label, $onText);
    }

    /** Menú de confirmación. $onYes es una acción; "No" vuelve a $back. */
    public function confirm(string $title, string $question, Closure $onYes, Closure $back): Closure
    {
        return function () use ($title, $question, $onYes, $back): Menu {
            return (new Menu($title, [$question]))
                ->add('\rSí, confirmar', $onYes)
                ->add('No', fn () => $back);
        };
    }

    /**
     * Menú para elegir un jugador conectado (y opción de escribir un nick para los desconectados).
     * @param Closure(Session, array<string,mixed>):mixed $onPick recibe la fila del usuario
     * @param null|Closure(Session, Session):bool $filter filtra a quién mostrar (recibe el que mira y el candidato)
     */
    public function pickPlayer(string $title, Closure $onPick, Closure $back, ?Closure $filter = null, bool $includeSelf = false, bool $allowTyped = true): Closure
    {
        return function (Session $s) use ($title, $onPick, $back, $filter, $includeSelf, $allowTyped): Menu {
            $m = new Menu($title);
            foreach ($this->app->sessions->logged() as $other) {
                if ((!$includeSelf && $other->slot === $s->slot) || ($filter !== null && !$filter($s, $other))) {
                    continue;
                }
                $uid = (int) $other->userId;
                $m->add($other->nick, fn (Session $s) => $onPick($s, (array) $this->app->users->find($uid)));
            }
            if ($allowTyped) {
                $m->add('\yEscribir un nick...', $this->ask('Escribí el nick del jugador', fn (Session $s, string $nick) => $onPick($s, $this->app->findUser($nick))));
            }
            if ($m->items === []) {
                $m->disabled('No hay nadie para elegir', 'No hay jugadores conectados que se puedan elegir.');
            }
            return $m->back($back);
        };
    }
}
