<?php

declare(strict_types=1);

namespace Claudia\Ai;

use Claudia\Config;
use Claudia\Log;

/**
 * Prueba los modelos de ai.json "models" en orden hasta que uno devuelva JSON válido
 * que pase el validador. Si fallan todos, llama a $done(null).
 */
final class AiRouter
{
    /** @param array<string, Provider> $providers */
    public function __construct(
        private readonly Config $config,
        private readonly array $providers,
    ) {
    }

    /**
     * @param array{system:string, user:string, schema:array} $prompt
     * @param callable(array):bool $validate
     * @param callable(?array $json, ?string $model):void $done
     */
    public function generate(array $prompt, callable $validate, callable $done): void
    {
        $models = array_values(array_filter(
            $this->config->array('ai.models'),
            fn ($m) => is_array($m) && ($m['enabled'] ?? true) && isset($this->providers[$m['provider'] ?? ''])
        ));
        $this->attempt($models, 0, $prompt, $validate, $done);
    }

    private function attempt(array $models, int $i, array $prompt, callable $validate, callable $done): void
    {
        if (!isset($models[$i])) {
            Log::warning('IA: fallaron todos los modelos');
            $done(null, null);
            return;
        }
        $model = $models[$i];
        $name = $model['provider'] . ':' . $model['model'];
        $started = microtime(true);
        $this->providers[$model['provider']]->generate($prompt, $model, function (?array $json, ?string $error) use ($models, $i, $prompt, $validate, $done, $name, $started): void {
            $ms = (int) ((microtime(true) - $started) * 1000);
            if ($json !== null && $validate($json)) {
                Log::debug('IA ok', ['model' => $name, 'ms' => $ms]);
                $done($json, $name);
                return;
            }
            Log::warning('IA: modelo falló, probando el siguiente', ['model' => $name, 'ms' => $ms, 'error' => $error ?? 'JSON inválido']);
            $this->attempt($models, $i + 1, $prompt, $validate, $done);
        });
    }
}
