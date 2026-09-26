/*
 * Claudia - Núcleo
 *
 * Mantiene la conexión TCP con el servicio de Claudia (PHP) y traduce entre el juego y el
 * protocolo (una línea JSON por mensaje). Se encarga de:
 *   - handshake, reconexión y cola de pedidos con respuesta
 *   - avisar entradas/salidas/cambios de nick de los jugadores
 *   - mandar el chat global y de muertos (no el de equipo) para la IA
 *   - reenviar los comandos de chat de Claudia (/perfil, /ruleta, ...) y ocultarlos del chat
 *   - mostrar el tag del grupo en el chat ([TAG]nick) y capturar las respuestas de los formularios por chat
 *   - mostrar los menús de HUD que arma el servicio (/menu) y pedir textos con messagemode
 *   - calcular el rol de cada jugador (jugador/admin/staff/owner) con las flags de AMXX
 *   - ejecutar los eventos del servicio: mensajes, sonidos para un jugador y ventanas MOTD
 *
 * Los demás plugins de Claudia usan la API de include/claudia.inc.
 */

#include <amxmodx>
#include <amxmisc>
#include <sockets>
#include <json>

#pragma semicolon 1
#pragma dynamic 16384

#define PLUGIN  "Claudia Core"
#define VERSION "1.0.0"
#define AUTHOR  "Claudia"

#define CLAUDIA_TAG "^4[Claudia]^1"

#define TASK_POLL       71001
#define TASK_RECONNECT  71002

#define POLL_INTERVAL        0.1
#define CONNECT_TIMEOUT      3.0
#define RECONNECT_DELAY      5.0
#define HANDSHAKE_TIMEOUT    5.0

#define RECV_MAX     32768
#define SEND_MAX     32768
#define LINE_MAX     8192
#define CHUNK_MAX    4096
#define MAX_PENDING  128

enum
{
	STATE_DISCONNECTED = 0,
	STATE_CONNECTING,
	STATE_HANDSHAKE,
	STATE_READY
};

enum
{
	KIND_FREE = 0,
	KIND_EXTERNAL,
	KIND_HELLO,
	KIND_JOIN
};

new g_State = STATE_DISCONNECTED;
new g_Socket;
new Float:g_StateSince;

new g_RecvBuf[RECV_MAX];
new g_RecvLen;
new g_SendBuf[SEND_MAX];
new g_SendLen;
new g_Line[LINE_MAX];
new g_Chunk[CHUNK_MAX];
new g_Text[1024];
new g_SayText[256];
new g_ChatLine[192];

new g_NextId;
new g_PendId[MAX_PENDING];
new g_PendKind[MAX_PENDING];
new g_PendFwd[MAX_PENDING];
new g_PendExtra[MAX_PENDING];

new bool:g_Registered[MAX_PLAYERS + 1];
new bool:g_Logged[MAX_PLAYERS + 1];
new bool:g_Joined[MAX_PLAYERS + 1];
new g_Name[MAX_PLAYERS + 1][MAX_NAME_LENGTH];
new g_Tag[MAX_PLAYERS + 1][16];
new bool:g_Capture[MAX_PLAYERS + 1];

new Trie:g_Commands;

new g_Host[64];
new g_Port;
new g_Secret[128];
new g_StaffFlags[32];
new g_AdminFlags[32];
new g_OwnerFlags[32];

// Menú de HUD abierto por jugador: handle de AMXX e id del servicio.
new g_Menu[MAX_PLAYERS + 1] = { -1, ... };
new g_MenuId[MAX_PLAYERS + 1];
new g_MenuTitle[512];
new g_MenuItem[128];

new g_fwdConnection;
new g_fwdAuthChanged;
new g_fwdSayCommand;
new g_fwdLeaving;
new g_fwdEvent;

public plugin_natives()
{
	register_library("claudia_core");
	register_native("claudia_connected", "native_connected");
	register_native("claudia_send", "native_send");
	register_native("claudia_is_logged", "native_is_logged");
	register_native("claudia_is_registered", "native_is_registered");
	register_native("claudia_role", "native_role");
}

public plugin_precache()
{
	// Sonidos que el servicio puede pedir reproducir (configs/claudia/sounds.ini).
	new path[256];
	get_configsdir(path, charsmax(path));
	add(path, charsmax(path), "/claudia/sounds.ini");
	new file = fopen(path, "rt");
	if (!file)
	{
		log_amx("[Claudia] No se encontró %s, no se precachean sonidos.", path);
		return;
	}
	new line[128];
	while (fgets(file, line, charsmax(line)))
	{
		trim(line);
		if (!line[0] || line[0] == ';' || (line[0] == '/' && line[1] == '/'))
		{
			continue;
		}
		add(line, charsmax(line), ".wav");
		precache_sound(line);
	}
	fclose(file);
}

