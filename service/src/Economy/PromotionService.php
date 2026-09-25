<?php

declare(strict_types=1);

namespace Claudia\Economy;

use Claudia\Clock;
use Claudia\Config;
use Claudia\Db;
use Claudia\Log;

/**
 * Promociones generales (para todos los usuarios) definidas en promotions.json.
 * Cada cierto intervalo se sortea cada promoción inactiva con su probabilidad de aparición.
 *
 * Tipos de efecto:
 *   bank_rate       multiplica la tasa de interés de un banco ("bank": id o "*")
 *   bank_max        multiplica el monto máximo prestable
 *   bank_late_rate  multiplica el recargo diario por atraso
 *   salary          multiplica los sueldos
 *   bonus_chance    multiplica la probabilidad de bonus en el trabajo
 */
final class PromotionService
{
    /** @var callable(array $promo):void */
    private $announcer;

    public function __construct(private readonly Config $config, private readonly Db $db)
    {
        $this->announcer = static function (array $promo): void {
        };
    }

    public function onActivated(callable $announcer): void
    {
        $this->announcer = $announcer;
    }

    /** @return array<string, array> promociones configuradas por id */
    public function configured(): array
    {
        $out = [];
        foreach ($this->config->array('promotions.promotions') as $p) {
            if (is_array($p) && isset($p['id'])) {
                $out[(string) $p['id']] = $p;
            }
        }
        return $out;
    }

    /** @return list<array{promo:array, ends_at:int}> */
    public function active(): array
    {
        $now = Clock::now();
        $configured = $this->configured();
        $out = [];
        foreach ($this->db->all('SELECT id, ends_at FROM promotions_active WHERE ends_at > ?', [$now]) as $row) {
            if (isset($configured[$row['id']])) {
                $out[] = ['promo' => $configured[$row['id']], 'ends_at' => (int) $row['ends_at']];
            }
        }
        return $out;
    }

    public function multiplier(string $type, ?string $bank = null): float
    {
        $m = 1.0;
        foreach ($this->active() as $a) {
            foreach ((array) ($a['promo']['effects'] ?? []) as $effect) {
                if (($effect['type'] ?? '') !== $type) {
                    continue;
                }
                $target = (string) ($effect['bank'] ?? '*');
                if ($bank !== null && $target !== '*' && $target !== $bank) {
                    continue;
                }
                $m *= (float) ($effect['multiplier'] ?? 1.0);
            }
        }
        return $m;
    }

    /** Llamado por el scheduler. Sortea promociones si pasó el intervalo. */
    public function tick(): void
    {
        $now = Clock::now();
        $this->db->exec('DELETE FROM promotions_active WHERE ends_at <= ?', [$now]);
        $interval = (int) round($this->config->float('promotions.roll_interval_hours', 6) * 3600);
        $last = (int) ($this->db->kvGet('promotions.last_roll', '0') ?? 0);
        if ($interval <= 0 || $now - $last < $interval) {
            return;
        }
        $this->db->kvSet('promotions.last_roll', (string) $now);
        $activeIds = array_map(fn ($a) => (string) $a['promo']['id'], $this->active());
        foreach ($this->configured() as $id => $promo) {
            if (in_array($id, $activeIds, true) || !($promo['enabled'] ?? true)) {
                continue;
            }
            $p = (float) ($promo['probability'] ?? 0);
            if ($p > 0 && random_int(0, 1_000_000) / 1_000_000 < $p) {
                $this->activate($id, $promo, $now);
            }
        }
    }

    public function activate(string $id, array $promo, ?int $now = null): void
    {
        $now ??= Clock::now();
        $ends = $now + (int) round((float) ($promo['duration_hours'] ?? 24) * 3600);
        $this->db->exec(
            'INSERT INTO promotions_active(id, started_at, ends_at) VALUES(?, ?, ?) ON CONFLICT(id) DO UPDATE SET started_at = excluded.started_at, ends_at = excluded.ends_at',
            [$id, $now, $ends]
        );
        Log::info('Promoción activada', ['id' => $id]);
        ($this->announcer)($promo);
    }
}
