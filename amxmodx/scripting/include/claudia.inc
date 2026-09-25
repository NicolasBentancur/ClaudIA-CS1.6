// Claudia - API del plugin núcleo (claudia_core) para los demás plugins.

#if defined _claudia_included
	#endinput
#endif
#define _claudia_included

#include <json>

#pragma reqlib claudia_core
#if !defined AMXMODX_NOAUTOLOAD
	#pragma loadlib claudia_core
#endif

#define CLAUDIA_TAG "^4[Claudia]^1"

/**
 * Devuelve true si hay conexión (con handshake completo) con el servicio de Claudia.
 */
native bool:claudia_connected();

/**
 * Envía un mensaje al servicio.
 *
 * @param type       Tipo de mensaje del protocolo (ej. "auth.login").
 * @param data       Objeto JSON con los datos. Se libera siempre dentro del native
 *                   (queda en Invalid_JSON). Puede ser Invalid_JSON para mandar {}.
 * @param callback   Función pública de TU plugin que recibe la respuesta, o "" si no hace falta:
 *                     public callback(bool:ok, JSON:data, const error[], const message[], extra)
 *                   "data" se libera después de llamar al callback (no lo liberes vos).
 * @param extra      Valor que se devuelve tal cual al callback (ej. el id del jugador).
 *
 * @return           true si se encoló el mensaje, false si no hay conexión.
 */
native bool:claudia_send(const type[], &JSON:data = Invalid_JSON, const callback[] = "", extra = 0);

/**
 * Estado de autenticación de un jugador según el servicio.
 */
native bool:claudia_is_logged(id);
native bool:claudia_is_registered(id);

/**
 * Se conectó o se perdió la conexión con el servicio.
 */
forward claudia_connection_changed(bool:connected);

/**
 * Cambió el estado de autenticación de un jugador.
 */
forward claudia_auth_changed(id, bool:registered, bool:logged);

/**
 * Un jugador escribió un comando de chat (/algo o !algo). "cmd" viene en minúsculas y sin la barra.
 * Devolvé PLUGIN_HANDLED si lo manejaste (se oculta del chat y no se manda al servicio).
 */
forward claudia_say_command(id, const cmd[], const args[]);

/**
 * El jugador se está desconectando. Se llama ANTES de avisarle al servicio, para que los
 * plugins puedan mandar sus últimos datos (ej. estadísticas).
 */
forward claudia_player_leaving(id);

/**
 * Evento del servicio que el núcleo no maneja por sí mismo.
 */
forward claudia_event(const type[], JSON:data);
