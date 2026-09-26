# Claudia

![Bender entrando por una puerta, con el texto «ClaudIA llegando al grupo»](assets/banner.jpg)

[![CI](https://github.com/NicolasBentancur/ClaudIA-CS1.6/actions/workflows/ci.yml/badge.svg)](https://github.com/NicolasBentancur/ClaudIA-CS1.6/actions/workflows/ci.yml)

Plugin de Counter-Strike 1.6 (AMX Mod X 1.10 + ReHLDS) con una IA de personalidad uruguaya en el chat, cuentas con contraseña, economía en URU Coins (bancos, préstamos y Clearing), trabajos, perfiles, rankings, recordatorios y un casino (ruleta francesa, blackjack, minas y slots) y ajedrez 1 contra 1 por apuesta que se juegan en la ventana MOTD.

[@carlosplanchon](https://github.com/carlosplanchon) ayudó en el hardening.

```
 CS 1.6 (ReHLDS)                     Misma PC                            Nube
┌──────────────────┐  TCP localhost  ┌─────────────────────────────┐  HTTPS  ┌──────────────┐
│ Plugins AMXX     │◄──JSON lines──► │ Servicio PHP (Workerman)    │ ──────► │ Gemini       │
│ (cliente fino)   │                 │  • TCP  :27100 (plugin)     │         │ Groq (fallb.)│
└──────────────────┘                 │  • HTTP :27101 (páginas)    │         └──────────────┘
        ▲ show_motd(url+token)       │  • WS   :27102 (juegos)     │
 Cliente del jugador ── HTTP/WS ───► │  • SQLite                   │
                                     └─────────────────────────────┘
```

- **Plugins AMXX** (`amxmodx/`): se encargan de los hooks del juego, el chat, los comandos, el login, el kick, las estadísticas, los sonidos y los MOTD. No tienen lógica de negocio.
- **Servicio PHP** (`service/`): es el único dueño de la base SQLite. Tiene toda la lógica: la IA, la economía, los trabajos y los juegos. Además sirve las páginas de los juegos y les manda en tiempo real los frames que calcula.

## Estructura

```
amxmodx/scripting/        claudia_core, claudia_auth, claudia_stats, claudia_admin (+ include/claudia.inc)
amxmodx/configs/          claudia/claudia.cfg, claudia/sounds.ini, plugins-claudia.ini
amxmodx/data/lang/        claudia.txt
sound/claudia/            sonidos .wav (generados con service/tools/gen_sounds.php)
service/bin/claudia.php   punto de entrada del servicio
service/config/           JSON de configuración por categoría + personality.md
service/src/              código (Ai, Auth, Economy, Jobs, Games, ...)
service/public/juegos/    páginas MOTD (ruleta, blackjack, minas, slots, ajedrez)
service/tests/            PHPUnit
docs/                     protocolo y configuración
assets/                   imágenes del README
```

## Instalación

### 1. Servicio (Windows o Linux)

Requisitos: PHP 8.2 o superior con las extensiones `curl`, `mbstring`, `pdo_sqlite`, `openssl` y `sockets`, y Composer.

```bash
cd service
composer install --no-dev
cp config/secrets.example.json config/secrets.json   # y completá las API keys
```

1. En `config/secrets.json` poné en `plugin_secret` un secreto propio (no va en `service.json`, que se sube al repositorio).
2. Si el servidor tiene varias IP, completá también `http.public_host` con la IP pública.
3. Abrí hacia Internet los puertos TCP **27101** (HTTP) y **27102** (WebSocket). La ventana MOTD la abre la PC del jugador, así que tiene que poder llegar a esos puertos.
4. El puerto **27100** queda solo para localhost.

Para arrancar el servicio:

- Windows: `service\start.bat`
- Linux: `service/start.sh`, o `service/start.sh -d` para correrlo como demonio.

Las API keys también se pueden pasar por variables de entorno (`GEMINI_API_KEY`, `GROQ_API_KEY`).

### 2. Plugins

Requisitos del servidor:

- **ReHLDS** funciona solo con el HLDS anterior al aniversario. Instalalo con `app_update 90 -beta steam_legacy` en SteamCMD y después copiá encima los binarios de ReHLDS.
- **Metamod(-R)** se carga desde `cstrike/liblist.gam` con `gamedll "addons\metamod\metamod.dll"` (Linux: `gamedll_linux "addons/metamod/metamod_i386.so"`).
- En `addons/metamod/plugins.ini` tiene que estar AMXX: `win32 addons\amxmodx\dlls\amxmodx_mm.dll` o `linux addons/amxmodx/dlls/amxmodx_mm_i386.so`.

Pasos:

1. Compilá con `compilar.bat` (Windows) o `compilar.sh` (Linux, con `AMXX_DIR` o `AMXXPC`). Queda todo armado en `build/cstrike/`.
2. Copiá el contenido de `build/cstrike/` en la carpeta `cstrike` del servidor.
3. Editá `addons/amxmodx/configs/claudia/claudia.cfg` y poné en `claudia_secret` el mismo secreto que `plugin_secret` en `secrets.json`.
4. Verificá que en `addons/amxmodx/configs/modules.ini` estén habilitados los módulos `sockets`, `json`, `cstrike`, `fakemeta`, `hamsandwich`, `fun` y `engine`.
5. En `addons/amxmodx/configs/plugins.ini` comentá (con `;`) `scrollmsg.amxx`, `imessage.amxx` y `adminhelp.amxx` (los mensajes de "This server is using AMX Mod X" / "Welcome to...") y `mapchooser.amxx` (la votación de mapa la hace `claudia_publico`).

`plugins-claudia.ini` se carga solo. `claudia_publico` va primero (no depende del resto) y después `claudia_core`.

Para ver el estado de la conexión, escribí `claudia_status` en la consola del servidor.

## Menú

`/menu` (o una tecla con `bind "F3" "claudia_menu"`) abre un menú de HUD con todo: perfil, economía y bancos, trabajo, casino, combate, pareja y familia, grupo, tienda y más opciones (perfil, rankings, recordatorios). Los menús cambian según la situación del jugador (con o sin pareja, miembro o dueño de grupo, propuestas pendientes). Cuando hace falta un monto o un texto, se abre la barra para escribir.

Los admins ven además **Administración** (`/admin`). Las opciones dependen del rol (ver `service/config/roles.json`):

- **admin:** jugadores (perfil, borrar apodo, sacar de un grupo), grupos (ver, editar, agregar y sacar miembros), anuncios de Claudia y el menú de AMX Mod X.
- **staff:** todo lo anterior, más el perfil completo, dar/quitar coins (hasta 10.000 por vez), borrar la memoria de la IA, crear y eliminar grupos, cambiar dueños y silenciar a Claudia.
- **owner:** todo, más contraseñas temporales, perdonar deudas, activar promociones, recargar la configuración y ver el estado del servicio.

## Servidor público (claudia_publico)

Funciona aunque el servicio esté caído. Se configura en `configs/claudia/publico.cfg` (rondas y cvars `cp_*`) y `configs/claudia/mapas.ini` (mapas de la votación).

- **Loadouts:** al aparecer se abre un menú con AK-47, M4A1, AWP, Famas, Galil, Scout o MP5, siempre con Deagle, chaleco y casco, HE, 2 flashes y humo (y kit de desactivación para los CT). "Darme siempre el mismo" lo da solo cada ronda. `/loadout` (o `/armas`, `/guns`) lo vuelve a abrir; el arma solo se puede cambiar sin haber salido de la zona de compra (si no, queda para la ronda siguiente). El equipo completo se da una vez por vida: cambiar de arma reemplaza la principal (hay que tenerla todavía) y no repone granadas, chaleco ni Deagle.
- **Rondas:** tiempos competitivos (1:45 de ronda, bomba de 35 s, 15 s de compra, $800) con freezetime 0; mapa de 30 minutos.
- **Mapa:** votación 3 minutos antes del final (5 mapas, sin repetir los últimos 3, más "Extender" hasta 2 veces). Si otro menú te tapa la votación, `/votar` la vuelve a abrir. `rtv` / `/rtv` con el 60% de los jugadores cambia de mapa enseguida (si se va gente, se vuelve a contar).
- **Bomba:** los segundos que quedan se muestran a los dos equipos (a los CT: "Apurate a desactivar la bomba, quedan N segundos"; a los T: "Defendé la bomba") y cuenta regresiva hablada de 10 a 1. A los T que quedan vivos, a los 20 segundos del final: "Apurate a plantar la bomba, dale".
- **Sonidos** (en `sound/claudia/anuncios/`; si falta alguno se omite): prepare to fight al empezar la ronda, primera sangre, humillación a cuchillo, double/multi/mega/ultra/monster kill, rachas de 3/5/7/10/15/20 kills (killing spree, rampage, dominating, unstoppable, godlike, wicked sick), headshot (solo para el que lo hizo) y flawless victory cuando un equipo gana sin perder a nadie. Cada jugador apaga o prende los sonidos de muertes con `/sonidos` (se recuerda por nick).
- **Calentamiento:** 1 minuto (`cp_warmup_time`) desde que entra el primer jugador en cada mapa; se revive al instante y las kills no cuentan para las stats ni para el modo de juego de Claudia. Al terminar se reinicia la ronda.
- **Daño:** debajo de la mira, el daño hecho en azul y el recibido en rojo, sin tope (un AWP muestra todo el daño, no 100). Al morir (o al empezar la ronda siguiente, si sobreviviste) se muestran tus víctimas con daño, impactos y tu precisión, y tus atacantes.
- **Revivir:** sobre el cuerpo de cada compañero muerto hay un cartel "REVIVIR" (solo lo ve su equipo). Manteniendo **E** 5 segundos al lado del cuerpo se lo revive ahí mismo, con el menú de armas. Tamaño y altura del cartel: `cp_revive_scale` y `cp_revive_height`.
- **Voz:** `sv_alltalk 1`, terroristas y anti se escuchan entre sí.
- **HUD:** cada tipo de mensaje tiene su fila (calentamiento, anuncios de kills, bomba, aviso de plantar, fin de ronda, resumen, daño) y los anuncios de kills salen de a uno, con su sonido, en cola.
- **Granadas:** el aviso por radio sale como `[HE]` (rojo), `[SG]` (verde) o `[FB]` (gris) + nick + mensaje; cada granada deja una estela de ese color que ven todos, y las flashes de los compañeros no ciegan.

Sin iniciar sesión no se puede entrar a ningún equipo (ni por menú ni por consola); al identificarse se abre el menú de equipos. Si el servicio está caído no se bloquea a nadie.

## Ajedrez (claudia_ajedrez)

- **Desafío:** `/ajedrez <nick> <apuesta>` (o Casino → Ajedrez en el menú). Al desafiado le aparece un menú para aceptar o rechazar (60 s). Al aceptar se retiene la apuesta de los dos y pasan a **espectador** hasta que termina la partida.
- **Partida:** 5 minutos por jugador, reglas completas (enroque, captura al paso, coronación, tablas por ahogado, repetición, 50 movimientos y material insuficiente). El tablero se abre en el MOTD; si lo cerrás, `/ajedrez volver`. Con el tablero cerrado más de 60 s, o saliendo del servidor, se pierde por abandono.
- **Pago:** el ganador se lleva el pozo (2 × apuesta) menos el 5 % de la casa; en tablas cada uno recupera su apuesta. Si el servicio se reinicia en medio de una partida, se devuelven las apuestas.
- **Pistas:** se pagan una vez (250) y valen para toda la partida: al elegir una pieza se marcan sus movimientos. El rival ve en el chat que las compraste.
- **Chat** al lado del tablero (con frases rápidas), **micrófono** (el botón le activa la voz del juego, `+voicerecord`, al propio jugador; se apaga solo a los 60 s) con **voz privada** entre los dos, **tablas** y **rendirse**.
- Todo se ajusta en `config/games/ajedrez.json`.

## Radio (claudia_radio)

Música por el chat de voz, pedida por los jugadores.

- `/radio <nombre o link de YouTube>` encola un tema (máximo 2 por jugador, 10 en la cola, 8 minutos por tema). `/radio cola`, `/radio sacar <n>`, `/radio saltar` (el que lo pidió o el staff lo cortan; el resto vota, hace falta la mitad) y `/radio on` / `/radio off` para escucharla o no (por defecto la escuchan todos; se recuerda por nick).
- El servicio baja el audio con **yt-dlp**, **ffmpeg** lo normaliza y lo convierte a wav mono de 16 kHz en trozos de 6 s dentro de `cstrike/radio/`, y el plugin los pasa por la voz con **VoiceTranscoder** (vía **ReAPI**). Cortar, saltar o apagar tarda como mucho un trozo.
- Instalación en el servidor de juego: `addons/VoiceTranscoder/` (con su línea en `addons/metamod/plugins.ini`), `reapi_amxx.dll` en `addons/amxmodx/modules/` y `claudia_radio.amxx`. Para compilar hacen falta los `.inc` de ReAPI en la carpeta `include` del compilador.
- En el servicio: rutas de yt-dlp, ffmpeg y de la carpeta `cstrike` del juego en `config/radio.json` (el servicio y el juego tienen que estar en la misma máquina, o compartir esa carpeta).
- La música de YouTube tiene derechos de autor: pasarla en un servidor público es responsabilidad de quien lo administra.

## Comandos

| Comando | Qué hace |
|---|---|
| `/registrar`, `/login`, `/cambiarclave` | Cuenta. La contraseña se escribe en la barra de messagemode y no aparece en el chat. |
| `/ayuda` | Lista de comandos (en la consola). |
| `/apodo <apodo>` | Cómo te llama Claudia. `/apodo borrar` lo quita. |
| `/perfil [nick]` | Tu perfil o el de otro jugador. Owner/staff ve todo, incluida la memoria de la IA. |
| `/top [economia\|juego]` | Rankings. |
| `/saldo`, `/transferir <nick> <monto>`, `/movimientos` | Economía. |
| `/bancos`, `/banco <id>`, `/prestamo <banco> <monto> <días>`, `/deuda`, `/pagar <banco\|todo> [monto]`, `/promos` | Bancos, préstamos y Clearing. |
| `/trabajos`, `/empleadores <trabajo>`, `/postular <trabajo> <empleador>`, `/trabajo`, `/cobrar`, `/renunciar` | Trabajos. |
| `/cumple DD/MM`, `/recordar <30m\|2h\|1d\|DD/MM [HH:MM]> <texto>`, `/recordatorios`, `/borrarrecordatorio <n>` | Recordatorios. |
| `/radio <tema o link>` | Pedir un tema en la radio (`cola`, `saltar`, `sacar <n>`, `on`, `off`). |
| `/ajedrez <nick> <apuesta>` | Desafiar al ajedrez (`aceptar`, `rechazar`, `volver`). |
| `/ruleta`, `/blackjack`, `/minas`, `/casino` | Casino (MOTD). Las ganancias y pérdidas grandes se anuncian en el chat (`economy.json` → `announce`). |
| `/chanchitos`, `/dulce`, `/materush`, `/slots` | Slots (MOTD): Los 3 Chanchitos del Banco, Dulce de Leche Bonanza y Mate Rush. |
| `/pareja <nick>`, `/aceptar`, `/rechazar`, `/mipareja [nick]`, `/terminar`, `/ex [nick]` | Parejas (una a la vez; se guardan las últimas 5 ex). |
| `/casarse`, `/si`, `/no` | Casamiento (hace falta un anillo de la tienda). `/si` y `/no` también responden adopciones. |
| `/adoptar <nick>`, `/familia [nick]`, `/apellido <texto>`, `/familias`, `/emancipar`, `/desheredar <nick>` | Familia: solo los casados adoptan (máximo 4 hijos). El árbol muestra pareja, padres, hijos, hermanos, abuelos, tíos y primos. |
| `/formarpareja`, `/besar <nick>`, `/siono <pregunta>` | Social: Claudia hace de celestina, besos y preguntas de sí o no. |
| `/tienda`, `/comprar <objeto>`, `/inventario` | Tienda (grupo propio, anillo). |
| `/creargrupo`, `/grupos`, `/grupo [nombre]`, `/miembros [nombre]`, `/unirse <nombre>`, `/salirg`, `/topgrupos`, `/g <mensaje>` | Grupos: el nombre, tag, descripción y privacidad se completan por chat. Los miembros hablan con `[TAG]Nick: mensaje`. |
| `/solicitudes`, `/aceptarg <nick>`, `/rechazarg <nick>`, `/expulsarg <nick>`, `/traspasarg <nick>`, `/editarg ...`, `/disolver` | Dueño del grupo. |
| `/donar <monto>`, `/fondo`, `/fondo dar <nick> <monto>` | Fondo común del grupo (cuotas cada 100 rondas, impuesto del 15% cada 300 rondas del dueño). |
| `/duelo <nick> <monto>`, `/aceptar_duelo`, `/rechazar_duelo` | Duelo por coins (1 a 30.000; los dos tienen que tenerlos). El que mate al otro se lleva el doble. Si uno muere por otra causa se devuelve todo; si uno se va, pierde. Máximo 5 duelos seguidos por pareja (se reinicia tras 1 h sin duelos). |
| `/racha` | Racha de kills: 2→5, 3→10, 5→20, 7→100, 10→200, 15→400, 20→1000 coins. Lo ganado va a un pozo que se pierde al morir; si te matan a cuchillo, el asesino te puede robar parte del pozo. Solo cuentan las muertes de jugadores con cuenta (los bots no suman, tampoco para el MVP ni el arma bonus). |
| `/mvp`, `/arma` | MVP de la ronda (más kills): 20 × 1,025^(racha−1) coins, hasta 100; matar al MVP anterior da +2. Arma bonus de la ronda: +5 por kill con ella. |
| `/bounty [nick monto]` | Recompensa por la cabeza de alguien (mínimo 100, se acumulan sobre el mismo jugador, una a la vez). Se cobra matándolo a cuchillo. |
| `/cancelar` | Cancela el formulario por chat que estés completando. |
| `amx_darcoins <nick> <monto>`, `amx_quitarcoins <nick> <monto>`, `amx_claudia_reload` | Admin (consola). |
| `/admingrupo <acción>` (chat, alias `/ag`) o `amx_grupo <acción>` (consola); atajos `amx_crearg <dueño> <tag> <nombre>` y `amx_borrarg <grupo>` | Admin de grupos: `crear`, `borrar`, `info`, `lista`, `renombrar`, `tag`, `desc`, `privacidad`, `dueno`, `agregar`, `expulsar`. Los admins crean gratis; al borrar, el fondo se reparte entre los miembros. |

Para hablar con Claudia, nombrala en el chat global o en el de muertos ("claudia", "clau", ...). También contesta si comentás algo en los 20 s siguientes a jugar en el casino.

## Tests

```bash
cd service
php vendor/bin/phpunit
```

En GitHub, cada push y pull request corre los tests (PHP 8.2 y 8.5) y compila los plugins (`.github/workflows/ci.yml`). Los plugins compilados quedan como artefacto `cstrike` de cada corrida, listos para copiar en la carpeta `cstrike` del servidor.

Para probar el servicio sin el juego está `tools/fake_plugin.php`, un cliente que habla el mismo protocolo que el plugin. Ejemplo: `php tools/fake_plugin.php --script=guion.txt`.

Más detalle en [docs/configuracion.md](docs/configuracion.md) y [docs/protocolo.md](docs/protocolo.md).

## Imágenes

<p align="center">
  <img src="assets/claudia-solaire.jpg" height="250" alt="Bender como Solaire de Dark Souls, con los brazos en alto al sol">
  <img src="assets/claudia-leyendo.jpg" height="250" alt="Bender leyendo un papel frente a una multitud">
  <img src="assets/claudia-sagrado-corazon.jpg" height="250" alt="Bender como la estampa del Sagrado Corazón">
</p>

<p align="center"><sub>Imágenes por <a href="https://github.com/TheShrekMaster">@TheShrekMaster</a>.</sub></p>

Y esta es la foto de perfil de Claudia en WhatsApp, en [ClaudIA](https://github.com/gauchitodev/ClaudIA):

<p align="center">
  <img src="assets/profile_picture_claudia.jpg" height="250" alt="Retrato de una mujer de pelo corto hecha de código verde brillante, estilo Matrix, dentro de un círculo sobre un fondo de caracteres que caen">
</p>

Las imágenes de `assets/` en las que aparece Bender, de *Futurama*, incluido el banner, las hizo [@TheShrekMaster](https://github.com/TheShrekMaster). No están cubiertas por la licencia MIT del código: los derechos de las imágenes son de su autor y los del personaje, de sus respectivos propietarios.

## Related Projects

- [ClaudIA](https://github.com/gauchitodev/ClaudIA), de [@gauchitodev](https://github.com/gauchitodev): bot de WhatsApp con personalidad uruguaya, hecho a medida para un grupo de amigos. ClaudIA-CS1.6 está basada conceptualmente en ese proyecto, y las imágenes de este README vienen de ahí.

## Licencia

El código está bajo la [licencia MIT](LICENSE). Las imágenes de `assets/` no: ver la nota de la sección [Imágenes](#imágenes).
