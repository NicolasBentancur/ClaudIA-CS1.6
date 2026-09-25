<?php

declare(strict_types=1);

namespace Claudia;

use Claudia\Ai\AiRouter;
use Claudia\Ai\ChatBuffer;
use Claudia\Ai\ChatService;
use Claudia\Ai\GeminiProvider;
use Claudia\Ai\GroqProvider;
use Claudia\Ai\PromptBuilder;
use Claudia\Ai\Provider;
use Claudia\Ai\RecentGames;
use Claudia\Ai\TriggerPolicy;
use Claudia\Auth\AuthService;
use Claudia\Commands\CommandRouter;
use Claudia\Commands\EconomyCommands;
use Claudia\Commands\GameCommands;
use Claudia\Commands\GeneralCommands;
use Claudia\Commands\JobCommands;
use Claudia\Commands\ReminderCommands;
use Claudia\Economy\LoanService;
use Claudia\Economy\PromotionService;
use Claudia\Economy\Wallet;
use Claudia\Games\Blackjack\BlackjackGame;
use Claudia\Games\Casino;
use Claudia\Games\GameManager;
use Claudia\Games\GameSession;
use Claudia\Games\Roulette\BallPhysics;
use Claudia\Games\Roulette\RouletteGame;
use Claudia\Games\Timers;
use Claudia\Jobs\JobService;
use Claudia\Memory\MemoryService;
use Claudia\Memory\Summarizer;
use Claudia\Net\AsyncHttp;
use Claudia\Net\Out;
use Claudia\Players\SessionManager;
use Claudia\Players\Users;
use Claudia\Reminders\ReminderService;
use Claudia\Stats\StatsService;
use Claudia\Util\Text;

/**
 * Contenedor de servicios. No depende de Workerman (salvo Timers por defecto), así los
 * tests pueden levantar la app completa con una base en memoria y una salida falsa.
 */
final class App
{
    public const VERSION = '1.0.0';

    public readonly Events $events;
    public readonly Out $out;
    public readonly SessionManager $sessions;
    public readonly Users $users;
    public readonly AuthService $auth;
    public readonly AsyncHttp $http;
    public readonly CommandRouter $commands;
    public readonly ChatBuffer $buffer;
    public readonly RecentGames $recentGames;
    public readonly TriggerPolicy $policy;
    public readonly MemoryService $memory;
    public readonly PromptBuilder $prompts;
    public readonly AiRouter $ai;
    public readonly ChatService $chat;
    public readonly Summarizer $summarizer;
    public readonly Wallet $wallet;
    public readonly PromotionService $promos;
    public readonly LoanService $loans;
    public readonly StatsService $stats;
    public readonly JobService $jobs;
    public readonly ReminderService $reminders;
    public readonly Casino $casino;
    public readonly GameManager $games;

    /** @var array<string, int> última ejecución de cada tarea periódica */
    private array $lastRun = [];

    /**
     * @param callable(string, array):void $sink destino de los eventos hacia el plugin
     * @param array<string, Provider>|null $providers proveedores de IA (null = Gemini + Groq reales)
     */
    public function __construct(
        public readonly Config $config,
        public readonly Db $db,
        callable $sink,
        ?array $providers = null,
        ?Timers $timers = null,
    ) {
        $this->events = new Events();
        $this->out = new Out($sink, $config->string('service.chat_tag', '{green}[Claudia]{default} '));
        $this->sessions = new SessionManager($config->int('service.session_resume_seconds', 180));
        $this->users = new Users($db);
        $this->auth = new AuthService($this->users, $config, $this->events);
        $this->http = new AsyncHttp($config->string('service.ca_bundle', '') ?: null);
        $this->commands = new CommandRouter($this->out);

        $this->wallet = new Wallet($db);
        $this->promos = new PromotionService($config, $db);
        $this->loans = new LoanService($config, $db, $this->wallet, $this->promos);
        $this->stats = new StatsService($db, $config);
        $this->jobs = new JobService($config, $db, $this->wallet, $this->promos, $this->stats);

        $this->buffer = new ChatBuffer($config->int('ai.history_messages', 15) + 1);
        $this->recentGames = new RecentGames($config->int('ai.recent_game_seconds', 20));
        $this->policy = new TriggerPolicy($config, $this->recentGames);
        $this->memory = new MemoryService($db);
        $this->prompts = new PromptBuilder($config, $this->users, $this->memory, fn (int $uid) => $this->factsFor($uid));
        $providers ??= [
            'gemini' => new GeminiProvider($this->http, $config, self::secret($config, 'gemini_api_key', 'GEMINI_API_KEY')),
            'groq' => new GroqProvider($this->http, $config, self::secret($config, 'groq_api_key', 'GROQ_API_KEY')),
        ];
        $this->ai = new AiRouter($config, $providers);
        $this->chat = new ChatService($config, $this->buffer, $this->policy, $this->recentGames, $this->prompts, $this->ai, $this->memory, $this->users, $this->out, $this->events);
        $this->summarizer = new Summarizer($config, $this->memory, $this->users, $this->prompts, $this->ai);
        $this->reminders = new ReminderService($config, $db, $this->sessions, $this->users, $this->out, $this->chat, $this->wallet);

        $this->casino = new Casino($config, $db, $this->wallet, $this->loans, $this->recentGames, $this->events);
        $this->games = new GameManager($config, $this->out, $this->sessions, $timers ?? new Timers());
        $this->games->register('ruleta', 'Ruleta', fn (GameSession $s) => new RouletteGame($config, $this->games, $this->casino, $this->wallet, new BallPhysics()));
        $this->games->register('blackjack', 'Blackjack', fn (GameSession $s) => new BlackjackGame($config, $this->games, $this->casino, $this->wallet));

        $this->wire();
    }

