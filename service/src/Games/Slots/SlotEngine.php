<?php

declare(strict_types=1);

namespace Claudia\Games\Slots;

/**
 * Matemática de un slot. Todo se expresa en múltiplos de la apuesta total: un resultado con
 * win = 2.5 paga 2,5 veces lo apostado. No toca dinero ni red (se puede simular).
 */
interface SlotEngine
{
    /**
     * Juega un giro completo, incluidos los giros gratis / bonus que dispare.
     * @param string|null $buy id de la compra de bonus (null = giro normal)
     * @return array{win: float, steps: list<array>, bonus: ?string, summary: string}
     */
    public function spin(Rng $rng, ?string $buy = null): array;

    /** Datos que la página necesita para dibujar (tamaño de grilla, símbolos, líneas...). */
    public function layout(): array;
}