public plugin_init()
{
	register_plugin(PLUGIN, VERSION, AUTHOR);
	register_dictionary("claudia.txt");
	register_cvar("claudia_version", VERSION, FCVAR_SERVER | FCVAR_SPONLY);

	bind_pcvar_string(create_cvar("claudia_host", "127.0.0.1", FCVAR_PROTECTED, "IP del servicio de Claudia"), g_Host, charsmax(g_Host));
	bind_pcvar_num(create_cvar("claudia_port", "27100", FCVAR_PROTECTED, "Puerto TCP del servicio de Claudia"), g_Port);
	bind_pcvar_string(create_cvar("claudia_secret", "CAMBIAR-ESTE-SECRETO", FCVAR_PROTECTED, "Secreto compartido con el servicio"), g_Secret, charsmax(g_Secret));
	bind_pcvar_string(create_cvar("claudia_admin_flags", "d", _, "Flags del rol admin (alcanza con una)"), g_AdminFlags, charsmax(g_AdminFlags));
	bind_pcvar_string(create_cvar("claudia_staff_flags", "m", _, "Flags del rol staff (alcanza con una)"), g_StaffFlags, charsmax(g_StaffFlags));
	bind_pcvar_string(create_cvar("claudia_owner_flags", "l", _, "Flags del rol owner (alcanza con una)"), g_OwnerFlags, charsmax(g_OwnerFlags));

	register_clcmd("say", "cmd_say");
	register_clcmd("say_team", "cmd_say_team");
	register_clcmd("claudia_menu", "cmd_menu", _, "- abre el menú de Claudia (bindealo a una tecla)");
	register_clcmd("claudia_input", "cmd_input");
	register_srvcmd("claudia_status", "cmd_status");

	g_Commands = TrieCreate();

	g_fwdConnection = CreateMultiForward("claudia_connection_changed", ET_IGNORE, FP_CELL);
	g_fwdAuthChanged = CreateMultiForward("claudia_auth_changed", ET_IGNORE, FP_CELL, FP_CELL, FP_CELL);
	g_fwdSayCommand = CreateMultiForward("claudia_say_command", ET_STOP, FP_CELL, FP_STRING, FP_STRING);
	g_fwdLeaving = CreateMultiForward("claudia_player_leaving", ET_IGNORE, FP_CELL);
	g_fwdEvent = CreateMultiForward("claudia_event", ET_IGNORE, FP_STRING, FP_CELL);
}

public plugin_cfg()
{
	new path[256];
	get_configsdir(path, charsmax(path));
	server_cmd("exec %s/claudia/claudia.cfg", path);
	server_exec();

	set_task(POLL_INTERVAL, "task_poll", TASK_POLL, _, _, "b");
	connect_service();
}

public plugin_end()
{
	if (g_State != STATE_DISCONNECTED)
	{
		socket_close(g_Socket);
	}
	TrieDestroy(g_Commands);
}

/* =========================================================================
 * Conexión
 * ========================================================================= */

connect_service()
{
	if (g_State != STATE_DISCONNECTED)
	{
		return;
	}
	new error;
	g_Socket = socket_open(g_Host, g_Port, SOCKET_TCP, error, SOCK_NON_BLOCKING);
	// En modo no bloqueante el módulo devuelve un socket válido con error = SOCK_ERROR_WHILE_CONNECTING
	// cuando la conexión quedó "en progreso". Solo falló si no hay socket.
	if (g_Socket <= 0 || (error != SOCK_ERROR_OK && error != SOCK_ERROR_WHILE_CONNECTING))
	{
		log_amx("[Claudia] No se pudo abrir el socket hacia %s:%d (error %d). Reintento en %.0f s.", g_Host, g_Port, error, RECONNECT_DELAY);
		schedule_reconnect();
		return;
	}
	g_State = STATE_CONNECTING;
	g_StateSince = get_gametime();
	g_RecvLen = 0;
	g_SendLen = 0;
}

schedule_reconnect()
{
	remove_task(TASK_RECONNECT);
	set_task(RECONNECT_DELAY, "task_reconnect", TASK_RECONNECT);
}

public task_reconnect()
{
	connect_service();
}

disconnect_service(const reason[])
{
	if (g_State == STATE_DISCONNECTED)
	{
		return;
	}
	new wasReady = (g_State == STATE_READY);
	socket_close(g_Socket);
	g_State = STATE_DISCONNECTED;
	g_RecvLen = 0;
	g_SendLen = 0;
	fail_pending();
	for (new i = 1; i <= MAX_PLAYERS; i++)
	{
		g_Joined[i] = false;
		g_Capture[i] = false;
	}
	if (wasReady)
	{
		log_amx("[Claudia] Desconectado del servicio: %s", reason);
		ExecuteForward(g_fwdConnection, _, false);
	}
	schedule_reconnect();
}

