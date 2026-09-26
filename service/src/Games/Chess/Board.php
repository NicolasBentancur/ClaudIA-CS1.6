<?php

declare(strict_types=1);

namespace Claudia\Games\Chess;

/**
 * Posición de ajedrez inmutable con todas las reglas: movimientos legales, jaque, enroque,
 * captura al paso, coronación, y los datos para detectar tablas (regla de 50 movimientos,
 * repetición y material insuficiente).
 *
 * Casillas: índice 0..63 con a1 = 0, b1 = 1 ... h8 = 63 (casilla = fila * 8 + columna).
 * Piezas: letras de FEN (PNBRQK blancas, pnbrqk negras); casilla vacía = ''.
 *
 * Una jugada es un array: from, to, piece, captured ('' si no captura), promo ('' o q/r/b/n
 * en minúscula), flags: 'n' normal, 'd' avance doble, 'e' al paso, 'k' enroque corto,
 * 'q' enroque largo.
 */
final class Board
{
    public const START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    private const KNIGHT = [[1, 2], [2, 1], [2, -1], [1, -2], [-1, -2], [-2, -1], [-2, 1], [-1, 2]];
    private const KING = [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]];
    private const ROOK_DIRS = [[1, 0], [-1, 0], [0, 1], [0, -1]];
    private const BISHOP_DIRS = [[1, 1], [1, -1], [-1, 1], [-1, -1]];

    /** Letras de las piezas en notación española (rey, dama, torre, alfil, caballo). */
    private const SAN_ES = ['k' => 'R', 'q' => 'D', 'r' => 'T', 'b' => 'A', 'n' => 'C'];

    /** @var list<string> */
    private array $sq;
    private string $turn;
    /** @var array{K:bool, Q:bool, k:bool, q:bool} */
    private array $castle;
    private int $ep;
    private int $halfmove;
    private int $fullmove;

    /** @var list<array>|null cache de jugadas legales */
    private ?array $legalCache = null;

    private function __construct()
    {
    }

    public static function start(): self
    {
        return self::fromFen(self::START);
    }

    public static function fromFen(string $fen): self
    {
        $parts = preg_split('/\s+/', trim($fen));
        if ($parts === false || count($parts) < 4) {
            throw new \InvalidArgumentException('FEN inválido');
        }
        $b = new self();
        $b->sq = array_fill(0, 64, '');
        $rows = explode('/', $parts[0]);
        if (count($rows) !== 8) {
            throw new \InvalidArgumentException('FEN inválido');
        }
        foreach ($rows as $i => $row) {
            $rank = 7 - $i;
            $file = 0;
            foreach (str_split($row) as $ch) {
                if (ctype_digit($ch)) {
                    $file += (int) $ch;
                } else {
                    $b->sq[$rank * 8 + $file] = $ch;
                    $file++;
                }
            }
        }
        $b->turn = $parts[1] === 'b' ? 'b' : 'w';
        $rights = $parts[2];
        $b->castle = [
            'K' => str_contains($rights, 'K'), 'Q' => str_contains($rights, 'Q'),
            'k' => str_contains($rights, 'k'), 'q' => str_contains($rights, 'q'),
        ];
        $b->ep = $parts[3] === '-' ? -1 : self::index($parts[3]);
        $b->halfmove = (int) ($parts[4] ?? 0);
        $b->fullmove = max(1, (int) ($parts[5] ?? 1));
        return $b;
    }

    public function fen(): string
    {
        return $this->placement() . ' ' . $this->turn . ' ' . $this->castleString() . ' '
            . ($this->ep >= 0 ? self::name($this->ep) : '-') . ' ' . $this->halfmove . ' ' . $this->fullmove;
    }

    /** Clave de la posición para la triple repetición (sin los contadores de jugadas). */
    public function positionKey(): string
    {
        // La captura al paso solo cuenta si realmente se puede hacer.
        $ep = '-';
        if ($this->ep >= 0) {
            foreach ($this->legalMoves() as $m) {
                if ($m['flags'] === 'e') {
                    $ep = self::name($this->ep);
                    break;
                }
            }
        }
        return $this->placement() . ' ' . $this->turn . ' ' . $this->castleString() . ' ' . $ep;
    }

    public function turn(): string
    {
        return $this->turn;
    }

    public function halfmove(): int
    {
        return $this->halfmove;
    }

    public function fullmove(): int
    {
        return $this->fullmove;
    }

    /** @return list<string> las 64 casillas ('' vacía) */
    public function squares(): array
    {
        return $this->sq;
    }

    public function at(int $sq): string
    {
        return $this->sq[$sq];
    }

    public static function index(string $name): int
    {
        if (!preg_match('/^([a-h])([1-8])$/', $name, $m)) {
            throw new \InvalidArgumentException("Casilla inválida: {$name}");
        }
        return (ord($m[2]) - ord('1')) * 8 + (ord($m[1]) - ord('a'));
    }

    public static function name(int $sq): string
    {
        return chr(ord('a') + $sq % 8) . chr(ord('1') + intdiv($sq, 8));
    }

    public static function colorOf(string $piece): string
    {
        return $piece === '' ? '' : (ctype_upper($piece) ? 'w' : 'b');
    }

    /* ------------------------------------------------------------------
     * Jugadas
     * ---------------------------------------------------------------- */

    /** @return list<array> jugadas legales del bando que mueve */
    public function legalMoves(): array
    {
        if ($this->legalCache !== null) {
            return $this->legalCache;
        }
        $legal = [];
        foreach ($this->pseudoMoves() as $m) {
            $next = $this->apply($m);
            if (!$next->kingAttacked($this->turn)) {
                $legal[] = $m;
            }
        }
        return $this->legalCache = $legal;
    }

    /** Busca la jugada legal from->to (con coronación si hace falta; por defecto dama). */
    public function findMove(int $from, int $to, string $promo = ''): ?array
    {
        $promo = strtolower($promo);
        foreach ($this->legalMoves() as $m) {
            if ($m['from'] === $from && $m['to'] === $to && ($m['promo'] === '' || $m['promo'] === ($promo !== '' ? $promo : 'q'))) {
                return $m;
            }
        }
        return null;
    }

    /** Aplica una jugada (que tiene que venir de legalMoves) y devuelve la posición nueva. */
    public function play(array $m): self
    {
        return $this->apply($m);
    }

    public function inCheck(): bool
    {
        return $this->kingAttacked($this->turn);
    }

    public function isCheckmate(): bool
    {
        return $this->inCheck() && $this->legalMoves() === [];
    }

    public function isStalemate(): bool
    {
        return !$this->inCheck() && $this->legalMoves() === [];
    }

    /** Ninguno de los dos puede dar mate con lo que tiene. */
    public function insufficientMaterial(): bool
    {
        $minor = [];
        foreach ($this->sq as $i => $p) {
            if ($p === '' || strtolower($p) === 'k') {
                continue;
            }
            $t = strtolower($p);
            if ($t === 'p' || $t === 'r' || $t === 'q') {
                return false;
            }
            $minor[] = ['type' => $t, 'color' => self::colorOf($p), 'light' => (intdiv($i, 8) + $i % 8) % 2 === 1];
        }
        if (count($minor) <= 1) {
            return true; // rey solo, o rey y un alfil/caballo contra rey
        }
        // Solo alfiles, todos en casillas del mismo color.
        foreach ($minor as $m) {
            if ($m['type'] !== 'b' || $m['light'] !== $minor[0]['light']) {
                return false;
            }
        }
        return true;
    }

    /** Al bando $color le queda solo el rey (no puede ganar por tiempo). */
    public function onlyKing(string $color): bool
    {
        foreach ($this->sq as $p) {
            if ($p !== '' && self::colorOf($p) === $color && strtolower($p) !== 'k') {
                return false;
            }
        }
        return true;
    }

    /** Casilla del rey del bando que mueve si está en jaque (para resaltarla), o -1. */
    public function checkedKing(): int
    {
        return $this->inCheck() ? $this->kingSquare($this->turn) : -1;
    }

    /** Notación algebraica en español (Cf3, exd5, O-O, e8=D+, Dxf7#). */
    public function san(array $m): string
    {
        $type = strtolower($m['piece']);
        if ($m['flags'] === 'k') {
            $s = 'O-O';
        } elseif ($m['flags'] === 'q') {
            $s = 'O-O-O';
        } elseif ($type === 'p') {
            $s = $m['captured'] !== '' ? self::name($m['from'])[0] . 'x' . self::name($m['to']) : self::name($m['to']);
            if ($m['promo'] !== '') {
                $s .= '=' . self::SAN_ES[$m['promo']];
            }
        } else {
            $s = self::SAN_ES[$type] . $this->disambiguation($m) . ($m['captured'] !== '' ? 'x' : '') . self::name($m['to']);
        }
        $next = $this->apply($m);
        if ($next->isCheckmate()) {
            $s .= '#';
        } elseif ($next->inCheck()) {
            $s .= '+';
        }
        return $s;
    }

    /** Cantidad de posiciones a $depth jugadas (para verificar el generador). */
    public function perft(int $depth): int
    {
        if ($depth === 0) {
            return 1;
        }
        $moves = $this->legalMoves();
        if ($depth === 1) {
            return count($moves);
        }
        $n = 0;
        foreach ($moves as $m) {
            $n += $this->apply($m)->perft($depth - 1);
        }
        return $n;
    }

    /* ------------------------------------------------------------------
     * Internos
     * ---------------------------------------------------------------- */

    private function disambiguation(array $m): string
    {
        $sameFile = false;
        $sameRank = false;
        $other = false;
        foreach ($this->legalMoves() as $o) {
            if ($o['to'] === $m['to'] && $o['piece'] === $m['piece'] && $o['from'] !== $m['from']) {
                $other = true;
                if ($o['from'] % 8 === $m['from'] % 8) {
                    $sameFile = true;
                }
                if (intdiv($o['from'], 8) === intdiv($m['from'], 8)) {
                    $sameRank = true;
                }
            }
        }
        if (!$other) {
            return '';
        }
        $name = self::name($m['from']);
        if (!$sameFile) {
            return $name[0];
        }
        if (!$sameRank) {
            return $name[1];
        }
        return $name;
    }

    /** @return list<array> */
    private function pseudoMoves(): array
    {
        $moves = [];
        $us = $this->turn;
        foreach ($this->sq as $from => $p) {
            if ($p === '' || self::colorOf($p) !== $us) {
                continue;
            }
            $f = $from % 8;
            $r = intdiv($from, 8);
            switch (strtolower($p)) {
                case 'p':
                    $this->pawnMoves($from, $p, $moves);
                    break;
                case 'n':
                    $this->stepMoves($from, $p, $f, $r, self::KNIGHT, $moves);
                    break;
                case 'k':
                    $this->stepMoves($from, $p, $f, $r, self::KING, $moves);
                    $this->castleMoves($from, $p, $moves);
                    break;
                case 'b':
                    $this->slideMoves($from, $p, $f, $r, self::BISHOP_DIRS, $moves);
                    break;
                case 'r':
                    $this->slideMoves($from, $p, $f, $r, self::ROOK_DIRS, $moves);
                    break;
                case 'q':
                    $this->slideMoves($from, $p, $f, $r, self::ROOK_DIRS, $moves);
                    $this->slideMoves($from, $p, $f, $r, self::BISHOP_DIRS, $moves);
                    break;
            }
        }
        return $moves;
    }

    private function pawnMoves(int $from, string $p, array &$moves): void
    {
        $white = $p === 'P';
        $dir = $white ? 8 : -8;
        $f = $from % 8;
        $r = intdiv($from, 8);
        $startRank = $white ? 1 : 6;
        $lastRank = $white ? 7 : 0;

        $one = $from + $dir;
        if ($one >= 0 && $one < 64 && $this->sq[$one] === '') {
            $this->addPawn($from, $one, $p, '', 'n', intdiv($one, 8) === $lastRank, $moves);
            $two = $one + $dir;
            if ($r === $startRank && $this->sq[$two] === '') {
                $moves[] = ['from' => $from, 'to' => $two, 'piece' => $p, 'captured' => '', 'promo' => '', 'flags' => 'd'];
            }
        }
        foreach ([-1, 1] as $df) {
            $tf = $f + $df;
            if ($tf < 0 || $tf > 7) {
                continue;
            }
            $to = $one + $df;
            if ($to < 0 || $to > 63) {
                continue;
            }
            $target = $this->sq[$to];
            if ($target !== '' && self::colorOf($target) !== self::colorOf($p)) {
                $this->addPawn($from, $to, $p, $target, 'n', intdiv($to, 8) === $lastRank, $moves);
            } elseif ($to === $this->ep) {
                $moves[] = ['from' => $from, 'to' => $to, 'piece' => $p, 'captured' => $white ? 'p' : 'P', 'promo' => '', 'flags' => 'e'];
            }
        }
    }

    private function addPawn(int $from, int $to, string $p, string $captured, string $flags, bool $promotes, array &$moves): void
    {
        if (!$promotes) {
            $moves[] = ['from' => $from, 'to' => $to, 'piece' => $p, 'captured' => $captured, 'promo' => '', 'flags' => $flags];
            return;
        }
        foreach (['q', 'r', 'b', 'n'] as $promo) {
            $moves[] = ['from' => $from, 'to' => $to, 'piece' => $p, 'captured' => $captured, 'promo' => $promo, 'flags' => $flags];
        }
    }

    private function stepMoves(int $from, string $p, int $f, int $r, array $steps, array &$moves): void
    {
        foreach ($steps as [$df, $dr]) {
            $tf = $f + $df;
            $tr = $r + $dr;
            if ($tf < 0 || $tf > 7 || $tr < 0 || $tr > 7) {
                continue;
            }
            $to = $tr * 8 + $tf;
            $target = $this->sq[$to];
            if ($target === '' || self::colorOf($target) !== self::colorOf($p)) {
                $moves[] = ['from' => $from, 'to' => $to, 'piece' => $p, 'captured' => $target, 'promo' => '', 'flags' => 'n'];
            }
        }
    }

    private function slideMoves(int $from, string $p, int $f, int $r, array $dirs, array &$moves): void
    {
        foreach ($dirs as [$df, $dr]) {
            $tf = $f + $df;
            $tr = $r + $dr;
            while ($tf >= 0 && $tf <= 7 && $tr >= 0 && $tr <= 7) {
                $to = $tr * 8 + $tf;
                $target = $this->sq[$to];
                if ($target === '') {
                    $moves[] = ['from' => $from, 'to' => $to, 'piece' => $p, 'captured' => '', 'promo' => '', 'flags' => 'n'];
                } else {
                    if (self::colorOf($target) !== self::colorOf($p)) {
                        $moves[] = ['from' => $from, 'to' => $to, 'piece' => $p, 'captured' => $target, 'promo' => '', 'flags' => 'n'];
                    }
                    break;
                }
                $tf += $df;
                $tr += $dr;
            }
        }
    }

    private function castleMoves(int $from, string $p, array &$moves): void
    {
        $white = $p === 'K';
        $home = $white ? 4 : 60;
        if ($from !== $home) {
            return;
        }
        $them = $white ? 'b' : 'w';
        $rook = $white ? 'R' : 'r';
        if ($this->castle[$white ? 'K' : 'k'] && $this->sq[$home + 3] === $rook
            && $this->sq[$home + 1] === '' && $this->sq[$home + 2] === ''
            && !$this->attacked($home, $them) && !$this->attacked($home + 1, $them) && !$this->attacked($home + 2, $them)) {
            $moves[] = ['from' => $home, 'to' => $home + 2, 'piece' => $p, 'captured' => '', 'promo' => '', 'flags' => 'k'];
        }
        if ($this->castle[$white ? 'Q' : 'q'] && $this->sq[$home - 4] === $rook
            && $this->sq[$home - 1] === '' && $this->sq[$home - 2] === '' && $this->sq[$home - 3] === ''
            && !$this->attacked($home, $them) && !$this->attacked($home - 1, $them) && !$this->attacked($home - 2, $them)) {
            $moves[] = ['from' => $home, 'to' => $home - 2, 'piece' => $p, 'captured' => '', 'promo' => '', 'flags' => 'q'];
        }
    }

    private function apply(array $m): self
    {
        $b = clone $this;
        $b->legalCache = null;
        $p = $m['piece'];
        $white = self::colorOf($p) === 'w';
        $b->sq[$m['from']] = '';
        $b->sq[$m['to']] = $m['promo'] !== '' ? ($white ? strtoupper($m['promo']) : $m['promo']) : $p;
        if ($m['flags'] === 'e') {
            $b->sq[$m['to'] + ($white ? -8 : 8)] = '';
        } elseif ($m['flags'] === 'k') {
            $b->sq[$m['from'] + 1] = $b->sq[$m['from'] + 3];
            $b->sq[$m['from'] + 3] = '';
        } elseif ($m['flags'] === 'q') {
            $b->sq[$m['from'] - 1] = $b->sq[$m['from'] - 4];
            $b->sq[$m['from'] - 4] = '';
        }

        // Derechos de enroque: se pierden al mover el rey o una torre, o si capturan la torre.
        if ($p === 'K') {
            $b->castle['K'] = $b->castle['Q'] = false;
        } elseif ($p === 'k') {
            $b->castle['k'] = $b->castle['q'] = false;
        }
        foreach ([0 => 'Q', 7 => 'K', 56 => 'q', 63 => 'k'] as $corner => $right) {
            if ($m['from'] === $corner || $m['to'] === $corner) {
                $b->castle[$right] = false;
            }
        }

        $b->ep = $m['flags'] === 'd' ? ($m['from'] + $m['to']) >> 1 : -1;
        $b->halfmove = (strtolower($p) === 'p' || $m['captured'] !== '') ? 0 : $this->halfmove + 1;
        if (!$white) {
            $b->fullmove++;
        }
        $b->turn = $white ? 'b' : 'w';
        return $b;
    }

    private function kingSquare(string $color): int
    {
        $king = $color === 'w' ? 'K' : 'k';
        $i = array_search($king, $this->sq, true);
        return $i === false ? -1 : (int) $i;
    }

    private function kingAttacked(string $color): bool
    {
        $k = $this->kingSquare($color);
        return $k >= 0 && $this->attacked($k, $color === 'w' ? 'b' : 'w');
    }

    /** ¿La casilla está atacada por el bando $by? */
    private function attacked(int $sq, string $by): bool
    {
        $f = $sq % 8;
        $r = intdiv($sq, 8);
        $white = $by === 'w';

        // Peones: atacan en diagonal hacia adelante, así que se miran desde atrás.
        $pr = $r + ($white ? -1 : 1);
        if ($pr >= 0 && $pr <= 7) {
            foreach ([-1, 1] as $df) {
                $pf = $f + $df;
                if ($pf >= 0 && $pf <= 7 && $this->sq[$pr * 8 + $pf] === ($white ? 'P' : 'p')) {
                    return true;
                }
            }
        }
        foreach ([[self::KNIGHT, 'n'], [self::KING, 'k']] as [$steps, $type]) {
            $piece = $white ? strtoupper($type) : $type;
            foreach ($steps as [$df, $dr]) {
                $tf = $f + $df;
                $tr = $r + $dr;
                if ($tf >= 0 && $tf <= 7 && $tr >= 0 && $tr <= 7 && $this->sq[$tr * 8 + $tf] === $piece) {
                    return true;
                }
            }
        }
        foreach ([[self::ROOK_DIRS, 'r'], [self::BISHOP_DIRS, 'b']] as [$dirs, $type]) {
            $slider = $white ? strtoupper($type) : $type;
            $queen = $white ? 'Q' : 'q';
            foreach ($dirs as [$df, $dr]) {
                $tf = $f + $df;
                $tr = $r + $dr;
                while ($tf >= 0 && $tf <= 7 && $tr >= 0 && $tr <= 7) {
                    $p = $this->sq[$tr * 8 + $tf];
                    if ($p !== '') {
                        if ($p === $slider || $p === $queen) {
                            return true;
                        }
                        break;
                    }
                    $tf += $df;
                    $tr += $dr;
                }
            }
        }
        return false;
    }

    private function placement(): string
    {
        $rows = [];
        for ($rank = 7; $rank >= 0; $rank--) {
            $row = '';
            $empty = 0;
            for ($file = 0; $file < 8; $file++) {
                $p = $this->sq[$rank * 8 + $file];
                if ($p === '') {
                    $empty++;
                    continue;
                }
                if ($empty > 0) {
                    $row .= $empty;
                    $empty = 0;
                }
                $row .= $p;
            }
            $rows[] = $row . ($empty > 0 ? $empty : '');
        }
        return implode('/', $rows);
    }

    private function castleString(): string
    {
        $s = '';
        foreach (['K', 'Q', 'k', 'q'] as $r) {
            if ($this->castle[$r]) {
                $s .= $r;
            }
        }
        return $s === '' ? '-' : $s;
    }
}
