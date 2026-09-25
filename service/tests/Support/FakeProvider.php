<?php

declare(strict_types=1);

namespace Claudia\Tests\Support;

use Claudia\Ai\Provider;

/**
 * Proveedor de IA falso: devuelve respuestas encoladas (o un error) y guarda los prompts.
 */
final class FakeProvider implements Provider
{
    /** @var list<array|string> array = JSON de respuesta, string = error */
    public array $queue = [];

    /** @var list<array{prompt:array, model:array}> */
    public array $calls = [];

    public function generate(array $prompt, array $model, callable $done): void
    {
        $this->calls[] = ['prompt' => $prompt, 'model' => $model];
        $next = array_shift($this->queue) ?? 'sin respuesta';
        is_array($next) ? $done($next, null) : $done(null, $next);
    }
}
