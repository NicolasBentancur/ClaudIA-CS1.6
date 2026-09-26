<?php

declare(strict_types=1);

namespace Claudia\Games\Chess;

use Claudia\App;
use Claudia\Clock;
use Claudia\Games\GameSession;
use Claudia\Log;
use Claudia\Menus\Menu;
use Claudia\Net\Out;
use Claudia\Players\Session;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Ajedrez 1v1 por apuesta (config/games/ajedrez.json).
 *
 * Flujo: desafío (/ajedrez <nick> <monto>) -> el desafiado acepta desde un menú -> se retiene la
 * apuesta de los dos como rondas del casino (si el servicio se reinicia, Casino las devuelve) ->
 * los dos pasan a espectador y se les abre el tablero -> al terminar, el ganador cobra el pozo
 * menos la comisión de la casa; en tablas cada uno recupera lo suyo.
 *
 * El plugin claudia_ajedrez recibe: chess.spec (mandar a espectador / devolver al equipo),
 * chess.voice (+voicerecord / -voicerecord) y chess.pair (voz privada entre los dos).
 */
final class ChessService
{
    /** @var array<int, array{id:int, a:int, b:int, amount:int, expires:int}> */
    private array $challenges = [];
    /** @var array<int, ChessMatch> */
    private array $matches = [];
    private int $nextId = 1;

    private const REASONS = [
        'mate' => 'por jaque mate',
        'time' => 'por tiempo',
        'resign' => 'por abandono (se rindió)',
        'abandon' => 'porque el rival se fue',
        'stalemate' => 'por ahogado',
        'material' => 'por material insuficiente',
        'repetition' => 'por triple repetición',
        'fifty' => 'por la regla de los 50 movimientos',
        'agreement' => 'de común acuerdo',
        'timeout_material' => 'por tiempo, pero el rival no tenía con qué dar mate',
    ];

    public function __construct(private readonly App $app)
    {
    }

    private function cfg(string $key, mixed $default): mixed
    {
        return $this->app->config->get("games.ajedrez.{$key}", $default);
    }

    /* ------------------------------------------------------------------
     * Desafíos
     * ---------------------------------------------------------------- */

    public function challenge(int $from, int $to, int $amount): array
    {
        if (!$this->app->config->bool('games.ajedrez.enabled', true)) {
            throw new UserError('El ajedrez está deshabilitado por ahora.');
        }
        if ($from === $to) {
            throw new UserError('No podés jugar contra vos mismo.');
        }
        $min = (int) $this->cfg('min_bet', 50);
        $max = (int) $this->cfg('max_bet', 20000);
        if ($amount < $min || $amount > $max) {
            throw new UserError('La apuesta tiene que estar entre ' . Text::coins($min) . ' y ' . Text::coins($max) . ' URU Coins.');
        }
        foreach ([$from, $to] as $uid) {
            if ($this->matchOf($uid) !== null) {
                throw new UserError($uid === $from ? 'Ya estás jugando una partida.' : "{$this->app->nick($uid)} ya está jugando una partida.");
            }
        }
        if ($this->app->wallet->balance($from) < $amount) {
            throw new UserError('No te alcanza el saldo para esa apuesta.');
        }
        // Un desafío nuevo reemplaza a los anteriores del mismo par.
        foreach ($this->challenges as $id => $c) {
            if (($c['a'] === $from && $c['b'] === $to) || ($c['a'] === $to && $c['b'] === $from)) {
                unset($this->challenges[$id]);
            }
        }
        $id = $this->nextId++;
        $c = ['id' => $id, 'a' => $from, 'b' => $to, 'amount' => $amount,
            'expires' => Clock::now() + (int) $this->cfg('accept_seconds', 60)];
        $this->challenges[$id] = $c;
        return $c;
    }

