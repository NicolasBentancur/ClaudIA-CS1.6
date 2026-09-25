<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\Clock;
use Claudia\Players\Role;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * /saldo /transferir /movimientos /bancos /banco /prestamo /deuda /pagar /promos
 */
final class EconomyCommands
{
    public static function register(App $app): void
    {
        $r = $app->commands;
        $sec = 'Economía';

        $r->register('saldo', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $debt = $app->loans->totalDebt($uid);
            $c->reply('Tenés {green}' . Text::coins($app->wallet->balance($uid)) . '{default} URU Coins.'
                . ($debt > 0 ? ' Debés ' . Text::coins($debt) . ' a los bancos (/deuda).' : ''));
        }, '- tus URU Coins', $sec, true, ['coins', 'plata']);

        $r->register('transferir', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            if (count($c->argv) < 2) {
                throw new UserError('Uso: /transferir <nick> <monto>');
            }
            $argv = $c->argv;
            $amount = Text::parseAmount((string) array_pop($argv));
            if ($amount === null) {
                throw new UserError('Monto inválido.');
            }
            $min = $app->config->int('economy.transfer.min', 1);
            if ($amount < $min) {
                throw new UserError('El mínimo para transferir es ' . Text::coins($min) . '.');
            }
            $target = $app->findUser(implode(' ', $argv));
            $fee = (int) ceil($amount * $app->config->float('economy.transfer.fee_rate', 0.0));
            $from = $app->users->find($uid);
            $app->wallet->transfer($uid, (int) $target['id'], $amount, $fee, (string) $from['nick'], (string) $target['nick']);
            $c->reply('Le pasaste {green}' . Text::coins($amount) . "{default} URU Coins a {$target['nick']}" . ($fee > 0 ? ' (comisión ' . Text::coins($fee) . ')' : '') . '.');
            $to = $app->sessions->byUser((int) $target['id']);
            if ($to !== null) {
                $app->out->chat($to->slot, "{$from['nick']} te pasó {green}" . Text::coins($amount) . '{default} URU Coins.');
            }
        }, '<nick> <monto> - pasarle URU Coins a otro jugador', $sec, true, ['pagarle', 'transferencia']);

        $r->register('movimientos', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $c->console('===== Últimos movimientos =====');
            foreach ($app->wallet->history($uid, 15) as $t) {
                $c->console(date('d/m H:i', (int) $t['created_at']) . " {$t['kind']} " . ($t['amount'] > 0 ? '+' : '') . Text::coins((int) $t['amount']) . ' => ' . Text::coins((int) $t['balance_after']) . ' ' . ($t['ref'] ?? ''));
            }
            $c->reply('Te dejé tus últimos movimientos en la consola.');
        }, '- últimos movimientos de tu cuenta (en consola)', $sec);

        $r->register('bancos', function (CommandContext $c) use ($app): void {
            $uid = $c->session->userId;
            $c->console('===== Bancos =====');
            $names = [];
            foreach ($app->loans->banks() as $id => $b) {
                $names[] = $id;
                $terms = implode(', ', array_map(fn ($t) => $t['days'] . 'd al ' . round($app->loans->effectiveRate($id, $t) * 100, 1) . '%', (array) $b['terms']));
                $c->console("[{$id}] {$b['name']}: {$b['description']}");
                $c->console('   Plazos: ' . $terms . ' | Recargo por atraso: ' . round($app->loans->effectiveLateRate($id, $b) * 100, 1) . '% diario'
                    . ' | Monto: ' . Text::coins((int) $b['min_amount']) . ' a ' . Text::coins((int) $b['max_amount'])
                    . ($uid !== null ? ' | A vos te presta hasta ' . Text::coins($app->loans->maxFor($uid, $id)) : ''));
            }
            $c->reply('Bancos: {green}' . implode(', ', $names) . '{default}. Detalle en la consola, o /banco <nombre>. Pedí con /prestamo <banco> <monto> <días>.');
        }, '- lista de bancos, tasas y plazos', $sec, false);

        $r->register('banco', function (CommandContext $c) use ($app): void {
            $id = mb_strtolower((string) $c->arg(0, ''));
            if ($id === '') {
                throw new UserError('Uso: /banco <nombre>. Mirá /bancos.');
            }
            $b = $app->loans->bank($id);
            $terms = implode(', ', array_map(fn ($t) => $t['days'] . 'd ' . round($app->loans->effectiveRate($id, $t) * 100, 1) . '%', (array) $b['terms']));
            $c->reply("{green}{$b['name']}{default}: {$b['description']}");
            $max = $c->session->userId !== null ? $app->loans->maxFor($c->session->userId, $id) : 0;
            $c->reply("Plazos: {$terms} | Atraso: " . round($app->loans->effectiveLateRate($id, $b) * 100, 1) . '%/día | Te presta hasta ' . Text::coins($max) . ' | Disponible en el banco: ' . Text::coins($app->loans->liquidity($id)));
        }, '<nombre> - detalle de un banco', $sec, false);

        $r->register('prestamo', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            if (count($c->argv) < 3) {
                throw new UserError('Uso: /prestamo <banco> <monto> <días>. Ej: /prestamo brou 5000 15');
            }
            $amount = Text::parseAmount((string) $c->arg(1));
            $days = (int) $c->arg(2);
            if ($amount === null || $days <= 0) {
                throw new UserError('Uso: /prestamo <banco> <monto> <días>');
            }
            $loan = $app->loans->request($uid, (string) $c->arg(0), $amount, $days);
            $c->reply($app->loans->bankName((string) $loan['bank']) . ' te prestó {green}' . Text::coins($amount) . '{default}. Tenés que devolver '
                . Text::coins((int) $loan['outstanding']) . ' antes del ' . date('d/m H:i', (int) $loan['due_at']) . '. Pagá con /pagar.');
        }, '<banco> <monto> <días> - pedir un préstamo', $sec, true, ['préstamo', 'prestar']);

        $r->register('deuda', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $loans = $app->loans->activeLoans($uid);
            if ($loans === []) {
                $c->reply('No debés nada. Así me gusta.');
                return;
            }
            $now = Clock::now();
            foreach ($loans as $l) {
                $late = $now > (int) $l['due_at'];
                $c->reply($app->loans->bankName((string) $l['bank']) . ': debés {green}' . Text::coins((int) $l['outstanding']) . '{default} - '
                    . ($late ? '{team}vencido hace ' . Text::duration($now - (int) $l['due_at']) . '{default}' : 'vence en ' . Text::duration((int) $l['due_at'] - $now)));
            }
            if ($app->loans->inClearing($uid)) {
                $c->reply('{team}Estás en el Clearing.{default} Pagá todo para salir; mientras tanto se te confisca el '
                    . (int) round($app->config->float('economy.clearing.confiscation_rate', 0.5) * 100) . '% de lo que ganes en los juegos.');
            }
        }, '- tus préstamos y vencimientos', $sec, true, ['deudas', 'prestamos']);

        $r->register('pagar', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $bank = $c->arg(0);
            if ($bank === null) {
                throw new UserError('Uso: /pagar <banco|todo> [monto|todo]');
            }
            $bankId = Text::fold($bank) === 'todo' ? null : $bank;
            $amountRaw = $c->arg(1);
            $amount = null;
            if ($amountRaw !== null && Text::fold($amountRaw) !== 'todo') {
                $amount = Text::parseAmount($amountRaw);
                if ($amount === null) {
                    throw new UserError('Monto inválido.');
                }
            }
            $paid = $app->loans->pay($uid, $bankId, $amount);
            $left = $app->loans->totalDebt($uid);
            $c->reply('Pagaste {green}' . Text::coins($paid) . '{default}. ' . ($left > 0 ? 'Todavía debés ' . Text::coins($left) . '.' : 'No debés más nada.'));
        }, '<banco|todo> [monto|todo] - pagar deuda', $sec);

        $r->register('promos', function (CommandContext $c) use ($app): void {
            $active = $app->promos->active();
            if ($active === []) {
                $c->reply('No hay promociones activas ahora. Cada tanto aparecen, estate atento.');
                return;
            }
            foreach ($active as $a) {
                $c->reply('{green}' . ($a['promo']['name'] ?? '') . '{default}: ' . ($a['promo']['description'] ?? '') . ' (quedan ' . Text::duration($a['ends_at'] - Clock::now()) . ')');
            }
        }, '- promociones activas de los bancos y trabajos', $sec, false, ['promociones']);
    }

    /** Comandos de administración (vienen de amx_darcoins / amx_quitarcoins, no del chat). */
    public static function adminCoins(App $app, int $adminSlot, string $adminName, string $target, string $amountRaw, bool $give, int $role = Role::OWNER): string
    {
        $amount = Text::parseAmount($amountRaw);
        if ($amount === null) {
            throw new UserError('Monto inválido.');
        }
        return $app->admin->coins($role, $adminName, $app->findUser($target), $amount, $give);
    }
}