public task_poll()
{
	switch (g_State)
	{
		case STATE_CONNECTING:
		{
			if (socket_is_writable(g_Socket, 0))
			{
				g_State = STATE_HANDSHAKE;
				g_StateSince = get_gametime();
				send_hello();
			}
			else if (get_gametime() - g_StateSince > CONNECT_TIMEOUT)
			{
				socket_close(g_Socket);
				g_State = STATE_DISCONNECTED;
				schedule_reconnect();
			}
		}
		case STATE_HANDSHAKE, STATE_READY:
		{
			if (g_State == STATE_HANDSHAKE && get_gametime() - g_StateSince > HANDSHAKE_TIMEOUT)
			{
				disconnect_service("sin respuesta al handshake");
				return;
			}
			flush_send();
			read_socket();
		}
	}
}

read_socket()
{
	// Lee todo lo disponible sin bloquear.
	new guard = 32;
	while (g_State != STATE_DISCONNECTED && guard-- > 0 && socket_is_readable(g_Socket, 0))
	{
		new n = socket_recv(g_Socket, g_Chunk, charsmax(g_Chunk));
		if (n <= 0)
		{
			disconnect_service(n == 0 ? "conexión cerrada" : "error de lectura");
			return;
		}
		if (g_RecvLen + n >= RECV_MAX)
		{
			log_amx("[Claudia] Buffer de recepción lleno, se descarta.");
			g_RecvLen = 0;
			return;
		}
		for (new i = 0; i < n; i++)
		{
			g_RecvBuf[g_RecvLen++] = g_Chunk[i];
		}
		process_lines();
	}
}

process_lines()
{
	new start = 0;
	for (new i = 0; i < g_RecvLen; i++)
	{
		if (g_RecvBuf[i] != '^n')
		{
			continue;
		}
		new len = i - start;
		if (len > 0 && len < LINE_MAX)
		{
			for (new j = 0; j < len; j++)
			{
				g_Line[j] = g_RecvBuf[start + j];
			}
			g_Line[len] = EOS;
			handle_line();
			if (g_State == STATE_DISCONNECTED)
			{
				return;
			}
		}
		else if (len >= LINE_MAX)
		{
			log_amx("[Claudia] Línea demasiado larga (%d bytes), se descarta.", len);
		}
		start = i + 1;
	}
	// Mueve lo que quedó sin terminar al principio del buffer.
	if (start > 0)
	{
		new rest = g_RecvLen - start;
		for (new k = 0; k < rest; k++)
		{
			g_RecvBuf[k] = g_RecvBuf[start + k];
		}
		g_RecvLen = rest;
	}
}

flush_send()
{
	if (g_SendLen <= 0)
	{
		return;
	}
	new sent = socket_send2(g_Socket, g_SendBuf, g_SendLen);
	if (sent <= 0)
	{
		return;
	}
	new rest = g_SendLen - sent;
	for (new k = 0; k < rest; k++)
	{
		g_SendBuf[k] = g_SendBuf[sent + k];
	}
	g_SendLen = rest;
}

/* =========================================================================
 * Envío de mensajes
 * ========================================================================= */

/**
 * Encola un mensaje. Libera "data". Devuelve el id asignado (0 si no espera respuesta), -1 si falló.
 */
send_message(const type[], JSON:data, kind, fwd = -1, extra = 0)
{
	new bool:isHello = bool:(kind == KIND_HELLO);
	if (g_State != STATE_READY && !(isHello && g_State == STATE_HANDSHAKE))
	{
		if (data != Invalid_JSON)
		{
			json_free(data);
		}
		if (fwd != -1)
		{
			DestroyForward(fwd);
		}
		return -1;
	}

	new id = 0;
	if (kind != KIND_FREE)
	{
		new slot = alloc_pending();
		id = ++g_NextId;
		g_PendId[slot] = id;
		g_PendKind[slot] = kind;
		g_PendFwd[slot] = fwd;
		g_PendExtra[slot] = extra;
	}

	new JSON:msg = json_init_object();
	json_object_set_number(msg, "id", id);
	json_object_set_string(msg, "type", type);
	if (data == Invalid_JSON)
	{
		data = json_init_object();
	}
	json_object_set_value(msg, "data", data);
	json_free(data);

	new len = json_serial_to_string(msg, g_Line, charsmax(g_Line) - 1);
	json_free(msg);
	if (len <= 0)
	{
		log_amx("[Claudia] No se pudo serializar un mensaje '%s'.", type);
		return -1;
	}
	if (g_SendLen + len + 1 >= SEND_MAX)
	{
		log_amx("[Claudia] Buffer de envío lleno, se descarta '%s'.", type);
		return -1;
	}
	for (new i = 0; i < len; i++)
	{
		g_SendBuf[g_SendLen++] = g_Line[i];
	}
	g_SendBuf[g_SendLen++] = '^n';
	flush_send();
	return id;
}

