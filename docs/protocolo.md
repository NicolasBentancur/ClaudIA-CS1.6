# Protocolo plugin ↔ servicio

La conexión es TCP a `127.0.0.1:27100`. Cada mensaje es **una línea JSON** en UTF-8 terminada en `\n`.

## Formato

| Dirección | Forma |
|---|---|
| plugin → servicio | `{"id": 7, "type": "auth.login", "data": {...}}`. Con `id` 0 el plugin no espera respuesta. |
| servicio → plugin (respuesta) | `{"id": 7, "ok": true, "data": {...}}` o `{"id": 7, "ok": false, "error": "codigo", "message": "texto para el jugador"}` |
| servicio → plugin (evento) | `{"type": "print", "data": {...}}` |

El primer mensaje tiene que ser `hello` con el secreto compartido. Si el secreto no coincide, el servicio cierra la conexión.

## Mensajes del plugin

| type | data | respuesta |
|---|---|---|
| `hello` | `secret, version, ip, map, server` | `version, commands[]` (lista de comandos de chat que el plugin tiene que reenviar) |
| `player.join` | `slot, nick, ip, authid, was_logged, role` | `registered, logged` |
| `player.leave` | `slot` | - |
| `player.rename` | `slot, nick` | `registered, logged` (siempre sin login) |
| `auth.register` / `auth.login` | `slot, password` | `registered, logged` o error |
| `auth.change` | `slot, old, new` | - o error |
| `chat` | `slot, text, dead` | - (Claudia contesta con eventos `print`) |
| `cmd` | `slot, name, args, role` (0 jugador, 1 admin, 2 staff, 3 owner; también `staff`/`admin` por compatibilidad) | - (la salida llega como eventos `print` / `motd`) |
| `input` | `slot, text` | - (respuesta a un formulario por chat, mientras `input.capture` está activo) |
| `menu.select` | `slot, role, menu, item, page` | - (opción elegida en un menú de HUD) |
| `menu.input` | `slot, role, text` | - (texto escrito en `messagemode claudia_input`; vacío = cancelar) |
| `menu.close` | `slot, role, menu` | - |
| `stats` | `players: [{slot, kills, deaths, headshots, shots, hits, playtime, rounds}]` (deltas; `rounds` alimenta las cuotas e impuestos de los grupos) | - |
| `admin.coins` | `slot, admin_name, target, amount, mode (give\|take)` | `message` |
| `admin.group` | `slot, admin_name, args` (ej. `"crear N1k PIB Los Pibes"`) | `message`, `lines[]` (detalle opcional) |
| `admin.reload` | - | `message` |

## Eventos del servicio

| type | data | qué hace el plugin |
|---|---|---|
| `print` | `to` (0 todos, N slot, -1 consola del servidor), `channel` (`chat`\|`console`), `text` | `client_print_color` / `client_print` / `server_print`. `\x01 \x03 \x04` son los colores. |
| `sound` | `slot, sound` | `spk "<sound>"` solo para ese jugador. |
| `motd` | `slot, title, url` | `show_motd` con la URL del juego. |
| `auth.state` | `slot, registered, logged` | Actualiza el estado y dispara `claudia_auth_changed`. |
| `chat.tag` | `slot, tag` | Tag del grupo (`""` = ninguno). Si tiene, el plugin imprime su chat como `[TAG]nick :  mensaje` (respetando chat de muertos y de equipo) en lugar del chat normal. Se borra solo al desloguearse. |
| `menu` | `slot, id, title, items[], page` | Muestra un newmenu. Los textos pueden tener `\w \y \r \d`. Hasta 9 opciones van en una página con "0. Salir"; con más, se pagina de a 7. Devuelve `menu.select` / `menu.close`. |
| `prompt` | `slot, label` | Muestra el texto en el chat y abre `messagemode claudia_input`. |
| `exec` | `slot, cmd` | Ejecuta en el cliente un comando de la lista blanca del plugin (hoy solo `amxmodmenu`). |
| `input.capture` | `slot, on` | Mientras `on` es true, lo que el jugador escribe (que no sea un /comando) no se muestra y se manda como `input`. |
| otro | - | Se reenvía a los plugins con el forward `claudia_event`. |

## Reconexión y cambio de mapa

En cada cambio de mapa el plugin se recarga y se vuelve a conectar. El servicio recuerda durante `session_resume_seconds` el nick y la IP de cada jugador logueado, así que al volver no tiene que identificarse de nuevo.

Si el que se reinicia es el servicio, el plugin manda `was_logged: true` para restaurar la sesión.

## Juegos (MOTD)

`/ruleta` y `/blackjack` crean un token de un solo uso y el servicio manda el evento `motd` con esta URL:

```
http://<public_host>:27101/juegos/<juego>/?t=<token>&ws=ws://<public_host>:27102/
```

La página intenta conectarse por WebSocket y manda `{"type":"hello","token":"..."}` como primer mensaje.

Si en 2,5 s no pudo conectar, usa polling:

- `GET /api/poll?t=<token>&since=<seq>`
- `POST /api/send?t=<token>`

Todos los mensajes hacia la página llevan un `seq` creciente, así que al cambiar de transporte no se pierde ni se duplica nada.

### Ruleta

| Mensaje | Contenido |
|---|---|
| Página → servidor | `{"type":"spin","bets":[...]}` (ver `src/Games/Roulette/Bets.php`) |
| `init` | `balance, chips, min, max, wheel, order, red, history, busy` |
| `spin_start` | `fps, duration, buffer, balance, total` |
| `frames` | `f: [[ms, ruedaGrados, bolaGrados, radio], ...], last` (toda la trayectoria en un solo mensaje; la página la convierte en animación CSS) |
| `result` | `number, color, payout, net, confiscated, balance, chips, winners, history` |

Los sonidos (giro, cada rebote, ganar/perder) los programa el servicio con los tiempos de la simulación y los reproduce el plugin.

### Slots (chanchitos, dulce, materush)

| Mensaje | Contenido |
|---|---|
| Página → servidor | `spin {bet}`, `buy {bet, option}`, `sfx {name}` |
| `init` | `name, layout, balance, bets, buy[{id,name,price}], maxWin` |
| `result` | `bet, cost, buy, steps[], bonus, multiple, capped, payout, net, confiscated, balance` |

El servidor resuelve y paga el giro completo, incluidos cascadas, giros gratis y bonus, antes de responder. `steps` es la lista de pasos que anima la página:

- `spin`, `drop`, `pay`, `tumble`
- `bombs`, `rush`, `electric`, `sync`
- `fs_start`, `fs_retrigger`, `fs_end`
- `hw_start`, `hw_collect`, `hw_respin`, `hw_end`
- `spin_end`

La página pide los sonidos con `sfx` en el momento justo de la animación. El servicio solo reproduce los que están en el JSON del slot, con un máximo de 8 por segundo.

### Blackjack

| Mensaje | Contenido |
|---|---|
| Página → servidor | `bet {amount}`, `hit`, `stand`, `double`, `split` |
| `state` | `phase, dealer{cards,value,blackjack}, hands[{cards,value,soft,bet,doubled,done,result,return}], active, actions{hit,stand,double,split,bet}, balance, chips, min, max, rules` |
