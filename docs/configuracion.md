# Configuración

Hay dos lugares de configuración:

- **Plugin** (cvars en `addons/amxmodx/configs/claudia/claudia.cfg`): conexión, tiempo de login y flags de acceso.
- **Servicio** (`service/config/*.json`, separados por categoría): todo lo demás.

Las claves de los JSON que empiezan con `_` son comentarios. Después de editar un JSON, `amx_claudia_reload` lo recarga sin reiniciar. Los puertos y la base de datos solo cambian al reiniciar el servicio.

## claudia.cfg (plugin)

| cvar | por defecto | qué es |
|---|---|---|
| `claudia_host` / `claudia_port` | `127.0.0.1` / `27100` | Dónde escucha el servicio. |
| `claudia_secret` | - | Secreto compartido (igual que `plugin.secret` en `service.json`). |
| `claudia_login_timeout` | `30` | Segundos para identificarse antes del kick. `0` = no expulsar. Si el servicio no responde no se expulsa a nadie. |
| `claudia_staff_flags` | `l` | Owner/staff: ven el perfil completo de cualquiera. |
| `claudia_admin_flags` | `l` | Comandos de admin (`amx_darcoins`, `amx_quitarcoins`, `amx_claudia_reload`). |

## service.json

| Clave | Qué es |
|---|---|
| `plugin` | Puerto y secreto del socket local. |
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

Si agregás o cambiás sonidos, sumalos también a `configs/claudia/sounds.ini` para que se precacheen y se descarguen.
