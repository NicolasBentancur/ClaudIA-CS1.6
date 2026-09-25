<?php

declare(strict_types=1);

namespace Claudia;

use RuntimeException;

/**
 * Carga todos los JSON de config/ (recursivo). Cada archivo queda bajo una clave
 * según su ruta: config/ai.json -> "ai", config/games/roulette.json -> "games.roulette".
 * Las claves que empiezan con "_" se consideran comentarios y se ignoran.
 */
final class Config
{
    private array $data = [];

    public function __construct(private readonly string $dir)
    {
        $this->reload();
    }

    public static function fromArray(array $data): self
    {
        $self = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $self->data = $data;
        return $self;
    }

    public function reload(): void
    {
        if (!is_dir($this->dir)) {
            throw new RuntimeException("No existe el directorio de configuración: {$this->dir}");
        }
        $data = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'json' || str_ends_with($file->getFilename(), '.example.json')) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($this->dir) + 1);
            $key = str_replace(['\\', '/'], '.', substr($rel, 0, -5));
            $json = json_decode((string) file_get_contents($file->getPathname()), true);
            if (!is_array($json)) {
                throw new RuntimeException("JSON inválido en config/{$rel}: " . json_last_error_msg());
            }
            $this->setPath($data, $key, self::stripComments($json));
        }
        $this->data = $data;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function int(string $path, int $default = 0): int
    {
        return (int) $this->get($path, $default);
    }

    public function float(string $path, float $default = 0.0): float
    {
        return (float) $this->get($path, $default);
    }

    public function bool(string $path, bool $default = false): bool
    {
        return (bool) $this->get($path, $default);
    }

    public function string(string $path, string $default = ''): string
    {
        return (string) $this->get($path, $default);
    }

    public function array(string $path, array $default = []): array
    {
        $v = $this->get($path, $default);
        return is_array($v) ? $v : $default;
    }

    /** Solo para tests. */
    public function set(string $path, mixed $value): void
    {
        $this->setPath($this->data, $path, $value);
    }

    private function setPath(array &$data, string $path, mixed $value): void
    {
        $node = &$data;
        foreach (explode('.', $path) as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node = $value;
    }

    private static function stripComments(array $json): array
    {
        foreach ($json as $k => $v) {
            if (is_string($k) && str_starts_with($k, '_')) {
                unset($json[$k]);
            } elseif (is_array($v)) {
                $json[$k] = self::stripComments($v);
            }
        }
        return $json;
    }
}
