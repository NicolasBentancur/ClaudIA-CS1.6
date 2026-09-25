<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\Groups\GroupCreateFlow;
use Claudia\Net\Out;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Grupos:
 * /creargrupo /grupos /grupo /miembros /unirse /salirg /solicitudes /aceptarg /rechazarg
 * /expulsarg /traspasarg /editarg /disolver /donar /fondo /topgrupos /g
 */
final class GroupCommands
{
    public static function register(App $app): void
    {
        $r = $app->commands;
        $sec = 'Grupos';
        $g = $app->groups;

        $r->register('creargrupo', function (CommandContext $c) use ($app): void {
            self::startCreate($app, $c);
        }, '- fundar un grupo (' . Text::coins($g->price()) . ' URU Coins, se completa por chat)', $sec, true, ['fundargrupo']);

        $r->register('grupos', function (CommandContext $c) use ($app, $g): void {
            $list = $g->listPublic();
            if ($list === []) {
                $c->reply('No hay grupos públicos. Fundá el primero con /creargrupo.');
                return;
            }
            $c->console('===== Grupos públicos =====');
            foreach ($list as $row) {
                $c->console(sprintf('[%s] %s - %d/%d miembros - %d kills - dueño %s - %s', $row['tag'], $row['name'], $row['members'], $g->maxMembers(), $row['kills'], $app->nick((int) $row['owner_id']), $row['description']));
            }
            $short = array_map(fn ($row) => "[{$row['tag']}] {$row['name']} ({$row['members']}/{$g->maxMembers()})", array_slice($list, 0, 6));
            $c->reply('{green}Grupos:{default} ' . implode(' | ', $short) . '. Detalle en la consola. Entrá con /unirse <nombre>.');
        }, '- grupos públicos', $sec, false, ['clanes']);

        $r->register('grupo', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->session->userId;
            if ($c->args !== '') {
                $group = $g->find($c->args);
            } else {
                $group = $g->mine($c->userId());
            }
            $gid = (int) $group['id'];
            $member = $uid !== null && $g->ofUser($uid) !== null && (int) $g->ofUser($uid)['id'] === $gid;
            $c->reply("{green}[{$group['tag']}] {$group['name']}{default} - {$group['description']}");
            $line = 'Dueño: ' . $app->nick((int) $group['owner_id']) . ' | Miembros: ' . $g->memberCount($gid) . '/' . $g->maxMembers()
                . ' | Kills: ' . $group['kills'] . ' (#' . $g->position($gid) . ') | ' . ((int) $group['private'] === 1 ? 'Privado' : 'Público');
            if ($member || $c->staff) {
                $line .= ' | Fondo: ' . Text::coins((int) $group['pool']);
            }
            $c->reply($line);
            if (!$member && $uid !== null && $g->ofUser($uid) === null) {
                $c->reply('Para entrar: /unirse ' . $group['name']);
            }
        }, '[nombre] - información de tu grupo o de otro', $sec, false, ['clan']);

        $r->register('miembros', function (CommandContext $c) use ($app, $g): void {
            $group = $c->args !== '' ? $g->find($c->args) : $g->mine($c->userId());
            $members = $g->members((int) $group['id']);
            $c->console("===== Miembros de [{$group['tag']}] {$group['name']} =====");
            foreach ($members as $m) {
                $owner = (int) $m['user_id'] === (int) $group['owner_id'] ? ' (dueño)' : '';
                $c->console(sprintf('%s%s - %d kills - cuotas %s - donó %s - desde %s', $m['nick'], $owner, $m['kills'], Text::coins((int) $m['fees_paid']), Text::coins((int) $m['donated']), date('d/m/Y', (int) $m['joined_at'])));
            }
            $online = array_filter($members, fn ($m) => $app->sessions->byUser((int) $m['user_id']) !== null);
            $c->reply(count($members) . ' miembros (' . count($online) . ' conectados): ' . implode(', ', array_map(fn ($m) => $m['nick'], array_slice($members, 0, 8))) . (count($members) > 8 ? '...' : '') . '. Detalle en la consola.');
        }, '[nombre] - miembros del grupo (en consola)', $sec, false);

