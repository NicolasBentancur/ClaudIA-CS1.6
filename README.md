# Claudia

Plugin de Counter-Strike 1.6 (AMX Mod X 1.10 + ReHLDS) con una IA de personalidad uruguaya en el chat, cuentas con contraseña, economía en URU Coins (bancos, préstamos y Clearing), trabajos, perfiles, rankings, recordatorios y un casino (ruleta francesa y blackjack) que se juega en la ventana MOTD.

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
service/public/juegos/    páginas MOTD (ruleta, blackjack)
service/tests/            PHPUnit
docs/                     protocolo y configuración
```

## Instalación

### 1. Servicio (Windows o Linux)

Requisitos: PHP 8.2 o superior con las extensiones `curl`, `mbstring`, `pdo_sqlite`, `openssl` y `sockets`, y Composer.

```bash
cd service
composer install --no-dev
cp config/secrets.example.json config/secrets.json   # y completá las API keys
```

1. En `config/service.json` cambiá `plugin.secret` por un secreto propio.
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
3. Editá `addons/amxmodx/configs/claudia/claudia.cfg` y poné en `claudia_secret` el mismo secreto que en `service.json`.
4. Verificá que en `addons/amxmodx/configs/modules.ini` estén habilitados los módulos `sockets`, `json`, `cstrike`, `fakemeta` y `hamsandwich`.

`plugins-claudia.ini` se carga solo. `claudia_core` tiene que ir primero.

Para ver el estado de la conexión, escribí `claudia_status` en la consola del servidor.

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
| `/ruleta`, `/blackjack`, `/casino` | Casino (MOTD). |
| `amx_darcoins <nick> <monto>`, `amx_quitarcoins <nick> <monto>`, `amx_claudia_reload` | Admin (consola). |

Para hablar con Claudia, nombrala en el chat global o en el de muertos ("claudia", "clau", ...). También contesta si comentás algo en los 20 s siguientes a jugar en el casino.

## Tests

```bash
cd service
php vendor/bin/phpunit
```

Para probar el servicio sin el juego está `tools/fake_plugin.php`, un cliente que habla el mismo protocolo que el plugin. Ejemplo: `php tools/fake_plugin.php --script=guion.txt`.

Más detalle en [docs/configuracion.md](docs/configuracion.md) y [docs/protocolo.md](docs/protocolo.md).
