<?php

declare(strict_types=1);

/**
 * Servicio intermedio de Claudia.
 *
 *   php bin/claudia.php start        (primer plano)
 *   php bin/claudia.php start -d     (demonio, solo Linux)
 *   php bin/claudia.php stop|restart|status   (solo Linux)
 */

use Claudia\App;
use Claudia\Clock;
use Claudia\Config;
use Claudia\Db;
use Claudia\Log;
use Claudia\Net\HttpServer;
use Claudia\Net\PluginHandlers;
use Claudia\Net\PluginLink;
use Claudia\Net\WsServer;
use Workerman\Timer;
use Workerman\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';

$base = dirname(__DIR__);
$config = new Config($base . '/config');
date_default_timezone_set($config->string('service.timezone', 'America/Montevideo'));

$path = fn (string $p) => preg_match('#^([a-zA-Z]:)?[\\\\/]#', $p) ? $p : $base . '/' . $p;
Log::configure($path($config->string('service.log.file', 'logs/claudia.log')), $config->string('service.log.level', 'info'));
Clock::setOffset($config->int('service.debug.time_offset_seconds', 0));

// El secreto real va en secrets.json (plugin_secret) o en CLAUDIA_PLUGIN_SECRET; service.json queda de respaldo.
$secret = App::secret($config, 'plugin_secret', 'CLAUDIA_PLUGIN_SECRET');
if ($secret === '') {
    $secret = $config->string('service.plugin.secret', '');
}
if ($secret === '' || $secret === 'CAMBIAR-ESTE-SECRETO') {
    Log::warning('El secreto compartido con el plugin es el de ejemplo. Ponelo en config/secrets.json (plugin_secret) y en claudia.cfg.');
}

$db = new Db($path($config->string('service.database', 'data/claudia.sqlite')));
$db->migrate($base . '/migrations');

$link = new PluginLink($secret);
$app = new App($config, $db, fn (string $type, array $data) => $link->push($type, $data));
PluginHandlers::register($app, $link);

@mkdir($base . '/logs', 0775, true);
Worker::$pidFile = $base . '/logs/claudia.pid';
Worker::$logFile = $base . '/logs/workerman.log';
Worker::$stdoutFile = $base . '/logs/stdout.log';

$pluginWorker = new Worker(sprintf('text://%s:%d', $config->string('service.plugin.host', '127.0.0.1'), $config->int('service.plugin.port', 27100)));
$pluginWorker->name = 'claudia';
$link->attach($pluginWorker);

$pluginWorker->onWorkerStart = function () use ($app, $config, $base): void {
    $app->start();

    // En un mismo proceso: HTTP para las páginas y WebSocket para los juegos (compatible con Windows).
    $http = new Worker(sprintf('http://%s:%d', $config->string('service.http.host', '0.0.0.0'), $config->int('service.http.port', 27101)));
    $httpServer = new HttpServer($base . '/public', $app->games);
    $http->onMessage = [$httpServer, 'handle'];
    $http->listen();

    $ws = new Worker(sprintf('websocket://%s:%d', $config->string('service.ws.host', '0.0.0.0'), $config->int('service.ws.port', 27102)));
    (new WsServer($app->games))->attach($ws);
    $ws->listen();

    Timer::add(0.05, fn () => $app->http->tick());
    Timer::add(1.0, fn () => $app->tick(Clock::now()));

    Log::info(sprintf(
        'Claudia %s escuchando: plugin %s:%d | http :%d | ws :%d',
        App::VERSION,
        $config->string('service.plugin.host', '127.0.0.1'),
        $config->int('service.plugin.port', 27100),
        $config->int('service.http.port', 27101),
        $config->int('service.ws.port', 27102),
    ));
};

Worker::runAll();