alloc_pending()
{
	for (new i = 0; i < MAX_PENDING; i++)
	{
		if (g_PendKind[i] == KIND_FREE)
		{
			return i;
		}
	}
	// Sin lugar: se descarta el más viejo.
	new oldest = 0;
	for (new i = 1; i < MAX_PENDING; i++)
	{
		if (g_PendId[i] < g_PendId[oldest])
		{
			oldest = i;
		}
	}
	complete_pending(oldest, false, Invalid_JSON, "timeout", "Sin respuesta del servicio");
	return oldest;
}

fail_pending()
{
	for (new i = 0; i < MAX_PENDING; i++)
	{
		if (g_PendKind[i] != KIND_FREE)
		{
			complete_pending(i, false, Invalid_JSON, "disconnected", "Se perdió la conexión con Claudia");
		}
	}
}

complete_pending(slot, bool:ok, JSON:data, const error[], const message[])
{
	new kind = g_PendKind[slot];
	new fwd = g_PendFwd[slot];
	new extra = g_PendExtra[slot];
	g_PendKind[slot] = KIND_FREE;
	g_PendId[slot] = 0;
	g_PendFwd[slot] = -1;

	switch (kind)
	{
		case KIND_EXTERNAL:
		{
			if (fwd != -1)
			{
				ExecuteForward(fwd, _, ok, data, error, message, extra);
				DestroyForward(fwd);
			}
		}
		case KIND_HELLO:
		{
			if (g_State == STATE_HANDSHAKE)
			{
				on_hello(ok, data, message);
			}
		}
		case KIND_JOIN:
		{
			if (ok && data != Invalid_JSON)
			{
				set_auth_state(extra, json_object_get_bool(data, "registered"), json_object_get_bool(data, "logged"));
			}
		}
	}
}

send_hello()
{
	new JSON:data = json_init_object();
	new ip[64], map[64], host[128];
	get_user_ip(0, ip, charsmax(ip), 1);
	get_mapname(map, charsmax(map));
	get_cvar_string("hostname", host, charsmax(host));
	json_object_set_string(data, "secret", g_Secret);
	json_object_set_string(data, "version", VERSION);
	json_object_set_string(data, "ip", ip);
	json_object_set_string(data, "map", map);
	json_object_set_string(data, "server", host);
	send_message("hello", data, KIND_HELLO);
}

on_hello(bool:ok, JSON:data, const message[])
{
	if (!ok)
	{
		log_amx("[Claudia] El servicio rechazó la conexión: %s. Revisá claudia_secret.", message);
		disconnect_service("handshake rechazado");
		return;
	}
	TrieClear(g_Commands);
	new JSON:cmds = json_object_get_value(data, "commands");
	if (cmds != Invalid_JSON)
	{
		new name[64];
		new count = json_array_get_count(cmds);
		for (new i = 0; i < count; i++)
		{
			json_array_get_string(cmds, i, name, charsmax(name));
			TrieSetCell(g_Commands, name, 1);
		}
		json_free(cmds);
	}
	g_State = STATE_READY;
	log_amx("[Claudia] Conectado al servicio en %s:%d.", g_Host, g_Port);
	ExecuteForward(g_fwdConnection, _, true);

	new players[MAX_PLAYERS], num;
	get_players(players, num, "ch");
	for (new i = 0; i < num; i++)
	{
		send_join(players[i]);
	}
}

send_join(id)
{
	new JSON:data = json_init_object();
	new ip[32], authid[64];
	get_user_ip(id, ip, charsmax(ip), 1);
	get_user_authid(id, authid, charsmax(authid));
	get_user_name(id, g_Name[id], charsmax(g_Name[]));
	json_object_set_number(data, "slot", id);
	json_object_set_string(data, "nick", g_Name[id]);
	json_object_set_string(data, "ip", ip);
	json_object_set_string(data, "authid", authid);
	// #userid del motor: se conserva en el cambio de mapa y el servicio lo exige para reanudar la sesión.
	json_object_set_number(data, "userid", get_user_userid(id));
	json_object_set_bool(data, "was_logged", g_Logged[id]);
	json_object_set_number(data, "role", get_role(id));
	if (send_message("player.join", data, KIND_JOIN, -1, id) >= 0)
	{
		g_Joined[id] = true;
	}
}

set_auth_state(id, bool:registered, bool:logged)
{
	if (id < 1 || id > MAX_PLAYERS || !is_user_connected(id))
	{
		return;
	}
	g_Registered[id] = registered;
	g_Logged[id] = logged;
	if (!logged)
	{
		g_Tag[id][0] = EOS;
		g_Capture[id] = false;
	}
	ExecuteForward(g_fwdAuthChanged, _, id, registered, logged);
}

/* =========================================================================
 * Mensajes del servicio
 * ========================================================================= */

handle_line()
{
	new JSON:msg = json_parse(g_Line);
	if (msg == Invalid_JSON)
	{
		log_amx("[Claudia] Mensaje inválido del servicio.");
		return;
	}
	new id = json_object_get_number(msg, "id");
	if (id > 0 && json_object_has_value(msg, "ok"))
	{
		handle_response(id, msg);
	}
	else if (json_object_has_value(msg, "type", JSONString))
	{
		new type[32];
		json_object_get_string(msg, "type", type, charsmax(type));
		new JSON:data = json_object_get_value(msg, "data");
		handle_event(type, data);
		if (data != Invalid_JSON)
		{
			json_free(data);
		}
	}
	json_free(msg);
}

