<?php

declare(strict_types=1);

namespace Claudia\Memory;

use Claudia\Ai\AiRouter;
use Claudia\Ai\PromptBuilder;
use Claudia\Clock;
use Claudia\Config;
use Claudia\Log;
use Claudia\Players\Users;

/**
 * Tarea periódica: si la memoria de un usuario supera el umbral de palabras (3000 por defecto),
 * le pide a la IA un resumen sin perder detalles importantes y reemplaza la memoria por él.
 * Procesa de a un usuario por vez para no gastar cuota de golpe.
 */
final class Summarizer
{
    /** Si el resumen de alguien falla, se lo saltea este tiempo (si no, trabaría a todos los demás). */
    private const RETRY_AFTER = 3600;

    private bool $running = false;

    /** @var array<int, int> usuario => hasta cuándo no se reintenta */
    private array $failedUntil = [];

    public function __construct(
        private readonly Config $config,
        private readonly MemoryService $memory,
        private readonly Users $users,
        private readonly PromptBuilder $prompts,
        private readonly AiRouter $router,
    ) {
    }

    public function tick(): void
    {
        if ($this->running || !$this->config->bool('ai.enabled', true)) {
            return;
        }
        $threshold = $this->config->int('ai.memory.summary_threshold_words', 3000);
        $candidates = $this->memory->usersOver($threshold);
        foreach ($candidates as $userId) {
            if (($this->failedUntil[$userId] ?? 0) > Clock::now()) {
                continue;
            }
            // La consulta SQL es aproximada; se confirma contando palabras de verdad.
            if ($this->memory->wordCount($userId) > $threshold) {
                $this->summarize($userId);
                return;
            }
        }
    }

    public function summarize(int $userId): void
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            return;
        }
        $upTo = $this->memory->maxId($userId);
        $entries = $this->memory->all($userId);
        $this->running = true;
        $this->router->generate(
            $this->prompts->summary((string) $user['nick'], $entries),
            fn (array $j) => isset($j['resumen']) && is_string($j['resumen']) && trim($j['resumen']) !== '',
            function (?array $json) use ($userId, $upTo, $user): void {
                $this->running = false;
                if ($json === null) {
                    $this->failedUntil[$userId] = Clock::now() + self::RETRY_AFTER;
                    Log::warning('No se pudo resumir la memoria', ['nick' => $user['nick']]);
                    return;
                }
                if (!$this->memory->replaceWithSummary($userId, (string) $json['resumen'], $upTo)) {
                    Log::info('La memoria se borró mientras se resumía: se descarta el resumen', ['nick' => $user['nick']]);
                    return;
                }
                Log::info('Memoria resumida', ['nick' => $user['nick'], 'palabras' => $this->memory->wordCount($userId)]);
            }
        );
    }
}
