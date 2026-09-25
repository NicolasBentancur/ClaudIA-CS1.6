# Configuración

Hay dos lugares de configuración:

- **Plugin** (cvars en `addons/amxmodx/configs/claudia/claudia.cfg`): conexión, tiempo de login y flags de acceso.
- **Servicio** (`service/config/*.json`, separados por categoría): todo lo demás.

Las claves de los JSON que empiezan con `_` son comentarios. Después de editar un JSON, `amx_claudia_reload` lo recarga sin reiniciar. Los puertos y la base de datos solo cambian al reiniciar el servicio.

## claudia.cfg (plugin)

| cvar | por defecto | qué es |
|---|---|---|
| `claudia_host` / `claudia_port` | `127.0.0.1` / `27100` | Dónde escucha el servicio. |
| `claudia_secret` | - | Secreto compartido (igual que `plugin_secret` en `secrets.json`). |
| `claudia_login_timeout` | `30` | Segundos para identificarse antes del kick. `0` = no expulsar. Si el servicio no responde no se expulsa a nadie. |
| `claudia_admin_flags` | `d` | Flags del rol **admin** (alcanza con una). |
| `claudia_staff_flags` | `m` | Flags del rol **staff**. |
| `claudia_owner_flags` | `l` | Flags del rol **owner**. Se usa el rol más alto que tenga el jugador; la consola del servidor es owner. |

## roles.json

Roles: jugador < admin < staff < owner. Cada rol tiene todo lo del anterior. `permissions` dice el rol mínimo de cada acción de administración (menú `/admin`, `/admingrupo` y comandos de consola); el servicio lo verifica siempre. Además, salvo el owner, nadie puede actuar sobre un jugador conectado de su mismo rango o superior.

| Por defecto | Permisos |
|---|---|
| admin | `admin.menu`, `admin.amxmenu`, `announce`, `players.profile`, `players.nickname`, `groups.view`, `groups.edit`, `groups.members` |
| staff | `players.profile_full` (memoria de la IA, préstamos, movimientos), `players.memory`, `groups.create`, `groups.delete`, `groups.owner`, `coins.give`, `coins.take`, `ai.toggle` |
| owner | `players.password` (contraseña temporal), `loans.forgive`, `promos.force`, `config.reload`, `service.status` |

`coin_limits`: tope de coins por cada operación de dar o quitar, por rol (0 = sin tope). Por defecto staff 10.000 y owner sin tope.

## service.json

| Clave | Qué es |
|---|---|
| `plugin` | Puerto del socket local. El secreto va en `secrets.json` (`plugin_secret`); `plugin.secret` acá es solo un respaldo. |
| `http` / `ws` | Puertos de las páginas y del WebSocket. `public_host` vacío = usa la IP del servidor de CS. |
| `session_resume_seconds` | Cuánto dura la sesión reanudable tras un cambio de mapa. |
| `auth` | Largo de contraseña, intentos fallidos y bloqueo, nicks que no se pueden registrar. |
| `nickname` | Largo máximo y palabras prohibidas de `/apodo`. |
| `games.idle_seconds` | Cuándo se cierra una mesa inactiva. |
| `debug.time_offset_seconds` | Adelanta el reloj para probar vencimientos y sueldos. **0 en producción.** |

## ai.json + personality.md

- `personality.md`: la personalidad de Claudia (el prompt de sistema). Editalo libremente.
- `mention_keywords`: palabras que cuentan como mención directa o acortada. Se comparan como palabra completa, sin importar mayúsculas ni tildes.
- `cooldown_seconds` (25): el jugador no dispara otra petición a la IA si en ese tiempo obtuvo una respuesta o mencionó a Claudia. Puede seguir chateando.
- `global_limit_per_minute` (5): tope total de peticiones por minuto.
- `recent_game_seconds` (20): si el jugador jugó en el casino hace menos de esto, su comentario dispara la IA, y el resumen del juego se agrega al contexto.
- `history_messages` (15): mensajes previos que se mandan como contexto.
- `models`: orden de fallback. Cada modelo tiene su `timeout`, y los de Groq aceptan `extra` para parámetros específicos del modelo.
- `on_all_fail`: `silence` (no responde) o `message` (manda `all_fail_message`).
- `memory.summary_threshold_words` (3000): cuando la memoria de un usuario lo supera, la IA la resume.

