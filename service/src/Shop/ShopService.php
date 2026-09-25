<?php

declare(strict_types=1);

namespace Claudia\Shop;

use Claudia\Config;
use Claudia\Db;
use Claudia\Economy\Wallet;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Tienda (config/shop.json) e inventario. Lo que se gasta acá sale de circulación.
 *   type "item":  se guarda en el inventario (ej. anillo para casarse)
 *   type "grupo": no se guarda; el comando abre el formulario para crear un grupo
 */
final class ShopService
{
    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly Wallet $wallet,
    ) {
    }

    /** @return array<string, array{name:string, description:string, type:string, price:int, max:int}> */
    public function items(): array
    {
        $out = [];
        foreach ($this->config->array('shop.items') as $key => $item) {
            $type = (string) ($item['type'] ?? 'item');
            $out[(string) $key] = [
                'name' => (string) ($item['name'] ?? $key),
                'description' => (string) ($item['description'] ?? ''),
                'type' => $type,
                'price' => $type === 'grupo' ? $this->config->int('groups.price', 10000) : (int) ($item['price'] ?? 0),
                'max' => (int) ($item['max'] ?? 0),
            ];
        }
        return $out;
    }

    /** @return array{name:string, description:string, type:string, price:int, max:int} */
    public function item(string $key): array
    {
        $key = Text::fold(trim($key));
        $items = $this->items();
        if (!isset($items[$key])) {
            throw new UserError("No vendo \"{$key}\". Mirá /tienda.");
        }
        return $items[$key] + ['key' => $key];
    }

    /** Compra un objeto de inventario. @return int cantidad que queda en el inventario */
    public function buy(int $uid, string $key): int
    {
        $item = $this->item($key);
        $key = Text::fold(trim($key));
        if ($item['type'] !== 'item') {
            throw new \LogicException("'{$key}' no es un objeto de inventario");
        }
        return $this->db->transaction(function () use ($uid, $key, $item): int {
            $have = $this->count($uid, $key);
            if ($item['max'] > 0 && $have >= $item['max']) {
                throw new UserError("Ya tenés {$have}, no podés tener más.");
            }
            $this->wallet->debit($uid, $item['price'], 'shop', $item['name']);
            $this->db->exec(
                'INSERT INTO inventory(user_id, item, qty) VALUES(?, ?, 1) ON CONFLICT(user_id, item) DO UPDATE SET qty = qty + 1',
                [$uid, $key]
            );
            return $have + 1;
        });
    }

    public function count(int $uid, string $item): int
    {
        return (int) ($this->db->value('SELECT qty FROM inventory WHERE user_id = ? AND item = ?', [$uid, $item]) ?? 0);
    }

    /** Gasta una unidad. Devuelve false si no tenía. */
    public function take(int $uid, string $item): bool
    {
        return $this->db->exec('UPDATE inventory SET qty = qty - 1 WHERE user_id = ? AND item = ? AND qty > 0', [$uid, $item]) > 0;
    }

    /** @return array<string, int> */
    public function inventory(int $uid): array
    {
        $out = [];
        foreach ($this->db->all('SELECT item, qty FROM inventory WHERE user_id = ? AND qty > 0 ORDER BY item', [$uid]) as $r) {
            $out[(string) $r['item']] = (int) $r['qty'];
        }
        return $out;
    }
}