handle_response(id, JSON:msg)
{
	new slot = -1;
	for (new i = 0; i < MAX_PENDING; i++)
	{
		if (g_PendKind[i] != KIND_FREE && g_PendId[i] == id)
		{
			slot = i;
			break;
		}
	}
	if (slot == -1)
	{
		return;
	}
	new bool:ok = json_object_get_bool(msg, "ok");
	new error[64], message[256];
	new JSON:data = Invalid_JSON;
	if (ok)
	{
		data = json_object_get_value(msg, "data");
	}
	else
	{
		json_object_get_string(msg, "error", error, charsmax(error));
		json_object_get_string(msg, "message", message, charsmax(message));
	}
	complete_pending(slot, ok, data, error, message);
	if (data != Invalid_JSON)
	{
		json_free(data);
	}
}

handle_event(const type[], JSON:data)
{
	if (data == Invalid_JSON)
	{
		return;
	}
	if (equal(type, "print"))
	{
		new to = json_object_get_number(data, "to");
		new channel[16];
		json_object_get_string(data, "channel", channel, charsmax(channel));
		json_object_get_string(data, "text", g_Text, charsmax(g_Text));
		print_to(to, bool:equal(channel, "console"), g_Text);
	}
	else if (equal(type, "sound"))
	{
		new id = json_object_get_number(data, "slot");
		new sound[128];
		json_object_get_string(data, "sound", sound, charsmax(sound));
		if (is_valid_target(id) && is_safe_arg(sound))
		{
			client_cmd(id, "spk ^"%s^"", sound);
		}
	}
	else if (equal(type, "motd"))
	{
		new id = json_object_get_number(data, "slot");
		new title[64];
		json_object_get_string(data, "title", title, charsmax(title));
		json_object_get_string(data, "url", g_Text, charsmax(g_Text));
		if (is_valid_target(id))
		{
			show_motd(id, g_Text, title);
		}
	}
	else if (equal(type, "chat.tag"))
	{
		new id = json_object_get_number(data, "slot");
		if (1 <= id <= MAX_PLAYERS)
		{
			json_object_get_string(data, "tag", g_Tag[id], charsmax(g_Tag[]));
			strip_colors(g_Tag[id]);
		}
	}
	else if (equal(type, "input.capture"))
	{
		new id = json_object_get_number(data, "slot");
		if (1 <= id <= MAX_PLAYERS)
		{
			g_Capture[id] = json_object_get_bool(data, "on");
		}
	}
	else if (equal(type, "menu"))
	{
		show_service_menu(data);
	}
	else if (equal(type, "prompt"))
	{
		new id = json_object_get_number(data, "slot");
		json_object_get_string(data, "label", g_Text, charsmax(g_Text));
		if (is_valid_target(id))
		{
			client_print_color(id, print_team_default, "%s", g_Text);
			client_cmd(id, "messagemode claudia_input");
		}
	}
	else if (equal(type, "exec"))
	{
		// Solo comandos de una lista blanca: el servicio no puede ejecutar cualquier cosa en el cliente.
		new id = json_object_get_number(data, "slot");
		new cmd[32];
		json_object_get_string(data, "cmd", cmd, charsmax(cmd));
		if (is_valid_target(id) && equal(cmd, "amxmodmenu"))
		{
			client_cmd(id, "amxmodmenu");
		}
	}
	else if (equal(type, "auth.state"))
	{
		set_auth_state(json_object_get_number(data, "slot"), json_object_get_bool(data, "registered"), json_object_get_bool(data, "logged"));
	}
	else
	{
		ExecuteForward(g_fwdEvent, _, type, data);
	}
}

print_to(to, bool:console, const text[])
{
	if (to == -1)
	{
		server_print("%s", text);
		return;
	}
	if (to == 0)
	{
		if (!console)
		{
			client_print_color(0, print_team_default, "%s", text);
			return;
		}
		new players[MAX_PLAYERS], num;
		get_players(players, num, "ch");
		for (new i = 0; i < num; i++)
		{
			client_print(players[i], print_console, "%s", text);
		}
		return;
	}
	if (!is_valid_target(to))
	{
		return;
	}
	if (console)
	{
		client_print(to, print_console, "%s", text);
	}
	else
	{
		client_print_color(to, print_team_default, "%s", text);
	}
}

bool:is_valid_target(id)
{
	return (1 <= id <= MAX_PLAYERS) && is_user_connected(id) && !is_user_bot(id);
}

/** Evita inyectar comandos en client_cmd (comillas o punto y coma). */
bool:is_safe_arg(const s[])
{
	for (new i = 0; s[i] != EOS; i++)
	{
		if (s[i] == '"' || s[i] == ';' || s[i] == '^n' || s[i] == '^r')
		{
			return false;
		}
	}
	return true;
}