La IA devuelve JSON estructurado con estos campos: `responder`, `respuesta`, `pensamiento`, `trato` y `datos`. El pensamiento, el trato y los datos se guardan como memoria del jugador.

## economy.json

- `bet.min` / `bet.max`: límites por jugada en el casino (10 y 10.000).
- `chips`: denominaciones de las fichas. Se muestran `count` fichas y la mayor es como mucho `balance_fraction` del saldo.
- `clearing.days_overdue` (5) y `clearing.confiscation_rate` (0.5).
- `transfer`: monto mínimo y comisión.
- `ranking.game_score`: pesos del ranking de juego.

## banks.json

Cada banco tiene estos campos:

- `liquidity`: fondos que tiene para prestar. Se regenera `liquidity_regen_per_day` por día.
- `min_amount` / `max_amount`.
- `unemployed_max`: tope para desempleados. `null` = no les presta.
- `salary_multiplier`: tope igual al sueldo diario por este número.
- `terms`: plazos en días y la tasa total de cada uno.
- `late_daily_rate`: recargo diario compuesto sobre lo adeudado.
- `max_active_loans`.
- `requirements`: `requires_job`, `min_job_level`, `min_playtime_hours`.

Los plazos corren en tiempo real, aunque el jugador esté desconectado.

## promotions.json

Cada `roll_interval_hours` se sortea cada promoción inactiva según su `probability`. Si sale, queda activa `duration_hours` para **todos** los usuarios.

Efectos posibles:

- `bank_rate`, `bank_max`, `bank_late_rate`: con `bank` igual al id del banco o `*` para todos.
- `salary`
- `bonus_chance`

## jobs.json

- **Por trabajo:** `requirements` (`min_playtime_hours`, `min_kills`, `min_coins`), `employers`, `levels` y `fire`.
- **Por empleador:** multiplicadores `salary_multiplier`, `bonus_multiplier`, `promotion_multiplier` y `fire_multiplier`.
- **Por nivel:** `salary`, `bonus {chance, min, max}` y `promote {min_days, min_performance}`.
- **Rendimiento:** mezcla la actividad desde el último cobro con azar. Se configura en `performance`: `weights` define los pesos de minutos jugados, kills y mensajes, `target_activity` la actividad que cuenta como completa y `random_weight` cuánto pesa el azar.
- **Reingreso:** si te echan o renunciás y volvés al mismo trabajo, entrás `rehire_level_penalty` o `resign_level_penalty` niveles por debajo del que tenías.

## games/ruleta.json y games/blackjack.json

- **Ruleta:** `fps` (30) y `fallback_fps` (15), que se usa cuando hay más de `max_spins_at_full_fps` giros al mismo tiempo. También los sonidos.
- **Blackjack:** reglas (mazos, S17, pago del blackjack, divisiones, doblar después de dividir) y sonidos.

## social.json

Parejas, casamientos, adopciones y familias.

- `proposal_seconds`: cuánto dura una propuesta de pareja, casamiento o adopción sin respuesta.
- `max_children` (4): hijos adoptados por familia. `max_ex` (5): ex parejas que se guardan por jugador.
- `marriage.requires_ring`: si es true, `/casarse` necesita un anillo de la tienda (`ring_item`), que se gasta cuando aceptan.
- `kiss`, `cupid`, `yes_no`: tiempos de espera y frases de `/besar`, `/formarpareja` y `/siono`. En las frases, `{a}`, `{b}` y `{c}` se reemplazan por nicks.

## groups.json