        $r->register('unirse', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->userId();
            if ($c->args === '') {
                throw new UserError('Uso: /unirse <nombre del grupo>. Mirá /grupos.');
            }
            [$result, $group] = $g->join($uid, $c->args);
            if ($result === 'requested') {
                $c->reply("{$group['name']} es privado: le mandé tu solicitud al dueño.");
                $app->notify((int) $group['owner_id'], "{green}{$app->nick($uid)}{default} quiere entrar a {$group['name']}. /aceptarg {$app->nick($uid)} o /rechazarg {$app->nick($uid)}.");
                return;
            }
            $c->reply("Entraste a {green}[{$group['tag']}] {$group['name']}{default}. Cada " . $app->config->int('groups.fee.rounds', 100) . ' rondas se paga una cuota de '
                . Text::coins($app->config->int('groups.fee.amount', 25)) . ' al fondo común.');
            self::toGroup($app, (int) $group['id'], "{$app->nick($uid)} entró al grupo.", $uid);
        }, '<nombre> - entrar a un grupo (si es privado, se pide permiso)', $sec);

        $r->register('salirg', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->userId();
            $group = $g->leave($uid);
            $c->reply("Te fuiste de {$group['name']}.");
            self::toGroup($app, (int) $group['id'], "{$app->nick($uid)} se fue del grupo.");
        }, '- salir de tu grupo', $sec, true, ['salirgrupo']);

        $r->register('solicitudes', function (CommandContext $c) use ($app, $g): void {
            $group = $g->owned($c->userId());
            $req = $g->requests((int) $group['id']);
            if ($req === []) {
                $c->reply('No hay solicitudes pendientes.');
                return;
            }
            $c->reply('Quieren entrar: {green}' . implode(', ', array_column($req, 'nick')) . '{default}. /aceptarg <nick> o /rechazarg <nick>.');
        }, '- solicitudes para entrar a tu grupo (dueño)', $sec);

        $r->register('aceptarg', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->userId();
            $target = self::requester($app, $c);
            $group = $g->acceptRequest($uid, (int) $target['id']);
            $c->reply("Aceptaste a {$target['nick']} en el grupo.");
            $app->notify((int) $target['id'], "Te aceptaron en {green}[{$group['tag']}] {$group['name']}{default}.");
            self::toGroup($app, (int) $group['id'], "{$target['nick']} entró al grupo.", (int) $target['id']);
        }, '<nick> - aceptar una solicitud (dueño)', $sec);

        $r->register('rechazarg', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->userId();
            $target = self::requester($app, $c);
            $group = $g->rejectRequest($uid, (int) $target['id']);
            $c->reply("Rechazaste a {$target['nick']}.");
            $app->notify((int) $target['id'], "Tu solicitud para entrar a {$group['name']} fue rechazada.");
        }, '<nick> - rechazar una solicitud (dueño)', $sec);

        $r->register('expulsarg', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->userId();
            if ($c->args === '') {
                throw new UserError('Uso: /expulsarg <nick>');
            }
            $target = $app->findUser($c->args);
            $group = $g->expel($uid, (int) $target['id']);
            $app->notify((int) $target['id'], "Te echaron de {$group['name']}.");
            self::toGroup($app, (int) $group['id'], "{$target['nick']} fue expulsado/a del grupo.");
        }, '<nick> - echar a un miembro (dueño)', $sec, true, ['echarg']);

        $r->register('traspasarg', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->userId();
            if ($c->args === '') {
                throw new UserError('Uso: /traspasarg <nick>');
            }
            $target = $app->findUser($c->args);
            $group = $g->transferOwner($uid, (int) $target['id']);
            self::toGroup($app, (int) $group['id'], "{$app->nick($uid)} le pasó el grupo a {green}{$target['nick']}{default}. Ahora es el/la dueño/a.");
        }, '<nick> - pasarle el grupo a otro miembro (dueño)', $sec);

        $r->register('editarg', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->userId();
            $what = Text::fold((string) $c->arg(0, ''));
            $value = $c->rest(1);
            if (str_starts_with($what, 'desc')) {
                $d = $g->setDescription($uid, $value);
                $c->reply("Descripción actualizada: {$d}");
            } elseif (str_starts_with($what, 'priv')) {
                $v = Text::fold($value);
                if (!str_starts_with($v, 'pub') && !str_starts_with($v, 'priv')) {
                    throw new UserError('Uso: /editarg privacidad publico|privado');
                }
                $g->setPrivate($uid, str_starts_with($v, 'priv'));
                $c->reply('El grupo ahora es ' . (str_starts_with($v, 'priv') ? 'privado: vos aceptás a los que quieran entrar.' : 'público: entra cualquiera.'));
            } else {
                throw new UserError('Uso: /editarg descripcion <texto> | /editarg privacidad publico|privado');
            }
        }, 'descripcion <texto> | privacidad publico|privado - editar el grupo (dueño)', $sec);

        $r->register('disolver', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->userId();
            $group = $g->owned($uid);
            $key = "disolver:{$uid}";
            if (!in_array(Text::fold($c->args), ['si', 'confirmar'], true) || $app->cooldowns->left($key) === 0) {
                $app->cooldowns->take($key, $app->config->int('groups.dissolve_confirm_seconds', 30));
                $members = $g->memberCount((int) $group['id']);
                $c->reply("¿Seguro que querés disolver {green}{$group['name']}{default}? El fondo (" . Text::coins((int) $group['pool']) . ") se reparte entre los {$members} miembros. Confirmá con {green}/disolver si{default}.");
                return;
            }
            $res = $g->dissolve($uid);
            foreach ($res['members'] as $m) {
                if ($m !== $uid) {
                    $app->notify($m, "{$app->nick($uid)} disolvió {$group['name']}." . ($res['share'] > 0 ? ' Te tocaron ' . Text::coins($res['share']) . ' del fondo.' : ''));
                }
            }
            $c->reply("Disolviste {$group['name']}." . ($res['share'] > 0 ? ' A cada uno le tocaron ' . Text::coins($res['share']) . ' del fondo.' : ''));
        }, '- disolver tu grupo (dueño)', $sec);

        $r->register('donar', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->userId();
            $amount = Text::parseAmount((string) $c->arg(0, ''));
            if ($amount === null || $amount <= 0) {
                throw new UserError('Uso: /donar <monto>');
            }
            $group = $g->mine($uid);
            $pool = $g->donate($uid, $amount);
            $c->reply('Donaste {green}' . Text::coins($amount) . '{default} al fondo. Ahora hay ' . Text::coins($pool) . '.');
            self::toGroup($app, (int) $group['id'], "{$app->nick($uid)} donó " . Text::coins($amount) . ' al fondo del grupo.', $uid);
        }, '<monto> - donar coins al fondo de tu grupo', $sec);

        $r->register('fondo', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->userId();
            if (Text::fold((string) $c->arg(0, '')) === 'dar') {
                if (count($c->argv) < 3) {
                    throw new UserError('Uso: /fondo dar <nick> <monto>');
                }
                $argv = $c->argv;
                $amount = Text::parseAmount((string) array_pop($argv));
                if ($amount === null) {
                    throw new UserError('Monto inválido.');
                }
                $target = $app->findUser(implode(' ', array_slice($argv, 1)));
                $group = $g->mine($uid);
                $pool = $g->payFromPool($uid, (int) $target['id'], $amount);
                $c->reply("Le diste {green}" . Text::coins($amount) . "{default} del fondo a {$target['nick']}. Quedan " . Text::coins($pool) . '.');
                self::toGroup($app, (int) $group['id'], "El dueño le dio " . Text::coins($amount) . " del fondo a {$target['nick']}.", $uid);
                return;
            }
            $group = $g->mine($uid);
            $c->console("===== Fondo de [{$group['tag']}] {$group['name']}: " . Text::coins((int) $group['pool']) . ' =====');
            foreach ($g->ledger((int) $group['id'], 20) as $l) {
                $c->console(date('d/m H:i', (int) $l['created_at']) . " {$l['kind']} " . ($l['amount'] > 0 ? '+' : '') . Text::coins((int) $l['amount'])
                    . ($l['nick'] !== null ? " ({$l['nick']})" : '') . ' => ' . Text::coins((int) $l['pool_after']) . ($l['note'] !== null ? " - {$l['note']}" : ''));
            }
            $isOwner = (int) $group['owner_id'] === $uid;
            $c->reply('Fondo del grupo: {green}' . Text::coins((int) $group['pool']) . '{default} URU Coins. Movimientos en la consola.'
                . ($isOwner ? ' Pagale a un miembro con /fondo dar <nick> <monto>.' : ' Aportá con /donar <monto>.'));
        }, '[dar <nick> <monto>] - fondo común del grupo', $sec);

        $r->register('topgrupos', function (CommandContext $c) use ($app, $g): void {
            $rows = $g->ranking(10);
            if ($rows === []) {
                $c->reply('Todavía no hay grupos.');
                return;
            }
            $c->console('===== Top grupos (kills) =====');
            foreach ($rows as $i => $row) {
                $c->console(sprintf('%2d. [%s] %s - %d kills - %d miembros', $i + 1, $row['tag'], $row['name'], $row['kills'], $row['members']));
            }
            $top = array_slice($rows, 0, 5);
            $c->reply('{green}Top grupos:{default} ' . implode(' | ', array_map(fn ($row, $i) => ($i + 1) . ". [{$row['tag']}] {$row['kills']}", $top, array_keys($top))));
        }, '- ranking de grupos por kills', $sec, false, ['rankinggrupos']);

        $r->register('g', function (CommandContext $c) use ($app, $g): void {
            $uid = $c->userId();
            $text = Text::chatSafe(Text::sanitize($c->args));
            if ($text === '') {
                throw new UserError('Uso: /g <mensaje> (chat solo para tu grupo)');
            }
            $group = $g->mine($uid);
            $line = "{green}(Grupo {$group['tag']}){default} {team}{$c->session->nick}{default}: {$text}";
            foreach ($g->memberIds((int) $group['id']) as $m) {
                $s = $app->sessions->byUser($m);
                if ($s !== null) {
                    $app->out->rawChat($s->slot, $line);
                }
            }
        }, '<mensaje> - chat privado de tu grupo', $sec, true, ['gchat']);
    }

    public static function startCreate(App $app, CommandContext $c): void
    {
        $uid = $c->userId();
        $current = $app->groups->ofUser($uid);
        if ($current !== null) {
            throw new UserError("Ya estás en {$current['name']}. Para fundar otro grupo primero salí (/salirg).");
        }
        if ($app->wallet->balance($uid) < $app->groups->price()) {
            throw new UserError('Fundar un grupo cuesta ' . Text::coins($app->groups->price()) . ' URU Coins y tenés ' . Text::coins($app->wallet->balance($uid)) . '.');
        }
        $flow = new GroupCreateFlow($app->groups, $app->out, function (array $group, int $owner) use ($app): void {
            $app->out->chat(Out::ALL, "{green}{$app->nick($owner)}{default} fundó el grupo {green}[{$group['tag']}] {$group['name']}{default}. "
                . ((int) $group['private'] === 1 ? 'Es privado: /unirse para pedir entrar.' : 'Es público: /unirse ' . $group['name']));
        });
        $app->flows->start($c->session, $flow, $app->config->int('groups.flow_timeout_seconds', 120));
    }

    /** @return array<string,mixed> el usuario que pidió entrar (por nick, entre las solicitudes o registrados) */
    private static function requester(App $app, CommandContext $c): array
    {
        if ($c->args === '') {
            throw new UserError('Uso: /' . $c->name . ' <nick>. Mirá /solicitudes.');
        }
        $group = $app->groups->owned($c->userId());
        $q = Text::fold($c->args);
        $matches = array_values(array_filter($app->groups->requests((int) $group['id']), fn ($r) => str_contains(Text::fold((string) $r['nick']), $q)));
        foreach ($matches as $m) {
            if (Text::fold((string) $m['nick']) === $q) {
                return ['id' => (int) $m['user_id'], 'nick' => (string) $m['nick']];
            }
        }
        if (count($matches) === 1) {
            return ['id' => (int) $matches[0]['user_id'], 'nick' => (string) $matches[0]['nick']];
        }
        return $app->findUser($c->args);
    }

    /** Aviso a los miembros conectados del grupo (menos $except). */
    private static function toGroup(App $app, int $gid, string $text, ?int $except = null): void
    {
        foreach ($app->groups->memberIds($gid) as $m) {
            if ($m !== $except) {
                $app->notify($m, $text);
            }
        }
    }
}
