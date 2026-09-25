<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * /ayuda, /apodo, /perfil, /top
 */
final class GeneralCommands
{
    public static function register(App $app): void
    {
        $r = $app->commands;

        $r->register('ayuda', function (CommandContext $c) use ($app): void {
            $c->console('===== Claudia: comandos =====');
            $c->console('Cuenta: /registrar  /login  /cambiarclave');
            foreach ($app->commands->help() as $section => $cmds) {
                $c->console("--- {$section} ---");
                foreach ($cmds as $cmd) {
                    $c->console("/{$cmd['name']} {$cmd['help']}");
                }
            }
            $c->console('Para hablar con Claudia, nombrala en el chat (claudia, clau...).');
            $c->reply('Te dejé la lista de comandos en la consola (tecla {green}~{default}). Los más usados: /perfil /saldo /trabajos /bancos /ruleta /blackjack');
        }, '- esta ayuda', 'General', false, ['comandos', 'help']);

        $r->register('apodo', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $raw = trim($c->args);
            if ($raw === '') {
                $u = $app->users->find($uid);
                $c->reply(($u['apodo'] ?? '') !== '' ? "Tu apodo es {green}{$u['apodo']}{default}. Cambialo con /apodo <nuevo> o borralo con /apodo borrar." : 'No tenés apodo. Ponete uno con /apodo <apodo>.');
                return;
            }
            if (Text::fold($raw) === 'borrar') {
                $app->users->setApodo($uid, null);
                $c->reply('Listo, te borré el apodo.');
                return;
            }
            $apodo = Text::chatSafe(Text::sanitize($raw));
            $max = $app->config->int('service.nickname.max_length', 20);
            if ($apodo === '' || mb_strlen($apodo) > $max) {
                throw new UserError("El apodo tiene que tener entre 1 y {$max} caracteres.");
            }
            foreach ($app->config->array('service.nickname.blocked_words') as $bad) {
                if ($bad !== '' && str_contains(Text::fold($apodo), Text::fold((string) $bad))) {
                    throw new UserError('Ese apodo no está permitido.');
                }
            }
            $app->users->setApodo($uid, $apodo);
            $c->reply("Desde ahora te digo {green}{$apodo}{default}.");
        }, '<apodo> - cómo querés que te llame Claudia (borrar para quitarlo)', 'General');

        $r->register('perfil', function (CommandContext $c) use ($app): void {
            $self = $c->session->userId;
            $query = trim($c->args);
            if ($query === '') {
                $user = $app->users->find($c->userId());
            } else {
                $user = $app->findUser($query);
            }
            $uid = (int) $user['id'];
            $full = $c->staff || $uid === $self;
            self::showProfile($app, $c, $user, $full);
            if ($c->staff) {
                self::showStaffDetail($app, $c, $user);
            }
        }, '[nick] - tu perfil o el de otro jugador', 'General', false);

