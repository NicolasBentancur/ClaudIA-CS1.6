<?php

declare(strict_types=1);

namespace Claudia\Menus;

use Closure;

/**
 * Un menú de HUD armado por el servicio. Cada opción tiene una acción:
 *   fn (Session $s): mixed
 * que devuelve lo que pasa después (ver MenuService): otro menú (Closure constructora),
 * MenuService::STAY para volver a mostrar este mismo, o null para cerrarlo.
 */
final class Menu
{
    /** @var list<array{label:string, action:?Closure, disabled:?string}> */
    public array $items = [];

    /** Menú anterior (se agrega "Volver" al final). */
    public ?Closure $back = null;

    /** @param list<string> $lines renglones debajo del título (info) */
    public function __construct(public string $title, public array $lines = [])
    {
    }

    public function add(string $label, ?Closure $action): self
    {
        $this->items[] = ['label' => $label, 'action' => $action, 'disabled' => null];
        return $this;
    }

    /** Opción que se ve en gris; al elegirla se explica por qué no está disponible. */
    public function disabled(string $label, string $reason): self
    {
        $this->items[] = ['label' => $label, 'action' => null, 'disabled' => $reason];
        return $this;
    }

    /** Agrega la opción solo si $allowed; si no, no aparece. */
    public function addIf(bool $allowed, string $label, ?Closure $action): self
    {
        return $allowed ? $this->add($label, $action) : $this;
    }

    public function back(?Closure $builder): self
    {
        $this->back = $builder;
        return $this;
    }

    public function renderTitle(): string
    {
        $t = '\yClaudia \w- ' . $this->title;
        foreach ($this->lines as $line) {
            $t .= "\n\\d" . $line;
        }
        return $t . "\n";
    }

    /** @return list<string> */
    public function labels(): array
    {
        $out = [];
        foreach ($this->items as $item) {
            $out[] = $item['disabled'] !== null ? '\d' . $item['label'] : '\w' . $item['label'];
        }
        if ($this->back !== null) {
            $out[] = '\yVolver';
        }
        return $out;
    }
}
