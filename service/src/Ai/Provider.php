<?php

declare(strict_types=1);

namespace Claudia\Ai;

/**
 * Un proveedor de IA (Gemini, Groq). Las respuestas se piden siempre en JSON.
 */
interface Provider
{
    /**
     * @param array{system:string, user:string, schema:array} $prompt
     * @param array<string,mixed> $model entrada de ai.json "models"
     * @param callable(?array $json, ?string $error):void $done
     */
    public function generate(array $prompt, array $model, callable $done): void;
}
