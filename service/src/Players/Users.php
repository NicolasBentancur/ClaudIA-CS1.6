<?php

declare(strict_types=1);

namespace Claudia\Players;

use Claudia\Clock;
use Claudia\Db;

/**
 * Acceso a la tabla users.
 */
final class Users
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function findByNick(string $nick): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE nick = ? COLLATE NOCASE', [$nick]);
    }

    public function create(string $nick, string $passHash, string $ip, string $authid): int
    {
        $now = Clock::now();
        return $this->db->transaction(function () use ($nick, $passHash, $ip, $authid, $now) {
            $id = $this->db->insert(
                'INSERT INTO users(nick, pass_hash, coins, last_ip, last_authid, created_at, last_seen) VALUES(?, ?, 0, ?, ?, ?, ?)',
                [$nick, $passHash, $ip, $authid, $now, $now]
            );
            $this->db->exec('INSERT INTO stats(user_id) VALUES(?)', [$id]);
            return $id;
        });
    }

    public function touch(int $id, string $ip, string $authid): void
    {
        $this->db->exec('UPDATE users SET last_seen = ?, last_ip = ?, last_authid = ? WHERE id = ?', [Clock::now(), $ip, $authid, $id]);
    }

    public function setPassword(int $id, string $hash): void
    {
        $this->db->exec('UPDATE users SET pass_hash = ? WHERE id = ?', [$hash, $id]);
    }

    public function setApodo(int $id, ?string $apodo): void
    {
        $this->db->exec('UPDATE users SET apodo = ? WHERE id = ?', [$apodo, $id]);
    }

    /**
     * No toca last_birthday_year: el regalo es uno por año aunque se cambie la fecha. Si no, anotar
     * la de hoy una y otra vez lo cobraría cada vez.
     */
    public function setBirthday(int $id, ?string $mmdd): void
    {
        $this->db->exec('UPDATE users SET birthday = ? WHERE id = ?', [$mmdd, $id]);
    }

    public function coins(int $id): int
    {
        return (int) $this->db->value('SELECT coins FROM users WHERE id = ?', [$id]);
    }

    /** Nombre a mostrar: apodo si tiene, si no el nick. */
    public function displayName(int $id): string
    {
        $u = $this->find($id);
        if ($u === null) {
            return '?';
        }
        return ($u['apodo'] ?? '') !== '' ? (string) $u['apodo'] : (string) $u['nick'];
    }
}