        $r->register('top', function (CommandContext $c) use ($app): void {
            $type = Text::fold($c->arg(0, 'economia') ?? 'economia');
            $type = str_starts_with($type, 'j') || str_starts_with($type, 'k') ? 'juego' : 'economia';
            $rows = $app->stats->top($type, 15);
            $title = $type === 'economia' ? 'Top economía (URU Coins)' : 'Top juego (puntaje)';
            $c->console("===== {$title} =====");
            foreach ($rows as $i => $row) {
                $c->console(sprintf('%2d. %s - %s', $i + 1, $row['nick'], $type === 'economia' ? Text::coins((int) $row['value']) : $row['value']));
            }
            $top = array_slice($rows, 0, 5);
            $line = implode(' | ', array_map(fn ($r, $i) => ($i + 1) . '. ' . $r['nick'] . ' ' . ($type === 'economia' ? Text::coins((int) $r['value']) : $r['value']), $top, array_keys($top)));
            $c->reply("{green}{$title}:{default} " . ($line === '' ? 'nadie todavía' : $line));
            if ($c->session->userId !== null) {
                $c->reply('Tu posición: #' . $app->stats->position($type, $c->session->userId) . '. Top 15 en la consola. (/top economia | /top juego)');
            }
        }, '[economia|juego] - rankings', 'General', false, ['ranking']);
    }

    /** @param array<string,mixed> $user */
    private static function showProfile(App $app, CommandContext $c, array $user, bool $full): void
    {
        $uid = (int) $user['id'];
        $s = $app->stats->get($uid);
        $name = (string) $user['nick'] . (($user['apodo'] ?? '') !== '' ? " ({$user['apodo']})" : '');
        $posEco = $app->stats->position('economia', $uid);
        $posGame = $app->stats->position('juego', $uid);
        $c->reply("Perfil de {green}{$name}{default} - #{$posEco} economía, #{$posGame} juego");
        $c->reply('URU Coins: {green}' . Text::coins((int) $user['coins']) . '{default} | Ganado: ' . Text::coins((int) $user['total_won']) . ' | Perdido: ' . Text::coins((int) $user['total_lost']));
        $c->reply("Kills {$s['kills']} | Muertes {$s['deaths']} | HS {$s['headshots']} | K/D {$s['kd']} | Precisión {$s['accuracy']}% | Jugado " . Text::duration($s['playtime']));
        $job = $app->jobs->info($uid);
        $jobText = $job === null ? 'desempleado' : "{$job['jobName']} en {$job['employerName']} ({$job['levelName']})";
        $line = "Trabajo: {$jobText}";
        if ($full) {
            $debt = $app->loans->totalDebt($uid);
            $line .= ' | Deuda: ' . Text::coins($debt) . ($app->loans->inClearing($uid) ? ' {team}[CLEARING]{default}' : '');
        }
        $c->reply($line . ' | Grupo: - | Familia: -');
    }

    /** @param array<string,mixed> $user */
    private static function showStaffDetail(App $app, CommandContext $c, array $user): void
    {
        $uid = (int) $user['id'];
        $fmt = fn (?int $ts) => $ts ? date('Y-m-d H:i', $ts) : '-';
        $c->console("===== [STAFF] {$user['nick']} (id {$uid}) =====");
        $c->console('Registrado: ' . $fmt((int) $user['created_at']) . ' | Última vez: ' . $fmt((int) $user['last_seen']) . " | IP: {$user['last_ip']} | AuthID: {$user['last_authid']}");
        $birthday = ($user['birthday'] ?? null) === null ? '-' : implode('/', array_reverse(explode('-', (string) $user['birthday'])));
        $c->console("Cumpleaños: {$birthday} | Clearing desde: " . $fmt($app->loans->clearingSince($uid)));
        foreach ($app->loans->activeLoans($uid) as $l) {
            $c->console(sprintf(
                'Préstamo #%d %s: capital %s, interés %s, recargos %s, adeuda %s, vence %s',
                $l['id'],
                $app->loans->bankName((string) $l['bank']),
                Text::coins((int) $l['principal']),
                Text::coins((int) $l['interest']),
                Text::coins((int) $l['surcharges']),
                Text::coins((int) $l['outstanding']),
                $fmt((int) $l['due_at'])
            ));
        }
        $c->console('--- Últimos movimientos ---');
        foreach ($app->wallet->history($uid, 10) as $t) {
            $c->console($fmt((int) $t['created_at']) . " {$t['kind']} " . ($t['amount'] > 0 ? '+' : '') . Text::coins((int) $t['amount']) . ' => ' . Text::coins((int) $t['balance_after']) . ' ' . ($t['ref'] ?? ''));
        }
        $c->console('--- Memoria de Claudia (' . $app->memory->wordCount($uid) . ' palabras) ---');
        foreach ($app->memory->all($uid) as $m) {
            $c->console("[{$m['kind']}] {$m['text']}");
        }
        $c->reply('[Staff] Detalle completo (préstamos, movimientos, memoria) en tu consola.');
    }
}