    /** Menú de HUD que le aparece al desafiado. */
    public function offerMenu(Session $target, array $c): void
    {
        $this->app->menus->open($target, function () use ($c): Menu {
            $mins = intdiv((int) $this->cfg('time_seconds', 300), 60);
            $m = new Menu('Ajedrez', [
                "{$this->app->nick($c['a'])} te desafía por " . Text::coins($c['amount']) . " ({$mins} min)",
                'El que gana se lleva ' . Text::coins($this->prize($c['amount'])),
            ]);
            $m->add('\yAceptar', function (Session $s) {
                $this->app->commands->dispatch($s, 'ajedrez', 'aceptar');
                return null;
            });
            $m->add('Rechazar', function (Session $s) {
                $this->app->commands->dispatch($s, 'ajedrez', 'rechazar');
                return null;
            });
            return $m;
        });
    }

    /** @return array{id:int, a:int, b:int, amount:int, expires:int}|null */
    public function pendingFor(int $uid): ?array
    {
        foreach ($this->challenges as $c) {
            if ($c['b'] === $uid && $c['expires'] > Clock::now()) {
                return $c;
            }
        }
        return null;
    }

    public function decline(int $uid): array
    {
        $c = $this->pendingFor($uid);
        if ($c === null) {
            throw new UserError('No tenés ningún desafío de ajedrez pendiente.');
        }
        unset($this->challenges[$c['id']]);
        return $c;
    }

    /** Acepta el desafío: retiene las apuestas, manda a los dos a espectador y abre el tablero. */
    public function accept(int $uid): ChessMatch
    {
        $c = $this->pendingFor($uid);
        if ($c === null) {
            throw new UserError('No tenés ningún desafío de ajedrez pendiente.');
        }
        unset($this->challenges[$c['id']]);
        $a = $this->app->sessions->byUser($c['a']);
        $b = $this->app->sessions->byUser($c['b']);
        if ($a === null || $b === null) {
            throw new UserError('El otro jugador ya no está conectado.');
        }
        foreach ([$c['a'], $c['b']] as $u) {
            if ($this->matchOf($u) !== null) {
                throw new UserError('Alguno de los dos ya está jugando otra partida.');
            }
            if ($this->app->wallet->balance($u) < $c['amount']) {
                throw new UserError($u === $uid ? 'No te alcanza el saldo para la apuesta.' : "A {$this->app->nick($u)} ya no le alcanza el saldo.");
            }
        }
        $id = $this->nextId++;
        // Blancas para el que desafió.
        [$white, $black] = [$c['a'], $c['b']];
        $names = [$white => $this->app->nick($white), $black => $this->app->nick($black)];
        $rounds = [
            $white => $this->app->casino->openRound($white, 'ajedrez', $c['amount'], ['match' => $id, 'rival' => $names[$black], 'color' => 'w']),
            $black => $this->app->casino->openRound($black, 'ajedrez', $c['amount'], ['match' => $id, 'rival' => $names[$white], 'color' => 'b']),
        ];
        $match = new ChessMatch($id, $white, $black, $c['amount'], $rounds, $names, (int) $this->cfg('time_seconds', 300), Clock::micro());
        $this->matches[$id] = $match;
        Log::info('Ajedrez: partida', ['id' => $id, 'blancas' => $names[$white], 'negras' => $names[$black], 'apuesta' => $c['amount']]);

        $match->addChat(0, '', "Partida por " . Text::coins($this->prize($match->bet)) . '. Blancas: ' . $names[$white] . '. ¡Suerte!', true);
        foreach ([$a, $b] as $s) {
            $this->app->out->event('chess.spec', ['slot' => $s->slot, 'on' => true]);
        }
        $this->app->out->event('chess.pair', ['a' => $a->slot, 'b' => $b->slot, 'on' => true]);
        $this->app->games->open($a, 'ajedrez');
        $this->app->games->open($b, 'ajedrez');
        return $match;
    }

    /** Lo que cobra el ganador: el pozo menos la comisión de la casa. */
    public function prize(int $bet): int
    {
        $pot = $bet * 2;
        return $pot - (int) floor($pot * (float) $this->cfg('house_rate', 0.05));
    }

