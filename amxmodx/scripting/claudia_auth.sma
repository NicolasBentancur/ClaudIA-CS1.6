/*
 * Claudia - Autenticación
 *
 * Registro y login por nick + contraseña. Las contraseñas se escriben con messagemode
 * (la barra de chat manda un comando de consola, no un "say"), así nunca aparecen en el chat.
 * También se acepta "/login clave" o "/registrar clave" en el chat: se bloquea antes de que
 * se publique.
 *
 * El jugador que no se identifica en claudia_login_timeout segundos es expulsado. Si el servicio
 * de Claudia no está disponible no se expulsa a nadie.
 */

#include <amxmodx>
#include <claudia>

#pragma semicolon 1

#define PLUGIN  "Claudia Auth"
#define VERSION "1.0.0"
#define AUTHOR  "Claudia"

#define TASK_COUNTDOWN 72000
#define PASS_LEN 64

new g_Timeout;
new g_Remaining[MAX_PLAYERS + 1];
new bool:g_Counting[MAX_PLAYERS + 1];
new bool:g_IsRegistered[MAX_PLAYERS + 1];
new g_PendingPass[MAX_PLAYERS + 1][PASS_LEN];
new g_OldPass[MAX_PLAYERS + 1][PASS_LEN];
new g_HudSync;

public plugin_init()
{
	register_plugin(PLUGIN, VERSION, AUTHOR);
	register_dictionary("claudia.txt");

	bind_pcvar_num(create_cvar("claudia_login_timeout", "30", _, "Segundos para identificarse antes del kick (0 = no expulsar)", true, 0.0), g_Timeout);

	// Destinos de messagemode (lo que el jugador escribe llega como argumento).
	register_clcmd("claudia_login", "cmd_login_input");
	register_clcmd("claudia_registrar", "cmd_register_input");
	register_clcmd("claudia_confirmar", "cmd_confirm_input");
	register_clcmd("claudia_clave_actual", "cmd_old_input");
	register_clcmd("claudia_clave_nueva", "cmd_new_input");

	g_HudSync = CreateHudSyncObj();
}

/* =========================================================================
 * Comandos de chat
 * ========================================================================= */

public claudia_say_command(id, const cmd[], const args[])
{
	if (equal(cmd, "login") || equal(cmd, "entrar") || equal(cmd, "ingresar"))
	{
		if (claudia_is_logged(id))
		{
			tell(id, "CLAUDIA_ALREADY_LOGGED");
		}
		else if (args[0])
		{
			do_login(id, args);
		}
		else
		{
			ask(id, "claudia_login", "CLAUDIA_ASK_PASSWORD");
		}
		return PLUGIN_HANDLED;
	}
	if (equal(cmd, "registrar") || equal(cmd, "registro") || equal(cmd, "register"))
	{
		if (claudia_is_logged(id))
		{
			tell(id, "CLAUDIA_ALREADY_LOGGED");
		}
		else if (args[0])
		{
			do_register(id, args);
		}
		else
		{
			ask(id, "claudia_registrar", "CLAUDIA_ASK_NEW_PASSWORD");
		}
		return PLUGIN_HANDLED;
	}
	if (equal(cmd, "cambiarclave") || equal(cmd, "cambiarcontrasena") || equal(cmd, "clave"))
	{
		if (!claudia_is_logged(id))
		{
			tell(id, "CLAUDIA_CHAT_LOGIN");
		}
		else
		{
			ask(id, "claudia_clave_actual", "CLAUDIA_ASK_OLD_PASSWORD");
		}
		return PLUGIN_HANDLED;
	}
	return PLUGIN_CONTINUE;
}

public cmd_login_input(id)
{
	new pass[PASS_LEN];
	if (read_password(pass, charsmax(pass)))
	{
		do_login(id, pass);
	}
	return PLUGIN_HANDLED;
}

public cmd_register_input(id)
{
	new pass[PASS_LEN];
	if (read_password(pass, charsmax(pass)))
	{
		copy(g_PendingPass[id], charsmax(g_PendingPass[]), pass);
		ask(id, "claudia_confirmar", "CLAUDIA_ASK_CONFIRM");
	}
	return PLUGIN_HANDLED;
}

public cmd_confirm_input(id)
{
	new pass[PASS_LEN];
	if (!read_password(pass, charsmax(pass)) || !g_PendingPass[id][0])
	{
		return PLUGIN_HANDLED;
	}
	if (!equal(pass, g_PendingPass[id]))
	{
		tell(id, "CLAUDIA_MISMATCH");
	}
	else
	{
		do_register(id, pass);
	}
	g_PendingPass[id][0] = EOS;
	return PLUGIN_HANDLED;
}

public cmd_old_input(id)
{
	new pass[PASS_LEN];
	if (read_password(pass, charsmax(pass)))
	{
		copy(g_OldPass[id], charsmax(g_OldPass[]), pass);
		ask(id, "claudia_clave_nueva", "CLAUDIA_ASK_CHANGE_NEW");
	}
	return PLUGIN_HANDLED;
}

