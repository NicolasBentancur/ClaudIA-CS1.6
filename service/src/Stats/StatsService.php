<?php

declare(strict_types=1);

namespace Claudia\Stats;

use Claudia\Config;
use Claudia\Db;

/**
 * Estadísticas propias del plugin (kills, muertes, HS, precisión, tiempo) y rankings.
 * Los contadores de actividad del trabajo (employment.act_*) se actualizan acá mismo.
 */
final class StatsService
{
    public function __construct(private readonly Db $db, private readonly Config $config)
    {
    }

    /** @param array{kills?:int, deaths?:int, headshots?:int, shots?:int, hits?:int, playtime?:int} $d */
    public function add(int $userId, array $d): void
    {
        $k = max(0, (int) ($d['kills'] ?? 0));
        $de = max(0, (int) ($d['deaths'] ?? 0));
        $hs = max(0, (int) ($d['headshots'] ?? 0));
        $sh = max(0, (int) ($d['shots'] ?? 0));
        $hi = max(0, (int) ($d['hits'] ?? 0));
        $pt = max(0, (int) ($d['playtime'] ?? 0));
        if ($k + $de + $hs + $sh + $hi + $pt === 0) {
            return;
        }
        $this->db->transaction(function () use ($userId, $k, $de, $hs, $sh, $hi, $pt): void {
            $this->db->exec(
                'UPDATE stats SET kills = kills + ?, deaths = deaths + ?, headshots = headshots + ?, shots = shots + ?, hits = hits + ?, playtime = playtime + ? WHERE user_id = ?',
                [$k, $de, $hs, $sh, $hi, $pt, $userId]
            );
            $this->db->exec(
                'UPDATE employment SET act_playtime = act_playtime + ?, act_kills = act_kills + ? WHERE user_id = ?',
                [$pt, $k, $userId]
            );
        });
    }

    public function addMessage(int $userId): void
    {
        $this->db->exec('UPDATE stats SET messages = messages + 1 WHERE user_id = ?', [$userId]);
        $this->db->exec('UPDATE employment SET act_messages = act_messages + 1 WHERE user_id = ?', [$userId]);
    }

    /** @return array{kills:int, deaths:int, headshots:int, shots:int, hits:int, playtime:int, messages:int, accuracy:float, kd:float, score:float} */
    public function get(int $userId): array
    {
        $r = $this->db->one('SELECT * FROM stats WHERE user_id = ?', [$userId]) ?? [];
        $s = [];
        foreach (['kills', 'deaths', 'headshots', 'shots', 'hits', 'playtime', 'messages'] as $f) {
            $s[$f] = (int) ($r[$f] ?? 0);
        }
        $s['accuracy'] = $s['shots'] > 0 ? round($s['hits'] * 100 / $s['shots'], 1) : 0.0;
        $s['kd'] = round($s['kills'] / max(1, $s['deaths']), 2);
        $s['score'] = $this->score($s);
        return $s;
    }

    public function playtime(int $userId): int
    {
        return (int) $this->db->value('SELECT playtime FROM stats WHERE user_id = ?', [$userId]);
    }

    /** @return list<array{id:int, nick:string, value:float}> */
    public function top(string $type, int $limit = 10): array
    {
        [$expr, $params] = $this->rankExpr($type);
        $rows = $this->db->all(
            "SELECT u.id, u.nick, {$expr} AS value FROM users u JOIN stats s ON s.user_id = u.id ORDER BY value DESC, u.id ASC LIMIT ?",
            [...$params, $limit]
        );
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'nick' => (string) $r['nick'], 'value' => (float) $r['value']], $rows);
    }

    public function position(string $type, int $userId): int
    {
        [$expr, $params] = $this->rankExpr($type);
        $mine = $this->db->value("SELECT {$expr} FROM users u JOIN stats s ON s.user_id = u.id WHERE u.id = ?", [...$params, $userId]);
        if ($mine === null) {
            return 0;
        }
        $better = (int) $this->db->value(
            "SELECT COUNT(*) FROM users u JOIN stats s ON s.user_id = u.id
             WHERE ({$expr}) > CAST(? AS REAL) OR (({$expr}) = CAST(? AS REAL) AND u.id < ?)",
            [...$params, (string) $mine, ...$params, (string) $mine, $userId]
        );
        return $better + 1;
    }

    /** @param array<string,int|float> $s */
    private function score(array $s): float
    {
        $w = $this->weights();
        return round($s['kills'] * $w['kills'] + $s['deaths'] * $w['deaths'] + $s['headshots'] * $w['headshots'], 1);
    }

    /** @return array{0:string, 1:list<float>} */
    private function rankExpr(string $type): array
    {
        if ($type === 'economia') {
            return ['u.coins', []];
        }
        $w = $this->weights();
        return [
            '(s.kills * CAST(? AS REAL) + s.deaths * CAST(? AS REAL) + s.headshots * CAST(? AS REAL))',
            [(string) $w['kills'], (string) $w['deaths'], (string) $w['headshots']],
        ];
    }

    /** @return array{kills:float, deaths:float, headshots:float} */
    private function weights(): array
    {
        $w = $this->config->array('economy.ranking.game_score', []);
        return [
            'kills' => (float) ($w['kills'] ?? 1),
            'deaths' => (float) ($w['deaths'] ?? -0.5),
            'headshots' => (float) ($w['headshots'] ?? 0.5),
        ];
    }
}