    public function matchOf(int $uid): ?ChessMatch
    {
        foreach ($this->matches as $m) {
            if (!$m->over && $m->has($uid)) {
                return $m;
            }
        }
        return null;
    }

    /** Vuelve a abrir el tablero (si cerró la ventana). */
    public function reopen(Session $s): void
    {
        if ($s->userId === null || $this->matchOf($s->userId) === null) {
            throw new UserError('No estás jugando ninguna partida. Desafiá con /ajedrez <nick> <apuesta>.');
        }
        $this->app->games->open($s, 'ajedrez');
    }

    /* ------------------------------------------------------------------
     * Ventana del juego
     * ---------------------------------------------------------------- */

    public function attach(GameSession $gs): void
    {
        $m = $this->matchOf($gs->userId);
        if ($m === null) {
            $gs->send(['type' => 'nomatch']);
            return;
        }
        $m->sessions[$gs->userId] = $gs;
        $gs->state['busy'] = true;
        $gs->send(['type' => 'chatlog', 'lines' => array_map(fn ($l) => $this->chatLine($l, $gs->userId), $m->chat)]);
        $this->sendState($m, $gs->userId);
    }

    public function detach(GameSession $gs): void
    {
        foreach ($this->matches as $m) {
            if (($m->sessions[$gs->userId] ?? null) === $gs) {
                $m->sessions[$gs->userId] = null;
                $m->away[$gs->userId] = Clock::micro();
                $this->setVoice($m, $gs->userId, false);
            }
        }
    }

    public function handle(GameSession $gs, array $msg): void
    {
        $uid = $gs->userId;
        $m = $this->matchOf($uid);
        if ($m === null) {
            throw new UserError('La partida ya terminó.');
        }
        $now = Clock::micro();
        $this->checkClock($m, $now);
        if ($m->over) {
            return;
        }
        switch ((string) ($msg['type'] ?? '')) {
            case 'move':
                $this->move($m, $uid, (string) ($msg['from'] ?? ''), (string) ($msg['to'] ?? ''), (string) ($msg['promo'] ?? ''), $now);
                return;
            case 'hint':
                $this->buyHint($m, $uid);
                return;
            case 'chat':
                $this->chat($m, $uid, (string) ($msg['text'] ?? ''), $now);
                return;
            case 'voice':
                $this->setVoice($m, $uid, (bool) ($msg['on'] ?? false));
                return;
            case 'resign':
                $this->finish($m, $m->opponent($uid), 'resign');
                return;
            case 'draw':
                $this->offerDraw($m, $uid);
                return;
            case 'draw_accept':
                if ($m->drawOffer === null || $m->drawOffer === $uid) {
                    throw new UserError('No hay ninguna oferta de tablas para aceptar.');
                }
                $this->finish($m, null, 'agreement');
                return;
            case 'draw_decline':
                if ($m->drawOffer !== null && $m->drawOffer !== $uid) {
                    $m->drawOffer = null;
                    $this->system($m, "{$m->names[$uid]} rechazó las tablas.");
                    $this->broadcastState($m);
                }
                return;
            default:
                throw new UserError('Acción desconocida.');
        }
    }

    private function move(ChessMatch $m, int $uid, string $from, string $to, string $promo, float $now): void
    {
        try {
            $fromSq = Board::index($from);
            $toSq = Board::index($to);
        } catch (\InvalidArgumentException) {
            throw new UserError('Casilla inválida.');
        }
        if (!in_array($promo, ['', 'q', 'r', 'b', 'n'], true)) {
            $promo = 'q';
        }
        $r = $m->move($uid, $fromSq, $toSq, $promo, $now, (int) $this->cfg('increment_seconds', 0));
        $sound = $r['move']['captured'] !== '' ? 'capture' : 'move';
        if ($m->board->inCheck()) {
            $sound = 'check';
        }
        foreach ([$m->white, $m->black] as $u) {
            $gs = $m->sessions[$u];
            if ($gs !== null) {
                $this->app->games->sound($gs, $sound);
            }
        }
        if ($r['end'] !== null) {
            $winner = $r['end']['winner'] === null ? null : $m->userOf($r['end']['winner']);
            $this->finish($m, $winner, $r['end']['reason']);
            return;
        }
        $this->broadcastState($m);
    }