- `price` (10.000): lo que cuesta fundar un grupo. Se cobra al confirmar el formulario y sale de circulación.
- `max_members` (16), largos de nombre, tag y descripción, y `tag_extra_chars` (símbolos permitidos en el tag).
- `fee`: cada `rounds` (100) rondas jugadas, cada miembro que no es el dueño pone `amount` (25) en el fondo. Si no le alcanza, no se cobra y se le avisa al dueño.
- `tax`: cada `rounds` (300) rondas que juega el dueño, se quema el `rate` (15%) del fondo.
- El ranking de grupos es solo por kills (cosmético): nunca se crean coins. El fondo se llena únicamente con cuotas y donaciones.
- Al disolver un grupo, el fondo se reparte en partes iguales entre los miembros.

## combat.json

Modo de juego (duelos, rachas, MVP, arma bonus y recompensas).

- `duel`: `min`/`max` de la apuesta, `accept_seconds` para aceptar, `pair_cap` (5) duelos seguidos por pareja y `pair_reset_seconds` (3600) sin duelos para reiniciar el contador. Las apuestas en juego se guardan en la base: si el servicio se reinicia, se devuelven.
- `streak.rewards`: kills seguidas → coins (se acreditan al momento y se suman al pozo de la racha). `streak.knife_steal`: tabla `[probabilidad %, fracción del pozo]` del robo a cuchillo (el robo es entero, mínimo 1, y se descuenta del saldo de la víctima).
- `mvp`: `base` × `multiplier`^(racha de MVP − 1), redondeado; `killer_bonus` por matar al MVP de la ronda anterior.
- `weapon_bonus`: `amount` por kill y la lista de armas (nombres del DeathMsg: `ak47`, `m4a1`, `awp`...).
- `bounty.min`: recompensa mínima.

## shop.json

Objetos de `/tienda`. `type: "grupo"` abre el formulario para crear un grupo (el precio sale de `groups.json`). `type: "item"` se guarda en el inventario (`price` y `max` por jugador).

## Slots: games/chanchitos.json, games/dulce.json y games/materush.json

Cada slot tiene estos campos:

- `name`: el nombre que se muestra.
- `bet_levels`: apuestas disponibles. Se filtran por los límites de `economy.bet`.
- `max_win`: tope del premio, en veces la apuesta.
- `buy`: compras de bonus. Cada una tiene `enabled` y `price` en veces la apuesta.
- `sounds`: sonidos por evento. La página los pide sincronizados con la animación.
- `math`: pesos, tablas de pago y funciones. **Esto define el retorno (RTP).**

Las tablas vienen ajustadas a ~96% de RTP. Si cambiás algo en `math`, volvé a medir:

```bash
php tools/slot_sim.php chanchitos 1000000            # RTP del juego normal
php tools/slot_sim.php dulce 30000 --buy=giros       # valor de la compra y precio sugerido
```

- **Precio de la compra:** tiene que ser igual a su valor esperado dividido por el RTP buscado. El simulador lo sugiere.
- **Varianza:** los slots volátiles, como Mate Rush, necesitan millones de giros para una medición precisa. Conviene medir el juego base y la compra por separado, y combinar: RTP ≈ base + (valor del bonus / frecuencia del bonus).

Qué controla el `math` de cada slot:

- **Los 3 Chanchitos del Banco**
  - `wild`: rodillos, peso, tamaños y multiplicadores del comodín.
  - `scatter`: casitas y giros gratis.
  - `coins`: monedas del lobo y chanchitos. Con `trigger` monedas y al menos un chanchito arranca el bonus.
  - `hold`: el bonus Candado y Carga (re-giros, probabilidad por casilla, valores, jackpots y Grand).
- **Dulce de Leche Bonanza**
  - `min_count`: cantidad mínima de símbolos iguales para cobrar.
  - `symbols`: pago según la cantidad.
  - `scatter`: chupetines.
  - `bomb`: bombones multiplicadores.
  - `fs_weights`: pesos de los símbolos solo durante los giros gratis.
- **Mate Rush**
  - `min_cluster`: tamaño mínimo del cluster.
  - `cells`: tope de los multiplicadores de casilla (base y giros gratis) y el valor inicial de los súper giros.
  - `rush`: rayos.
  - `electric`: Chispazo.
  - `sync`: sincronización.
  - `bonus`: soles y giros gratis.

Si agregás o cambiás sonidos, sumalos también a `configs/claudia/sounds.ini` para que se precacheen y se descarguen.
