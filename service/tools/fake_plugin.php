<?php

declare(strict_types=1);

/**
 * Cliente de prueba que habla el protocolo del plugin AMXX, para probar el servicio sin CS.
 *
 *   php tools/fake_plugin.php [host] [puerto] [secreto]
 *   php tools/fake_plugin.php --script=guion.txt     (ejecuta las líneas del archivo y sale)
 *
 * Luego escribí líneas (en Windows usá --script, la consola interactiva no siempre funciona):
 *   join <slot> <nick>            conectar un jugador
 *   register <slot> <clave>       /registrar
 *   login <slot> <clave>          /login
 *   say <slot> <texto>            chat global (si empieza con / es un comando)
 *   stats <slot> <kills> <deaths> <segundos>
 *   leave <slot>
 *   raw <json>                    manda una línea tal cual
 *   quit
 */

$scriptFile = null;
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--script=')) {
        $scriptFile = substr($a, 9);
    } else {
        $args[] = $a;
    }
}
$host = $args[0] ?? '127.0.0.1';
$port = (int) ($args[1] ?? 27100);
$config = json_decode((string) @file_get_contents(dirname(__DIR__) . '/config/service.json'), true) ?: [];
$secrets = json_decode((string) @file_get_contents(dirname(__DIR__) . '/config/secrets.json'), true) ?: [];
// Mismo orden que bin/claudia.php: CLAUDIA_PLUGIN_SECRET, secrets.json y, de respaldo, service.json.
$secret = $args[2] ?? ((string) getenv('CLAUDIA_PLUGIN_SECRET') ?: (string) ($secrets['plugin_secret'] ?? '') ?: (string) ($config['plugin']['secret'] ?? ''));

$sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
if (!$sock) {
    fwrite(STDERR, "No se pudo conectar: {$errstr}\n");
    exit(1);
}
stream_set_blocking($sock, false);

$id = 0;
$commands = [];
$send = function (string $type, array $data, bool $wantReply = true) use ($sock, &$id): int {
    $msgId = $wantReply ? ++$id : 0;
    fwrite($sock, json_encode(['id' => $msgId, 'type' => $type, 'data' => $data], JSON_UNESCAPED_UNICODE) . "\n");
    return $msgId;
};
$show = function (string $line) use (&$commands): void {
    $msg = json_decode($line, true);
    if (!is_array($msg)) {
        echo "?? {$line}\n";
        return;
    }
    if (isset($msg['type'])) {
        $d = $msg['data'];
        if ($msg['type'] === 'print') {
            $text = preg_replace('/[\x01-\x04]/', '', $d['text']);
            echo "[{$d['channel']} -> {$d['to']}] {$text}\n";
        } else {
            echo "[evento {$msg['type']}] " . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
        return;
    }
    if (isset($msg['data']['commands'])) {
        $commands = $msg['data']['commands'];
        echo "[hello ok] versión {$msg['data']['version']}, " . count($commands) . " comandos\n";
        return;
    }
    echo "[resp #{$msg['id']}] " . json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
};

$send('hello', ['secret' => $secret, 'server' => 'fake', 'map' => 'de_dust2', 'ip' => '127.0.0.1']);

$buffer = '';
$stdin = fopen('php://stdin', 'r');
$script = [];
$scripted = $scriptFile !== null;
if ($scripted) {
    $script = file($scriptFile, FILE_IGNORE_NEW_LINES) ?: [];
}
$nicks = [];
$deadline = microtime(true);
while (true) {
    $chunk = fread($sock, 65536);
    if ($chunk === '' && feof($sock)) {
        echo "Conexión cerrada\n";
        break;
    }
    $buffer .= (string) $chunk;
    while (($pos = strpos($buffer, "\n")) !== false) {
        $show(substr($buffer, 0, $pos));
        $buffer = substr($buffer, $pos + 1);
    }
    if ($script !== [] && microtime(true) >= $deadline) {
        $line = array_shift($script);
    } elseif ($scripted) {
        if (microtime(true) > $deadline + 3) {
            break; // fin del guion: esperar respuestas pendientes y salir
        }
        usleep(50000);
        continue;
    } else {
        $r = [$stdin];
        $w = $e = null;
        if (@stream_select($r, $w, $e, 0, 50000) !== 1) {
            continue;
        }
        $line = trim((string) fgets($stdin));
    }
    $deadline = microtime(true) + 0.4;
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    echo "> {$line}\n";
    $parts = explode(' ', $line, 3);
    switch ($parts[0]) {
        case 'join':
            $nicks[(int) $parts[1]] = $parts[2];
            $send('player.join', ['slot' => (int) $parts[1], 'nick' => $parts[2], 'ip' => '127.0.0.' . $parts[1], 'authid' => 'STEAM_0:0:' . $parts[1]]);
            break;
        case 'register':
            $send('auth.register', ['slot' => (int) $parts[1], 'password' => $parts[2]]);
            break;
        case 'login':
            $send('auth.login', ['slot' => (int) $parts[1], 'password' => $parts[2]]);
            break;
        case 'say':
            $text = $parts[2] ?? '';
            if (preg_match('#^[/!](\S+)\s*(.*)$#', $text, $m) && in_array(mb_strtolower($m[1]), $commands, true)) {
                $send('cmd', ['slot' => (int) $parts[1], 'name' => $m[1], 'args' => $m[2], 'staff' => true, 'admin' => true], false);
            } else {
                $send('chat', ['slot' => (int) $parts[1], 'text' => $text, 'dead' => false], false);
            }
            break;
        case 'stats':
            $v = explode(' ', $parts[2] ?? '0 0 0');
            $send('stats', ['players' => [['slot' => (int) $parts[1], 'kills' => (int) $v[0], 'deaths' => (int) ($v[1] ?? 0), 'playtime' => (int) ($v[2] ?? 0)]]], false);
            break;
        case 'leave':
            $send('player.leave', ['slot' => (int) $parts[1]]);
            break;
        case 'raw':
            fwrite($sock, substr($line, 4) . "\n");
            break;
        case 'quit':
            exit(0);
        default:
            echo "Comando desconocido\n";
    }
}