    private function buyHint(ChessMatch $m, int $uid): void
    {
        if ($m->hints[$uid]) {
            throw new UserError('Ya tenés las pistas de esta partida.');
        }
        $price = (int) $this->cfg('hint_price', 250);
        if ($this->app->wallet->balance($uid) < $price) {
            throw new UserError('No te alcanza para las pistas (' . Text::coins($price) . ' URU Coins).');
        }
        $this->app->wallet->debit($uid, $price, 'game_bet', 'pistas de ajedrez');
        $m->hints[$uid] = true;
        $this->system($m, "{$m->names[$uid]} compró las pistas: ve a dónde puede mover cada pieza.");
        $this->broadcastState($m);
    }

    private function chat(ChessMatch $m, int $uid, string $text, float $now): void
    {
        $max = (int) $this->cfg('chat.max_chars', 120);
        $text = trim(mb_substr(Text::chatSafe(Text::sanitize($text)), 0, $max));
        if ($text === '') {
            return;
        }
        if ($now - ($m->lastChat[$uid] ?? 0.0) < (float) $this->cfg('chat.min_interval', 1.0)) {
            throw new UserError('Más despacio con el chat.');
        }
        $m->lastChat[$uid] = $now;
        $line = $m->addChat($uid, $m->names[$uid], $text);
        Log::info('Ajedrez chat', ['match' => $m->id, 'nick' => $m->names[$uid], 'text' => $text]);
        foreach ([$m->white, $m->black] as $u) {
            $m->sessions[$u]?->send(['type' => 'chat'] + $this->chatLine($line, $u));
        }
    }

    private function system(ChessMatch $m, string $text): void
    {
        $line = $m->addChat(0, '', $text, true);
        foreach ([$m->white, $m->black] as $u) {
            $m->sessions[$u]?->send(['type' => 'chat'] + $this->chatLine($line, $u));
        }
    }

    private function chatLine(array $line, int $viewer): array
    {
        return ['from' => $line['from'], 'text' => $line['text'], 'own' => $line['uid'] === $viewer, 'system' => $line['system']];
    }

    private function offerDraw(ChessMatch $m, int $uid): void
    {
        if ($m->drawOffer === $uid) {
            throw new UserError('Ya ofreciste tablas. Esperá la respuesta.');
        }
        if ($m->drawOffer !== null) {
            // El otro ya las había ofrecido: ofrecer también = aceptar.
            $this->finish($m, null, 'agreement');
            return;
        }
        $max = (int) $this->cfg('draw_offers_max', 3);
        if ($m->drawOffers[$uid] >= $max) {
            throw new UserError("Ya ofreciste tablas {$max} veces en esta partida.");
        }
        $m->drawOffers[$uid]++;
        $m->drawOffer = $uid;
        $this->system($m, "{$m->names[$uid]} ofrece tablas.");
        $this->broadcastState($m);
    }

    /**
     * Micrófono: el plugin le ejecuta +voicerecord / -voicerecord al jugador. Solo el propio
     * jugador lo prende desde su ventana; se apaga solo a los voice_seconds, al cerrar la ventana
     * o al terminar la partida.
     */
    private function setVoice(ChessMatch $m, int $uid, bool $on): void
    {
        $was = $m->voice[$uid] > 0.0;
        $m->voice[$uid] = $on ? Clock::micro() + (float) $this->cfg('voice_seconds', 60) : 0.0;
        $s = $this->app->sessions->byUser($uid);
        if ($s !== null && ($on || $was)) {
            $this->app->out->event('chess.voice', ['slot' => $s->slot, 'on' => $on]);
        }
        if ($m->sessions[$uid] !== null && !$m->over) {
            $this->sendState($m, $uid);
        }
    }

    /* ------------------------------------------------------------------
     * Fin de la partida y pago
     * ---------------------------------------------------------------- */

