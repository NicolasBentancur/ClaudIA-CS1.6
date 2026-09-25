<?php

declare(strict_types=1);

namespace Claudia;

use PDO;
use Throwable;

/**
 * Envoltura fina sobre PDO/SQLite. El servicio es el único proceso que escribe la base.
 */
final class Db
{
    private PDO $pdo;
    private int $txDepth = 0;

    public function __construct(string $path)
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->prepare($sql, $params);
        $st->execute();
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->prepare($sql, $params);
        $st->execute();
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $st = $this->prepare($sql, $params);
        $st->execute();
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function exec(string $sql, array $params = []): int
    {
        $st = $this->prepare($sql, $params);
        $st->execute();
        return $st->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->exec($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Ejecuta $fn dentro de una transacción. Soporta anidamiento (solo la externa hace commit).
     * @template T
     * @param callable():T $fn
     * @return T
     */
    /**
     * Prepara y enlaza parámetros con su tipo real. Con execute($params) PDO manda todo como
     * texto, y en SQLite una expresión numérica sin afinidad comparada con texto siempre da falso.
     */
    private function prepare(string $sql, array $params): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $i = 0;
        foreach ($params as $key => $value) {
            $name = is_int($key) ? ++$i : (str_starts_with((string) $key, ":") ? (string) $key : ":" . $key);
            $type = match (true) {
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        return $st;
    }

    public function transaction(callable $fn): mixed
    {
        if ($this->txDepth === 0) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        }
        $this->txDepth++;
        try {
            $result = $fn();
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $this->pdo->exec('COMMIT');
            }
            return $result;
        } catch (Throwable $e) {
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    /** Aplica los .sql de $dir cuyo número sea mayor al PRAGMA user_version. */
    public function migrate(string $dir): void
    {
        $current = (int) $this->value('PRAGMA user_version');
        $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $version = (int) basename($file);
            if ($version <= $current) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            $this->pdo->exec('BEGIN');
            try {
                $this->pdo->exec($sql);
                $this->pdo->exec('PRAGMA user_version = ' . $version);
                $this->pdo->exec('COMMIT');
            } catch (Throwable $e) {
                $this->pdo->exec('ROLLBACK');
                throw $e;
            }
            Log::info('Migración aplicada', ['archivo' => basename($file)]);
        }
    }

    public function kvGet(string $key, ?string $default = null): ?string
    {
        $v = $this->value('SELECT value FROM kv WHERE key = ?', [$key]);
        return $v === null ? $default : (string) $v;
    }

    public function kvSet(string $key, string $value): void
    {
        $this->exec('INSERT INTO kv(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
    }
}