/* =========================================================================
 * Jugadores
 * ========================================================================= */

public client_putinserver(id)
{
	g_Registered[id] = false;
	g_Logged[id] = false;
	g_Joined[id] = false;
	g_Tag[id][0] = EOS;
	g_Capture[id] = false;
	if (is_user_bot(id) || is_user_hltv(id))
	{
		return;
	}
	get_user_name(id, g_Name[id], charsmax(g_Name[]));
	if (g_State == STATE_READY)
	{
		send_join(id);
	}
}

public client_disconnected(id)
{
	if (g_Joined[id])
	{
		ExecuteForward(g_fwdLeaving, _, id);
		new JSON:data = json_init_object();
		json_object_set_number(data, "slot", id);
		send_message("player.leave", data, KIND_FREE);
	}
	g_Joined[id] = false;
	g_Registered[id] = false;
	g_Logged[id] = false;
	g_Name[id][0] = EOS;
	g_Tag[id][0] = EOS;
	g_Capture[id] = false;
	g_Menu[id] = -1;
}

public client_infochanged(id)
{
	if (!is_user_connected(id) || is_user_bot(id) || !g_Name[id][0])
	{
		return;
	}
	new newname[MAX_NAME_LENGTH];
	get_user_info(id, "name", newname, charsmax(newname));
	if (equal(newname, g_Name[id]))
	{
		return;
	}
	copy(g_Name[id], charsmax(g_Name[]), newname);
	new bool:wasLogged = g_Logged[id];
	// Cambiar de nick cierra la sesión. Se limpia ya y no al llegar la respuesta: si el servicio está
	// caído o no contesta, un was_logged viejo en el próximo player.join lo reanudaría en la cuenta del
	// nick nuevo.
	g_Logged[id] = false;
	g_Tag[id][0] = EOS;
	g_Capture[id] = false;
	if (g_Joined[id])
	{
		new JSON:data = json_init_object();
		json_object_set_number(data, "slot", id);
		json_object_set_string(data, "nick", newname);
		send_message("player.rename", data, KIND_JOIN, -1, id);
	}
	if (wasLogged)
	{
		client_print_color(id, print_team_default, "%s %L", CLAUDIA_TAG, id, "CLAUDIA_NICK_CHANGED");
	}
}

/* =========================================================================
 * Chat
 * ========================================================================= */

public cmd_say(id)
{
	return handle_say(id, false);
}

public cmd_say_team(id)
{
	return handle_say(id, true);
}

handle_say(id, bool:team)
{
	if (!is_user_connected(id) || is_user_bot(id))
	{
		return PLUGIN_CONTINUE;
	}
	read_args(g_SayText, charsmax(g_SayText));
	remove_quotes(g_SayText);
	trim(g_SayText);
	if (!g_SayText[0] || g_SayText[0] == '@')
	{
		return PLUGIN_CONTINUE;
	}

	if (g_SayText[0] == '/' || g_SayText[0] == '!')
	{
		new cmd[32], args[224];
		strtok2(g_SayText[1], cmd, charsmax(cmd), args, charsmax(args), ' ', TRIM_FULL);
		strtolower(cmd);
		if (!cmd[0])
		{
			return PLUGIN_CONTINUE;
		}

		new ret = PLUGIN_CONTINUE;
		ExecuteForward(g_fwdSayCommand, ret, id, cmd, args);
		if (ret == PLUGIN_HANDLED)
		{
			return PLUGIN_HANDLED;
		}

		if (TrieKeyExists(g_Commands, cmd))
		{
			if (g_State != STATE_READY || !g_Joined[id])
			{
				client_print_color(id, print_team_default, "%s %L", CLAUDIA_TAG, id, "CLAUDIA_OFFLINE");
				return PLUGIN_HANDLED;
			}
			send_command(id, cmd, args);
			return PLUGIN_HANDLED;
		}
		return PLUGIN_CONTINUE;
	}

	// Formulario por chat (ej. crear un grupo): la respuesta va al servicio y no se muestra.
	if (g_Capture[id] && g_State == STATE_READY && g_Joined[id])
	{
		new JSON:data = json_init_object();
		json_object_set_number(data, "slot", id);
		json_object_set_string(data, "text", g_SayText);
		send_message("input", data, KIND_FREE);
		return PLUGIN_HANDLED;
	}

	// Chat global y de muertos: contexto para la IA. El chat de equipo no se manda.
	if (!team && g_State == STATE_READY && g_Joined[id])
	{
		new JSON:data = json_init_object();
		json_object_set_number(data, "slot", id);
		json_object_set_string(data, "text", g_SayText);
		json_object_set_bool(data, "dead", !is_user_alive(id));
		send_message("chat", data, KIND_FREE);
	}

	// Todo el chat se imprime acá con el formato [Rol][TAG]Nick: mensaje.
	print_tagged(id, team, g_SayText);
	return PLUGIN_HANDLED;
}