public cmd_new_input(id)
{
	new pass[PASS_LEN];
	if (!read_password(pass, charsmax(pass)) || !g_OldPass[id][0])
	{
		return PLUGIN_HANDLED;
	}
	if (!claudia_connected())
	{
		tell(id, "CLAUDIA_OFFLINE");
		return PLUGIN_HANDLED;
	}
	new JSON:data = json_init_object();
	json_object_set_number(data, "slot", id);
	json_object_set_string(data, "old", g_OldPass[id]);
	json_object_set_string(data, "new", pass);
	claudia_send("auth.change", data, "cb_change", get_user_userid(id));
	g_OldPass[id][0] = EOS;
	return PLUGIN_HANDLED;
}

/* =========================================================================
 * Pedidos al servicio
 * ========================================================================= */

do_login(id, const pass[])
{
	send_auth(id, "auth.login", pass);
}

do_register(id, const pass[])
{
	send_auth(id, "auth.register", pass);
}

send_auth(id, const type[], const pass[])
{
	if (!claudia_connected())
	{
		tell(id, "CLAUDIA_OFFLINE");
		return;
	}
	new JSON:data = json_init_object();
	json_object_set_number(data, "slot", id);
	json_object_set_string(data, "password", pass);
	claudia_send(type, data, "cb_auth", get_user_userid(id));
}

public cb_auth(bool:ok, JSON:data, const error[], const message[], userid)
{
	new id = find_player("k", userid);
	if (!id)
	{
		return;
	}
	// Si salió bien, el servicio ya saludó y mandó auth.state (claudia_auth_changed).
	if (!ok)
	{
		client_print_color(id, print_team_default, "%s %s", CLAUDIA_TAG, message);
	}
}

public cb_change(bool:ok, JSON:data, const error[], const message[], userid)
{
	new id = find_player("k", userid);
	if (!id)
	{
		return;
	}
	if (ok)
	{
		tell(id, "CLAUDIA_PASSWORD_CHANGED");
	}
	else
	{
		client_print_color(id, print_team_default, "%s %s", CLAUDIA_TAG, message);
	}
}

/* =========================================================================
 * Cuenta regresiva y kick
 * ========================================================================= */

public claudia_auth_changed(id, bool:registered, bool:logged)
{
	g_IsRegistered[id] = registered;
	if (logged)
	{
		stop_countdown(id);
		ClearSyncHud(id, g_HudSync);
	}
	else
	{
		start_countdown(id);
	}
}

public claudia_connection_changed(bool:connected)
{
	if (!connected)
	{
		// Sin servicio no se expulsa a nadie.
		for (new id = 1; id <= MAX_PLAYERS; id++)
		{
			stop_countdown(id);
		}
	}
}

public client_disconnected(id)
{
	stop_countdown(id);
	g_PendingPass[id][0] = EOS;
	g_OldPass[id][0] = EOS;
	g_IsRegistered[id] = false;
}

start_countdown(id)
{
	if (g_Timeout <= 0 || !is_user_connected(id) || is_user_bot(id))
	{
		return;
	}
	if (!g_Counting[id])
	{
		g_Counting[id] = true;
		g_Remaining[id] = g_Timeout;
		set_task(1.0, "task_countdown", TASK_COUNTDOWN + id, _, _, "b");
	}
	tell(id, g_IsRegistered[id] ? "CLAUDIA_CHAT_LOGIN" : "CLAUDIA_CHAT_REGISTER");
	show_hud(id);
}

stop_countdown(id)
{
	g_Counting[id] = false;
	remove_task(TASK_COUNTDOWN + id);
}

public task_countdown(taskid)
{
	new id = taskid - TASK_COUNTDOWN;
	if (!is_user_connected(id) || claudia_is_logged(id) || !claudia_connected())
	{
		stop_countdown(id);
		return;
	}
	g_Remaining[id]--;
	if (g_Remaining[id] <= 0)
	{
		stop_countdown(id);
		new reason[128];
		formatex(reason, charsmax(reason), "%L", id, "CLAUDIA_KICK");
		replace_string(reason, charsmax(reason), "^"", "");
		server_cmd("kick #%d ^"%s^"", get_user_userid(id), reason);
		return;
	}
	show_hud(id);
	if (g_Remaining[id] % 10 == 0)
	{
		tell(id, g_IsRegistered[id] ? "CLAUDIA_CHAT_LOGIN" : "CLAUDIA_CHAT_REGISTER");
	}
}

show_hud(id)
{
	set_hudmessage(255, 190, 0, -1.0, 0.28, 0, 0.0, 1.1, 0.0, 0.0, -1);
	ShowSyncHudMsg(id, g_HudSync, "%L", id, g_IsRegistered[id] ? "CLAUDIA_HUD_LOGIN" : "CLAUDIA_HUD_REGISTER", g_Remaining[id]);
}

/* =========================================================================
 * Utilidades
 * ========================================================================= */

bool:read_password(pass[], len)
{
	read_args(pass, len);
	remove_quotes(pass);
	trim(pass);
	return pass[0] != EOS;
}

ask(id, const command[], const key[])
{
	tell(id, key);
	client_cmd(id, "messagemode %s", command);
}

tell(id, const key[])
{
	client_print_color(id, print_team_default, "%s %L", CLAUDIA_TAG, id, key);
}
