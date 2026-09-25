<?php

declare(strict_types=1);

namespace Claudia\Util;

/**
 * Utilidades de texto para el chat de CS 1.6.
 */
final class Text
{
    /** Códigos de color de client_print_color. */
    public const DEFAULT = "\x01";
    public const TEAM = "\x03";
    public const GREEN = "\x04";

    /** Reemplaza {green} {default} {team} por los bytes de color del chat. */
    public static function colors(string $text): string
    {
        return strtr($text, ['{green}' => self::GREEN, '{default}' => self::DEFAULT, '{team}' => self::TEAM]);
    }

    /** Quita caracteres de control (incluye los códigos de color) y colapsa espacios. */
    public static function sanitize(string $text): string
    {
        $text = self::validUtf8($text);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return trim($text);
    }

    public static function validUtf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    }

    /** Minúsculas y sin tildes, para comparar palabras clave. */
    public static function fold(string $text): string
    {
        $text = mb_strtolower(self::validUtf8($text), 'UTF-8');
        return strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);
    }

    public static function stripAccents(string $text): string
    {
        return strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            '¿' => '', '¡' => '', '“' => '"', '”' => '"', '‘' => "'", '’' => "'", '…' => '...', '—' => '-', '–' => '-',
        ]);
    }

    /** Quita emojis y símbolos que el chat de CS 1.6 no puede mostrar (fuera del plano básico latino). */
    public static function chatSafe(string $text): string
    {
        $text = strtr($text, ['“' => '"', '”' => '"', '‘' => "'", '’' => "'", '…' => '...', '—' => '-', '–' => '-']);
        // Deja ASCII imprimible y Latin-1 Supplement (tildes, ñ, ¿, ¡).
        return preg_replace('/[^\x{20}-\x{7E}\x{A0}-\x{FF}]/u', '', $text) ?? '';
    }

    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Parte un texto en trozos de como máximo $maxBytes bytes UTF-8 sin cortar palabras
     * (salvo que una sola palabra sea más larga que el límite).
     * @return list<string>
     */
    public static function splitBytes(string $text, int $maxBytes): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $chunks = [];
        $current = '';
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            while (strlen($word) > $maxBytes) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $cut = mb_strcut($word, 0, $maxBytes, 'UTF-8');
                $chunks[] = $cut;
                $word = substr($word, strlen($cut));
            }
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (strlen($candidate) > $maxBytes) {
                $chunks[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /** Recorta a $maxBytes bytes sin romper un carácter UTF-8. */
    public static function truncateBytes(string $text, int $maxBytes): string
    {
        return strlen($text) <= $maxBytes ? $text : mb_strcut($text, 0, $maxBytes, 'UTF-8');
    }

    /**
     * Interpreta montos: "500", "1.500", "1,500", "2k", "1.5k", "1m". Devuelve null si no es válido.
     */
    public static function parseAmount(string $raw): ?int
    {
        $raw = strtolower(trim($raw));
        if (!preg_match('/^(\d+(?:[.,]\d+)*)(k|m)?$/', $raw, $m)) {
            return null;
        }
        $num = $m[1];
        $mult = match ($m[2] ?? '') {
            'k' => 1000,
            'm' => 1000000,
            default => 1,
        };
        if ($mult > 1) {
            $value = (float) str_replace(',', '.', $num) * $mult;
        } else {
            // Sin sufijo, los separadores son de miles.
            $value = (float) str_replace(['.', ','], '', $num);
        }
        if ($value <= 0 || $value > PHP_INT_MAX / 2) {
            return null;
        }
        return (int) round($value);
    }

    /** 12345 -> "12.345" */
    public static function coins(int $amount): string
    {
        return number_format($amount, 0, ',', '.');
    }

    /**
     * Interpreta una duración o fecha para recordatorios, relativa a $now.
     * Acepta: "30m", "2h", "1d", "1h30m", "25/12", "25/12 18:00", "18:00".
     * Devuelve [timestamp, cantidadDeTokensConsumidos] o null.
     * @param list<string> $tokens
     * @return array{0:int,1:int}|null
     */
    public static function parseWhen(array $tokens, int $now, string $tz = 'America/Montevideo'): ?array
    {
        if ($tokens === []) {
            return null;
        }
        $first = strtolower($tokens[0]);
        if (preg_match('/^(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?$/', $first, $m) && $first !== '') {
            $secs = ((int) ($m[1] ?? 0)) * 86400 + ((int) ($m[2] ?? 0)) * 3600 + ((int) ($m[3] ?? 0)) * 60;
            return $secs > 0 ? [$now + $secs, 1] : null;
        }
        $zone = new \DateTimeZone($tz);
        $base = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $first, $d)) {
            $day = (int) $d[1];
            $month = (int) $d[2];
            if (!checkdate($month, $day, (int) $base->format('Y'))) {
                return null;
            }
            $hour = 9;
            $minute = 0;
            $used = 1;
            if (isset($tokens[1]) && preg_match('/^(\d{1,2}):(\d{2})$/', $tokens[1], $t)) {
                $hour = (int) $t[1];
                $minute = (int) $t[2];
                $used = 2;
                if ($hour > 23 || $minute > 59) {
                    return null;
                }
            }
            $target = $base->setDate((int) $base->format('Y'), $month, $day)->setTime($hour, $minute);
            if ($target->getTimestamp() <= $now) {
                $target = $target->modify('+1 year');
            }
            return [$target->getTimestamp(), $used];
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $first, $t)) {
            $hour = (int) $t[1];
            $minute = (int) $t[2];
            if ($hour > 23 || $minute > 59) {
                return null;
            }
            $target = $base->setTime($hour, $minute);
            if ($target->getTimestamp() <= $now) {
                $target = $target->modify('+1 day');
            }
            return [$target->getTimestamp(), 1];
        }
        return null;
    }

    /** Formatea segundos como "2d 3h", "45m", "30s". */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($d > 0) {
            return $h > 0 ? "{$d}d {$h}h" : "{$d}d";
        }
        if ($h > 0) {
            return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
        }
        if ($m > 0) {
            return "{$m}m";
        }
        return "{$seconds}s";
    }
}