/**
 * Imprime un mensaje de chat con los tags (rol y grupo) respetando las reglas del CS:
 * los muertos solo le hablan a los muertos y el chat de equipo solo le llega al equipo.
 */
print_tagged(id, bool:team, const text[])
{
	new msg[192];
	copy(msg, charsmax(msg), text);
	strip_colors(msg);

	new senderTeam = get_user_team(id);
	new bool:alive = bool:is_user_alive(id);
	new prefix[40];
	if (senderTeam != 1 && senderTeam != 2)
	{
		copy(prefix, charsmax(prefix), "*SPEC* ");
	}
	else if (!alive)
	{
		copy(prefix, charsmax(prefix), "*MUERTO* ");
	}
	if (team)
	{
		add(prefix, charsmax(prefix), senderTeam == 1 ? "(Terrorista) " : (senderTeam == 2 ? "(Anti-Terrorista) " : "(Espectador) "));
	}
	// [Rol][TAG]Nick: mensaje. Colores del chat de GoldSrc: ^1 amarillo, ^3 color del emisor, ^4 verde
	// (el negro no existe). Owner en rojo (emisor forzado a rojo), staff en amarillo y admin en verde.
	new role = get_role(id);
	new sender = role == 3 ? print_team_red : id;
	new tags[64];
	switch (role)
	{
		case 3: copy(tags, charsmax(tags), "^3[Owner]");
		case 2: copy(tags, charsmax(tags), "^1[Staff]");
		case 1: copy(tags, charsmax(tags), "^4[Admin]");
	}
	if (g_Tag[id][0])
	{
		format(tags, charsmax(tags), "%s^4[%s]", tags, g_Tag[id]);
	}
	formatex(g_ChatLine, charsmax(g_ChatLine), "^1%s%s^3%s^1: %s", prefix, tags, g_Name[id], msg);

	new bool:alltalk = get_cvar_num("sv_alltalk") != 0;
	new players[MAX_PLAYERS], num;
	get_players(players, num, "ch");
	for (new i = 0; i < num; i++)
	{
		new to = players[i];
		if (team && get_user_team(to) != senderTeam)
		{
			continue;
		}
		if (!alive && !alltalk && is_user_alive(to))
		{
			continue;
		}
		client_print_color(to, sender, "%s", g_ChatLine);
	}

	// Mismo formato que el log del motor, para que no se pierda en los logs/estadísticas.
	new authid[64], teamName[32];
	get_user_authid(id, authid, charsmax(authid));
	get_user_team(id, teamName, charsmax(teamName));
	log_message("^"%s<%d><%s><%s>^" %s ^"%s^"", g_Name[id], get_user_userid(id), authid, teamName, team ? "say_team" : "say", msg);
}

/** Quita los códigos de color del chat (bytes 1 a 4) para que no se puedan inyectar. */
strip_colors(text[])
{
	for (new i = 0; text[i] != EOS; i++)
	{
		if (text[i] >= 1 && text[i] <= 4)
		{
			text[i] = ' ';
		}
	}
}

/* =========================================================================
 * Roles, comandos y menús
 * ========================================================================= */

/** Rol según las flags: 0 jugador, 1 admin, 2 staff, 3 owner. La consola (id 0) es owner. */
get_role(id)
{
	if (id == 0)
	{
		return 3;
	}
	if (!is_user_connected(id))
	{
		return 0;
	}
	new flags = get_user_flags(id);
	if (g_OwnerFlags[0] && (flags & read_flags(g_OwnerFlags)))
	{
		return 3;
	}
	if (g_StaffFlags[0] && (flags & read_flags(g_StaffFlags)))
	{
		return 2;
	}
	if (g_AdminFlags[0] && (flags & read_flags(g_AdminFlags)))
	{
		return 1;
	}
	return 0;
}

/** Datos comunes de los mensajes de un jugador: slot y rol (staff/admin quedan por compatibilidad). */
JSON:player_data(id)
{
	new JSON:data = json_init_object();
	new role = get_role(id);
	json_object_set_number(data, "slot", id);
	json_object_set_number(data, "role", role);
	json_object_set_bool(data, "staff", role >= 2);
	json_object_set_bool(data, "admin", role >= 1);
	return data;
}

send_command(id, const cmd[], const args[])
{
	new JSON:data = player_data(id);
	json_object_set_string(data, "name", cmd);
	json_object_set_string(data, "args", args);
	send_message("cmd", data, KIND_FREE);
}

/** claudia_menu: para bindear una tecla, ej. bind "F3" "claudia_menu". */
public cmd_menu(id)
{
	if (g_State != STATE_READY || !g_Joined[id])
	{
		client_print_color(id, print_team_default, "%s %L", CLAUDIA_TAG, id, "CLAUDIA_OFFLINE");
		return PLUGIN_HANDLED;
	}
	send_command(id, "menu", "");
	return PLUGIN_HANDLED;
}

