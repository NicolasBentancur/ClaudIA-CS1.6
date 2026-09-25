<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * /tienda /comprar /inventario
 */
final class ShopCommands
{
    public static function register(App $app): void
    {
        $r = $app->commands;
        $sec = 'Tienda';

        $r->register('tienda', function (CommandContext $c) use ($app): void {
            $c->console('===== Tienda de Claudia =====');
            $short = [];
            foreach ($app->shop->items() as $key => $item) {
                $c->console("{$key}: {$item['name']} - " . Text::coins($item['price']) . " URU Coins - {$item['description']}");
                $short[] = "{$key} (" . Text::coins($item['price']) . ')';
            }
            $c->reply('{green}Tienda:{default} ' . implode(' | ', $short) . '. Comprá con /comprar <objeto>. Detalle en la consola.');
        }, '- lo que se puede comprar', $sec, false, ['shop']);

        $r->register('comprar', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $key = (string) $c->arg(0, '');
            if ($key === '') {
                throw new UserError('Uso: /comprar <objeto>. Mirá /tienda.');
            }
            $item = $app->shop->item($key);
            if ($item['type'] === 'grupo') {
                GroupCommands::startCreate($app, $c);
                return;
            }
            $have = $app->shop->buy($uid, $key);
            $c->reply("Compraste {green}{$item['name']}{default} por " . Text::coins($item['price']) . ". Tenés {$have}. Te quedan " . Text::coins($app->wallet->balance($uid)) . ' URU Coins.');
        }, '<objeto> - comprar en la tienda', $sec);

        $r->register('inventario', function (CommandContext $c) use ($app): void {
            $inv = $app->shop->inventory($c->userId());
            if ($inv === []) {
                $c->reply('No tenés nada guardado. Mirá la /tienda.');
                return;
            }
            $items = $app->shop->items();
            $c->reply('Tenés: ' . implode(', ', array_map(fn ($k, $q) => ($items[$k]['name'] ?? $k) . " x{$q}", array_keys($inv), $inv)) . '.');
        }, '- tus objetos', $sec, true, ['inv']);
    }
}
