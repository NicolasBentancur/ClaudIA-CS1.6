<?php

declare(strict_types=1);

namespace Claudia\Ai;

use Claudia\Clock;
use Claudia\Config;
use Claudia\Events;
use Claudia\Log;
use Claudia\Memory\MemoryService;
use Claudia\Net\Out;
use Claudia\Players\Session;
use Claudia\Players\Users;
use Claudia\Util\Text;

/**
 * Recibe el chat global/muertos, decide si Claudia contesta y publica la respuesta.
 */
final class ChatService
{
    public function __construct(
        private readonly Config $config,
        private readonly ChatBuffer $buffer,
        private readonly TriggerPolicy $policy,
        private readonly RecentGames $games,
        private readonly PromptBuilder $prompts,
        private readonly AiRouter $router,
        private readonly MemoryService $memory,
        private readonly Users $users,
        private readonly Out $out,
        private readonly Events $events,
    ) {
    }

    public function onMessage(Session $s, string $text, bool $dead): void
    {
        $text = Text::sanitize($text);
        if ($text === '') {
            return;
        }
        $now = Clock::now();
        $this->buffer->setCapacity($this->config->int('ai.history_messages', 15) + 1);
        $this->buffer->add($now, $s->nick, $s->userId, $text, false, $dead);
        $this->events->emit('chat.message', $s, $text);

        if (!$this->config->bool('ai.enabled', true)) {
            return;
        }
        // Los no logueados van por slot: con el nick en la clave, cambiárselo reiniciaba el cooldown.
        $key = $s->userId !== null ? 'u' . $s->userId : 's' . $s->slot;
        $reason = $this->policy->evaluate($key, $s->userId, $text, Clock::micro());
        if ($reason === null || !$this->policy->takeGlobal(Clock::micro())) {
            return;
        }

        $n = $this->config->int('ai.history_messages', 15);
        $game = $s->userId !== null ? $this->games->recent($s->userId, $now) : null;
        $prompt = $this->prompts->chat(
            $this->buffer->history($n),
            ['nick' => $s->nick, 'userId' => $s->userId, 'text' => $text, 'dead' => $dead],
            $reason,
            $game,
            $this->buffer->userIds($n),
        );

        $this->policy->begin($key);
        $userId = $s->userId;
        $this->router->generate($prompt, fn (array $j) => isset($j['respuesta']) && is_string($j['respuesta']), function (?array $json) use ($key, $userId): void {
            // policy->end() en el finally: si algo falla acá (por ejemplo, guardar la memoria), el
            // jugador no puede quedar "esperando respuesta" para siempre.
            $responded = false;
            try {
                if ($json === null) {
                    if ($this->config->string('ai.on_all_fail', 'silence') === 'message') {
                        $this->say($this->config->string('ai.all_fail_message', 'Me quedé sin cuota, bo. Después hablamos.'));
                    }
                    return;
                }
                $reply = $this->clean((string) ($json['respuesta'] ?? ''));
                if (($json['responder'] ?? true) && $reply !== '') {
                    $this->say($reply);
                    $responded = true;
                }
                if ($userId !== null) {
                    $this->memory->remember($userId, 'pensamiento', (string) ($json['pensamiento'] ?? ''));
                    $this->memory->remember($userId, 'trato', (string) ($json['trato'] ?? ''));
                    foreach (array_slice((array) ($json['datos'] ?? []), 0, 5) as $dato) {
                        if (is_string($dato)) {
                            $this->memory->remember($userId, 'dato', $dato);
                        }
                    }
                }
            } finally {
                $this->policy->end($key, $responded, Clock::now());
            }
        });
    }

    /**
     * Mensaje espontáneo de Claudia generado por IA (ej. cumpleaños). Si no hay cupo
     * o falla la IA, se usa $fallback.
     */
    public function announce(string $instruction, ?int $userId, string $fallback): void
    {
        if (!$this->config->bool('ai.enabled', true) || !$this->policy->takeGlobal(Clock::micro())) {
            $this->say($fallback);
            return;
        }
        $this->router->generate($this->prompts->announcement($instruction, $userId), fn (array $j) => isset($j['respuesta']) && is_string($j['respuesta']), function (?array $json) use ($fallback): void {
            $text = $json === null ? '' : $this->clean((string) $json['respuesta']);
            $this->say($text !== '' ? $text : $fallback);
        });
    }

    /** Publica un mensaje de Claudia en el chat global y lo agrega al historial. */
    public function say(string $text): void
    {
        $text = $this->clean($text);
        if ($text === '') {
            return;
        }
        $this->buffer->add(Clock::now(), 'Claudia', null, $text, true);
        $this->out->rawChat(Out::ALL, $this->config->string('ai.chat_prefix', '{green}Claudia{default}: ') . $text);
        Log::info('Claudia: ' . $text);
    }

    private function clean(string $text): string
    {
        $text = Text::chatSafe(Text::sanitize($text));
        if ($this->config->bool('ai.strip_accents', false)) {
            $text = Text::stripAccents($text);
        }
        $maxLines = max(1, $this->config->int('ai.max_reply_lines', 2));
        $parts = Text::splitBytes($text, 170);
        if (count($parts) > $maxLines) {
            $parts = array_slice($parts, 0, $maxLines);
            $parts[$maxLines - 1] = rtrim($parts[$maxLines - 1], '.,;: ') . '...';
        }
        return implode(' ', $parts);
    }
}