/** Respuesta a un texto pedido por el menú (messagemode claudia_input). */
public cmd_input(id)
{
	if (g_State != STATE_READY || !g_Joined[id])
	{
		return PLUGIN_HANDLED;
	}
	read_args(g_SayText, charsmax(g_SayText));
	remove_quotes(g_SayText);
	trim(g_SayText);
	new JSON:data = player_data(id);
	json_object_set_string(data, "text", g_SayText);
	send_message("menu.input", data, KIND_FREE);
	return PLUGIN_HANDLED;
}

/** Muestra un menú armado por el servicio: {slot, id, title, items[], page}. */
show_service_menu(JSON:data)
{
	new id = json_object_get_number(data, "slot");
	if (!is_valid_target(id))
	{
		return;
	}
	json_object_get_string(data, "title", g_MenuTitle, charsmax(g_MenuTitle));
	new JSON:items = json_object_get_value(data, "items");
	if (items == Invalid_JSON)
	{
		return;
	}
	new menu = menu_create(g_MenuTitle, "menu_handler");
	new count = json_array_get_count(items);
	new info[8];
	for (new i = 0; i < count; i++)
	{
		json_array_get_string(items, i, g_MenuItem, charsmax(g_MenuItem));
		num_to_str(i, info, charsmax(info));
		menu_additem(menu, g_MenuItem, info);
	}
	json_free(items);

	// Hasta 9 opciones entran en una sola página con "0. Salir"; si hay más, se pagina de a 7.
	if (count <= 9)
	{
		menu_setprop(menu, MPROP_PERPAGE, 0);
		menu_setprop(menu, MPROP_EXIT, MEXIT_FORCE);
	}
	menu_setprop(menu, MPROP_BACKNAME, "Anterior");
	menu_setprop(menu, MPROP_NEXTNAME, "Siguiente");
	menu_setprop(menu, MPROP_EXITNAME, "Salir");

	// Si tenía otro abierto, su handler recibe MENU_EXIT y lo destruye (ya no es el actual).
	g_Menu[id] = menu;
	g_MenuId[id] = json_object_get_number(data, "id");
	new page = json_object_get_number(data, "page");
	menu_display(id, menu, (page > 0 && count > 9) ? page : 0);
}

public menu_handler(id, menu, item)
{
	new bool:current = (g_Menu[id] == menu);
	new page = 0;
	if (current)
	{
		new oldMenu, newMenu;
		player_menu_info(id, oldMenu, newMenu, page);
		g_Menu[id] = -1;
	}
	if (current && is_user_connected(id) && g_State == STATE_READY)
	{
		new JSON:data = player_data(id);
		json_object_set_number(data, "menu", g_MenuId[id]);
		if (item >= 0)
		{
			new info[8], access, callback;
			menu_item_getinfo(menu, item, access, info, charsmax(info), _, _, callback);
			json_object_set_number(data, "item", str_to_num(info));
			json_object_set_number(data, "page", page);
			send_message("menu.select", data, KIND_FREE);
		}
		else
		{
			send_message("menu.close", data, KIND_FREE);
		}
	}
	menu_destroy(menu);
	return PLUGIN_HANDLED;
}

public cmd_status()
{
	new const names[][] = { "desconectado", "conectando", "handshake", "conectado" };
	server_print("[Claudia] Servicio %s:%d - %s. Comandos conocidos: %d.", g_Host, g_Port, names[g_State], TrieGetSize(g_Commands));
	return PLUGIN_HANDLED;
}

/* =========================================================================
 * Natives
 * ========================================================================= */

public bool:native_connected(plugin, argc)
{
	return g_State == STATE_READY;
}

public bool:native_send(plugin, argc)
{
	new type[64], callback[64];
	get_string(1, type, charsmax(type));
	new JSON:data = JSON:get_param_byref(2);
	get_string(3, callback, charsmax(callback));
	new extra = get_param(4);
	set_param_byref(2, _:Invalid_JSON);

	new fwd = -1;
	if (callback[0])
	{
		fwd = CreateOneForward(plugin, callback, FP_CELL, FP_CELL, FP_STRING, FP_STRING, FP_CELL);
		if (fwd == -1)
		{
			log_error(AMX_ERR_NATIVE, "[Claudia] No existe la función pública '%s'.", callback);
		}
	}
	return send_message(type, data, fwd != -1 ? KIND_EXTERNAL : KIND_FREE, fwd, extra) >= 0;
}

public bool:native_is_logged(plugin, argc)
{
	new id = get_param(1);
	return (1 <= id <= MAX_PLAYERS) && g_Logged[id];
}

public native_role(plugin, argc)
{
	return get_role(get_param(1));
}

public bool:native_is_registered(plugin, argc)
{
	new id = get_param(1);
	return (1 <= id <= MAX_PLAYERS) && g_Registered[id];
}
