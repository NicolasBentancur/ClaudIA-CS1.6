<?php

declare(strict_types=1);

namespace Claudia\Memory;

use Claudia\Clock;
use Claudia\Db;
use Claudia\Util\Text;

/**
 * Memoria de Claudia sobre cada usuario:
 *  - pensamiento: lo que Claudia piensa del jugador (salida estructurada de la IA)
 *  - trato:       cómo la trató el jugador en cada interacción
 *  - dato:        datos concretos que el jugador contó de sí mismo
 *  - resumen:     resumen generado cuando la memoria supera el umbral de palabras
 */
final class MemoryService
{
    public const KINDS = ['pensamiento', 'trato', 'dato', 'resumen'];

    public function __construct(private readonly Db $db)
    {
    }

    public function remember(int $userId, string $kind, string $text): void
    {
        $text = Text::truncateBytes(Text::sanitize($text), 500);
        if ($text === '' || !in_array($kind, self::KINDS, true)) {
            return;
        }
        $this->db->exec('INSERT INTO memories(user_id, kind, text, created_at) VALUES(?, ?, ?, ?)', [$userId, $kind, $text, Clock::now()]);
    }

    /** Borra todo lo que Claudia recuerda de un usuario. Devuelve cuántos recuerdos borró. */
    public function forget(int $userId): int
    {
        return $this->db->exec('DELETE FROM memories WHERE user_id = ?', [$userId]);
    }

    /**
     * @return array{resumen:?string, pensamientos:list<string>, tratos:list<string>, datos:list<string>}
     */
    public function forPrompt(int $userId, int $maxThoughts = 8, int $maxFacts = 10, int $maxTratos = 5): array
    {
        $resumen = $this->db->value("SELECT text FROM memories WHERE user_id = ? AND kind = 'resumen' ORDER BY id DESC LIMIT 1", [$userId]);
        return [
            'resumen' => $resumen === null ? null : (string) $resumen,
            'pensamientos' => $this->latest($userId, 'pensamiento', $maxThoughts),
            'tratos' => $this->latest($userId, 'trato', $maxTratos),
            'datos' => $this->latest($userId, 'dato', $maxFacts),
        ];
    }

    /** @return list<array{kind:string, text:string, created_at:int}> */
    public function all(int $userId): array
    {
        return $this->db->all('SELECT kind, text, created_at FROM memories WHERE user_id = ? ORDER BY id', [$userId]);
    }

    public function wordCount(int $userId): int
    {
        $n = 0;
        foreach ($this->db->all('SELECT text FROM memories WHERE user_id = ?', [$userId]) as $row) {
            $n += Text::words((string) $row['text']);
        }
        return $n;
    }

    /**
     * Usuarios cuya memoria (aprox. en palabras) supera el umbral.
     * @return list<int>
     */
    public function usersOver(int $words): array
    {
        $rows = $this->db->all(
            "SELECT user_id, SUM(LENGTH(text) - LENGTH(REPLACE(text, ' ', '')) + 1) AS w
             FROM memories GROUP BY user_id HAVING w > ? ORDER BY w DESC",
            [$words]
        );
        return array_map(fn ($r) => (int) $r['user_id'], $rows);
    }

    /**
     * Reemplaza toda la memoria del usuario por un único resumen (las entradas nuevas posteriores a
     * $upToId se conservan). Devuelve false, sin guardar nada, si las entradas resumidas ya no están:
     * la borraron (forget) mientras se pedía el resumen, y guardarlo la haría reaparecer.
     */
    public function replaceWithSummary(int $userId, string $summary, int $upToId): bool
    {
        return $this->db->transaction(function () use ($userId, $summary, $upToId): bool {
            if ($this->db->exec('DELETE FROM memories WHERE user_id = ? AND id <= ?', [$userId, $upToId]) === 0) {
                return false;
            }
            $this->db->exec(
                "INSERT INTO memories(user_id, kind, text, created_at) VALUES(?, 'resumen', ?, ?)",
                [$userId, Text::sanitize($summary), Clock::now()]
            );
            return true;
        });
    }

    public function maxId(int $userId): int
    {
        return (int) $this->db->value('SELECT MAX(id) FROM memories WHERE user_id = ?', [$userId]);
    }

    /** @return list<string> */
    private function latest(int $userId, string $kind, int $n): array
    {
        $rows = $this->db->all('SELECT text FROM memories WHERE user_id = ? AND kind = ? ORDER BY id DESC LIMIT ?', [$userId, $kind, $n]);
        return array_reverse(array_map(fn ($r) => (string) $r['text'], $rows));
    }
}