    public static function secret(Config $config, string $key, string $env): string
    {
        $v = getenv($env);
        if (is_string($v) && $v !== '') {
            return $v;
        }
        return $config->string("secrets.{$key}", '');
    }

    /** Datos del perfil que la IA conoce de cada jugador. @return list<string> */
    public function factsFor(int $userId): array
    {
        $facts = [];
        $facts[] = 'URU Coins: ' . Text::coins($this->wallet->balance($userId));
        $job = $this->jobs->info($userId);
        $facts[] = $job === null ? 'Trabajo: desempleado' : "Trabajo: {$job['jobName']} en {$job['employerName']} ({$job['levelName']})";
        $debt = $this->loans->totalDebt($userId);
        if ($debt > 0) {
            $facts[] = 'Debe ' . Text::coins($debt) . ' URU Coins a los bancos' . ($this->loans->inClearing($userId) ? ' y está en el Clearing (moroso)' : '');
        }
        $s = $this->stats->get($userId);
        $facts[] = "Stats: {$s['kills']} kills, {$s['deaths']} muertes, {$s['accuracy']}% de precisión";
        $u = $this->users->find($userId);
        if ($u !== null && ($u['birthday'] ?? null) !== null) {
            [$m, $d] = explode('-', (string) $u['birthday']);
            $facts[] = "Cumpleaños: {$d}/{$m}";
        }
        return $facts;
    }

    /**
     * Busca un usuario registrado: primero entre los conectados (nick parcial), después
     * por nick exacto en la base.
     * @return array<string,mixed>
     */
    public function findUser(string $query): array
    {
        $query = trim($query);
        $online = $this->sessions->findByName($query);
        if ($online !== null && $online->userId !== null) {
            return (array) $this->users->find($online->userId);
        }
        $user = $this->users->findByNick($query);
        if ($user === null) {
            throw new UserError("No encontré a ningún usuario registrado \"{$query}\".", 'user_not_found');
        }
        return $user;
    }

    /** Tareas periódicas (se llama cada segundo). */
    public function tick(int $now): void
    {
        $every = function (string $key, int $seconds, callable $fn) use ($now): void {
            if (($this->lastRun[$key] ?? 0) + $seconds <= $now) {
                $this->lastRun[$key] = $now;
                try {
                    $fn();
                } catch (\Throwable $e) {
                    Log::error("Error en tarea '{$key}': " . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
                }
            }
        };
        $every('games', 30, fn () => $this->games->tick());
        $every('reminders', 15, fn () => $this->reminders->tick());
        $every('loans', 60, fn () => $this->loans->tick());
        $every('promos', 60, fn () => $this->promos->tick());
        $every('resume', 300, fn () => $this->sessions->purgeResume());
        $every('summaries', max(30, $this->config->int('ai.memory.summary_check_interval_seconds', 300)), fn () => $this->summarizer->tick());
    }

    /** Inicialización que toca la base (al arrancar el servicio). */
    public function start(): void
    {
        $this->loans->syncBanks();
        $this->casino->refundOpenRounds();
    }

    private function wire(): void
    {
        GeneralCommands::register($this);
        EconomyCommands::register($this);
        JobCommands::register($this);
        ReminderCommands::register($this);
        GameCommands::register($this);

        $this->loans->setJobInfo(fn (int $uid) => $this->jobs->info($uid));
        $this->loans->setPlaytime(fn (int $uid) => $this->stats->playtime($uid));
        $this->loans->setNotifier(function (int $uid, string $msg): void {
            $s = $this->sessions->byUser($uid);
            if ($s !== null) {
                $this->out->chat($s->slot, $msg);
            }
        });
        $this->promos->onActivated(function (array $promo): void {
            $this->out->chat(Out::ALL, '{green}Promo:{default} ' . ($promo['name'] ?? '') . ' - ' . ($promo['description'] ?? ''));
        });

        $this->events->on('chat.message', function ($s): void {
            if ($s->userId !== null) {
                $this->stats->addMessage($s->userId);
            }
        });
        $onLogin = function ($s): void {
            $this->reminders->deliverFor((int) $s->userId);
        };
        $this->events->on('auth.login', function ($s, bool $isNew) use ($onLogin): void {
            $name = $this->users->displayName((int) $s->userId);
            $this->out->chat($s->slot, $isNew
                ? "Listo {$name}, quedaste registrado. Escribí /ayuda para ver lo que podés hacer."
                : "Bienvenido de nuevo, {$name}.");
            $this->out->authState($s->slot, true, true);
            $onLogin($s);
        });
        $this->events->on('auth.resumed', function ($s) use ($onLogin): void {
            $onLogin($s);
        });
        $this->events->on('auth.logout', function ($s): void {
            $this->games->closeForUser((int) $s->userId);
        });
    }
}
