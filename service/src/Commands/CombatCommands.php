<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\Net\Out;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * /duelo /aceptar_duelo /rechazar_duelo /racha /mvp /arma /bounty
 */
final class CombatCommands
{
    public static function register(App $app): void
    {
        $r = $app->commands;
        $sec = 'Combate';
        $cb = $app->combat;

        $r->register('duelo', function (CommandContext $c) use ($app, $cb): void {
            $uid = $c->userId();
            if (count($c->argv) < 2) {
                throw new UserError('Uso: /duelo <nick> <monto>');
            }
            $argv = $c->argv;
            $amount = Text::parseAmount((string) array_pop($argv));
            if ($amount === null) {
                throw new UserError('Monto inválido.');
            }
            $target = $app->findOnline(implode(' ', $argv));
            $cb->challenge($uid, (int) $target->userId, $amount);
            $secs = (int) $app->config->get('combat.duel.accept_seconds', 60);
            $c->reply("Retaste a {green}{$target->nick}{default} a un duelo por " . Text::coins($amount) . " URU Coins. Tiene {$secs} segundos para aceptar.");
            $app->out->chat($target->slot, "{green}{$c->session->nick}{default} te reta a un duelo por {green}" . Text::coins($amount)
                . '{default} URU Coins: el que mate al otro se lleva el doble. /aceptar_duelo o /rechazar_duelo');
        }, '<nick> <monto> - retar a alguien a un duelo por coins', $sec, true, ['retar']);

        $r->register('aceptar_duelo', function (CommandContext $c) use ($app, $cb): void {
            $d = $cb->accept($c->userId());
            $app->out->chat(Out::ALL, "¡Duelo! {green}{$app->nick($d['a'])}{default} vs {green}{$app->nick($d['b'])}{default} por " . Text::coins($d['amount'] * 2) . ' URU Coins. El que mate al otro se lo lleva.');
        }, '- aceptar el duelo que te propusieron', $sec, true, ['aceptarduelo']);

        $r->register('rechazar_duelo', function (CommandContext $c) use ($app, $cb): void {
            $d = $cb->decline($c->userId());
            $c->reply('Rechazaste el duelo.');
            $app->notify($d['a'], "{$c->session->nick} no quiso el duelo.");
        }, '- rechazar un duelo', $sec, true, ['rechazarduelo']);

        $r->register('racha', function (CommandContext $c) use ($cb): void {
            $s = $cb->streak($c->userId());
            $c->reply($s['kills'] === 0
                ? 'No tenés racha. Cada kill seguida suma, y morir la corta.'
                : "Llevás {green}{$s['kills']}{default} kills seguidas y {green}" . Text::coins($s['pot']) . '{default} URU Coins en el pozo de la racha (si te matan a cuchillo te pueden robar).');
        }, '- tu racha de kills y el pozo', $sec, true);

        $r->register('mvp', function (CommandContext $c) use ($cb): void {
            $m = $cb->mvpInfo($c->userId());
            $c->reply(($m['isMvp'] ? "Sos el MVP: {green}{$m['current']}{default} rondas seguidas." : 'No sos el MVP de la última ronda.')
                . " Tu mejor racha de MVP: {$m['best']}.");
        }, '- tu racha de MVP', $sec, true);

        $r->register('arma', function (CommandContext $c) use ($app, $cb): void {
            $w = $cb->weapon();
            $c->reply($w === null ? 'Todavía no hay arma bonus: se elige al empezar la ronda.'
                : "Arma bonus de esta ronda: {green}{$w}{default} (+" . (int) $app->config->get('combat.weapon_bonus.amount', 5) . ' URU Coins por kill).');
        }, '- arma bonus de la ronda', $sec, false, ['armabonus']);

        $r->register('bounty', function (CommandContext $c) use ($app, $cb): void {
            if ($c->args === '') {
                $b = $cb->bounty();
                $c->reply($b === null
                    ? 'No hay ninguna recompensa activa. Poné una con /bounty <nick> <monto>.'
                    : 'Recompensa activa: {green}' . Text::coins($b['total']) . "{default} URU Coins por {$app->nick($b['target'])}. Se cobra matándolo a cuchillo.");
                return;
            }
            $uid = $c->userId();
            if (count($c->argv) < 2) {
                throw new UserError('Uso: /bounty <nick> <monto>');
            }
            $argv = $c->argv;
            $amount = Text::parseAmount((string) array_pop($argv));
            if ($amount === null) {
                throw new UserError('Monto inválido.');
            }
            $target = $app->findUser(implode(' ', $argv));
            $total = $cb->placeBounty($uid, (int) $target['id'], $amount);
            $app->out->chat(Out::ALL, "{$c->session->nick} puso " . Text::coins($amount) . " por la cabeza de {green}{$target['nick']}{default}. Recompensa total: {green}"
                . Text::coins($total) . '{default}. Se cobra matándolo a cuchillo.');
        }, '[nick monto] - ver o poner una recompensa por un jugador', $sec, false, ['recompensa']);
    }
}
