<?php

declare(strict_types=1);

namespace Claudia\Ai;

use Claudia\Clock;
use Claudia\Config;
use Claudia\Log;

/**
 * Prueba los modelos de ai.json "models" en orden hasta que uno devuelva JSON válido
 * que pase el validador. Si fallan todos, llama a $done(null).
 *
 * Para no gastar pedidos de más: un modelo que responde 429 (sin cupo) no se vuelve a probar
 * durante ai.rate_limit_backoff_seconds, y si un proveedor bloquea el prompt por seguridad no se
 * prueban sus otros modelos en esa misma charla (tienen los mismos filtros).
 */
final class AiRouter
{
    /** @var array<string, float> "proveedor:modelo" => hasta cuándo no se prueba */
    private array $coolingUntil = [];

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
        $now = Clock::micro();
        $models = array_values(array_filter(
            $this->config->array('ai.models'),
            fn ($m) => is_array($m) && ($m['enabled'] ?? true) && isset($this->providers[$m['provider'] ?? ''])
                && ($this->coolingUntil[$m['provider'] . ':' . ($m['model'] ?? '')] ?? 0.0) <= $now
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
        $this->providers[$model['provider']]->generate($prompt, $model, function (?array $json, ?string $error) use ($models, $i, $model, $prompt, $validate, $done, $name, $started): void {
            $ms = (int) ((microtime(true) - $started) * 1000);
            if ($json !== null && $validate($json)) {
                Log::debug('IA ok', ['model' => $name, 'ms' => $ms]);
                $done($json, $name);
                return;
            }
            Log::warning('IA: modelo falló, probando el siguiente', ['model' => $name, 'ms' => $ms, 'error' => $error ?? 'JSON inválido']);
            $next = $i + 1;
            if ($error !== null && str_starts_with($error, 'HTTP 429')) {
                $this->coolingUntil[$name] = Clock::micro() + $this->config->int('ai.rate_limit_backoff_seconds', 60);
            } elseif ($error !== null && (str_starts_with($error, 'bloqueado') || str_contains($error, 'SAFETY'))) {
                while (isset($models[$next]) && $models[$next]['provider'] === $model['provider']) {
                    $next++;
                }
            }
            $this->attempt($models, $next, $prompt, $validate, $done);
        });
    }
}
