<?php

declare(strict_types=1);

namespace Claudia\Ai;

/**
 * Extrae un objeto JSON de la salida de un modelo (tolera ```json ... ``` y texto alrededor).
 */
final class JsonReply
{
    public static function parse(string $text): ?array
    {
        $text = trim($text);
        $json = json_decode($text, true);
        if (is_array($json)) {
            return $json;
        }
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $json = json_decode($m[1], true);
            if (is_array($json)) {
                return $json;
            }
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $json = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($json)) {
                return $json;
            }
        }
        return null;
    }
}