    private function checkClock(ChessMatch $m, float $now): void
    {
        $flag = $m->flagged($now);
        if ($flag === null) {
            return;
        }
        $m->clock[$flag] = 0.0;
        $other = $flag === 'w' ? 'b' : 'w';
        if ($m->board->onlyKing($other)) {
            $this->finish($m, null, 'timeout_material');
            return;
        }
        $this->finish($m, $m->userOf($other), 'time');
    }

    /** $winner = usuario ganador o null para tablas. */
    public function finish(ChessMatch $m, ?int $winner, string $reason): void
    {
        if ($m->over) {
            return;
        }
        $now = Clock::micro();
        foreach (['w', 'b'] as $c) {
            $m->clock[$c] = $m->remaining($c, $now);
        }
        $m->over = true;
        $why = self::REASONS[$reason] ?? $reason;
        $prize = $this->prize($m->bet);
        $moves = implode(' ', $this->numbered($m->san));

        foreach ([$m->white, $m->black] as $u) {
            $payout = $winner === null ? $m->bet : ($winner === $u ? $prize : 0);
            $rival = $m->names[$m->opponent($u)];
            $summary = $winner === null
                ? "Jugó al ajedrez contra {$rival}: tablas {$why}."
                : ($winner === $u ? "Le ganó al ajedrez a {$rival} {$why}." : "Perdió al ajedrez contra {$rival} {$why}.");
            $this->app->casino->settle($m->rounds[$u], $payout, $summary, ['match' => $m->id, 'result' => $reason, 'winner' => $winner, 'moves' => $moves]);
        }

        $text = $winner === null
            ? "Tablas {$why}."
            : "Ganó {$m->names[$winner]} {$why}.";
        $m->result = ['winner' => $winner, 'reason' => $reason, 'text' => $text];
        $this->system($m, $text);
        Log::info('Ajedrez: fin', ['id' => $m->id, 'ganador' => $winner === null ? 'tablas' : $m->names[$winner], 'motivo' => $reason, 'jugadas' => count($m->san)]);

        $this->app->out->chat(Out::ALL, $winner === null
            ? "Ajedrez: {green}{$m->names[$m->white]}{default} y {green}{$m->names[$m->black]}{default} hicieron tablas {$why}."
            : "Ajedrez: {green}{$m->names[$winner]}{default} le ganó a {$m->names[$m->opponent($winner)]} {$why} y se llevó {green}" . Text::coins($prize) . ' URU Coins{default}.');

        $slots = [];
        foreach ([$m->white, $m->black] as $u) {
            $this->setVoice($m, $u, false);
            $gs = $m->sessions[$u];
            if ($gs !== null) {
                $gs->state['busy'] = false;
                $this->app->games->sound($gs, $winner === null ? 'draw' : ($winner === $u ? 'win' : 'lose'));
                $this->sendState($m, $u);
            }
            $s = $this->app->sessions->byUser($u);
            if ($s !== null) {
                $slots[] = $s->slot;
                $this->app->out->event('chess.spec', ['slot' => $s->slot, 'on' => false]);
            }
        }
        if (count($slots) === 2) {
            $this->app->out->event('chess.pair', ['a' => $slots[0], 'b' => $slots[1], 'on' => false]);
        }
        unset($this->matches[$m->id]);
    }

    /** El jugador se fue del servidor o cerró sesión: pierde la partida. */
    public function onLeave(int $uid): void
    {
        foreach ($this->challenges as $id => $c) {
            if ($c['a'] === $uid || $c['b'] === $uid) {
                unset($this->challenges[$id]);
            }
        }
        $m = $this->matchOf($uid);
        if ($m !== null) {
            $m->voice[$uid] = 0.0;
            $this->finish($m, $m->opponent($uid), 'abandon');
        }
    }

    /** Cada segundo: desafíos vencidos, relojes, ventanas cerradas y micrófonos. */
    public function tick(): void
    {
        $now = Clock::now();
        foreach ($this->challenges as $id => $c) {
            if ($c['expires'] <= $now) {
                unset($this->challenges[$id]);
                $this->app->notify($c['a'], "{$this->app->nick($c['b'])} no contestó el desafío de ajedrez.");
            }
        }
        $micro = Clock::micro();
        $grace = (float) $this->cfg('abandon_seconds', 60);
        foreach ($this->matches as $m) {
            $this->checkClock($m, $micro);
            if ($m->over) {
                continue;
            }
            foreach ([$m->white, $m->black] as $u) {
                $gs = $m->sessions[$u];
                $open = $gs !== null && !$gs->closed && ($gs->ws !== null || $micro - $gs->lastActive < 5);
                if ($open) {
                    $m->away[$u] = $micro;
                } elseif ($micro - $m->away[$u] > $grace) {
                    $this->system($m, "{$m->names[$u]} cerró el tablero y no volvió.");
                    $this->finish($m, $m->opponent($u), 'abandon');
                    break;
                }
                if ($m->voice[$u] > 0.0 && $micro >= $m->voice[$u]) {
                    $this->setVoice($m, $u, false);
                }
            }
        }
    }

    /* ------------------------------------------------------------------
     * Estado para la página
     * ---------------------------------------------------------------- */

    private function broadcastState(ChessMatch $m): void
    {
        foreach ([$m->white, $m->black] as $u) {
            $this->sendState($m, $u);
        }
    }

    private function sendState(ChessMatch $m, int $uid): void
    {
        $gs = $m->sessions[$uid] ?? null;
        if ($gs === null) {
            return;
        }
        $now = Clock::micro();
        $you = $m->colorOf($uid);
        $myTurn = !$m->over && $m->board->turn() === $you;
        $prize = $this->prize($m->bet);
        $state = [
            'type' => 'state',
            'id' => $m->id,
            'you' => $you,
            'board' => $m->board->squares(),
            'turn' => $m->board->turn(),
            'last' => $m->last === null ? null : ['from' => Board::name($m->last['from']), 'to' => Board::name($m->last['to'])],
            'check' => $m->board->checkedKing() >= 0 ? Board::name($m->board->checkedKing()) : null,
            'moves' => $m->san,
            'clock' => [
                'w' => (int) round($m->remaining('w', $now) * 1000),
                'b' => (int) round($m->remaining('b', $now) * 1000),
                'running' => $m->over ? null : $m->board->turn(),
            ],
            'names' => ['w' => $m->names[$m->white], 'b' => $m->names[$m->black]],
            'bet' => $m->bet,
            'prize' => $prize,
            'balance' => $this->app->wallet->balance($uid),
            'hint' => $m->hints[$uid],
            'hintPrice' => (int) $this->cfg('hint_price', 250),
            'legal' => $m->hints[$uid] && $myTurn ? $m->legalMap() : null,
            'draw' => $m->drawOffer === null ? null : ($m->drawOffer === $uid ? 'out' : 'in'),
            'drawLeft' => max(0, (int) $this->cfg('draw_offers_max', 3) - $m->drawOffers[$uid]),
            'voice' => $m->voice[$uid] > 0.0,
            'voiceSeconds' => (int) $this->cfg('voice_seconds', 60),
            'over' => $m->over,
            'result' => null,
        ];
        if ($m->result !== null) {
            $w = $m->result['winner'];
            $state['result'] = [
                'winner' => $w === null ? null : $m->colorOf($w),
                'reason' => $m->result['reason'],
                'text' => $m->result['text'],
                'net' => $w === null ? 0 : ($w === $uid ? $prize - $m->bet : -$m->bet),
            ];
        }
        $gs->send($state);
    }

    /** @param list<string> $san @return list<string> "1.e4 e5", "2.Cf3 Cc6"... */
    private function numbered(array $san): array
    {
        $out = [];
        foreach (array_chunk($san, 2) as $i => $pair) {
            $out[] = ($i + 1) . '.' . implode(' ', $pair);
        }
        return $out;
    }
}
