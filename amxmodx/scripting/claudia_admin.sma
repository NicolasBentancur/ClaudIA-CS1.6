/*
 * Claudia - Administración
 *
 *   amx_darcoins <nick> <monto>      da URU Coins (el nick puede ser parcial si está conectado)
 *   amx_quitarcoins <nick> <monto>   quita URU Coins (el saldo nunca queda negativo)
 *   amx_claudia_reload               recarga los JSON de configuración del servicio
 *
 * Requieren las flags de claudia_admin_flags (por defecto "l" = rcon). La consola del servidor
 * siempre puede usarlos.
 */

#include <amxmodx>
#include <amxmisc>
#include <claudia>

#pragma semicolon 1

#define PLUGIN  "Claudia Admin"
#define VERSION "1.0.0"
#define AUTHOR  "Claudia"

public plugin_init()
{
	register_plugin(PLUGIN, VERSION, AUTHOR);
	register_dictionary("claudia.txt");

	register_concmd("amx_darcoins", "cmd_give", ADMIN_ALL, "<nick> <monto> - da URU Coins");
	register_concmd("amx_quitarcoins", "cmd_take", ADMIN_ALL, "<nick> <monto> - quita URU Coins");
	register_concmd("amx_claudia_reload", "cmd_reload", ADMIN_ALL, "- recarga la configuración de Claudia");
}

public cmd_give(id)
{
	return coins(id, true);
}

public cmd_take(id)
{
	return coins(id, false);
}

coins(id, bool:give)
{
	if (!allowed(id))
	{
		return PLUGIN_HANDLED;
	}
	if (read_argc() < 3)
	{
		reply(id, give ? "Uso: amx_darcoins <nick> <monto>" : "Uso: amx_quitarcoins <nick> <monto>");
		return PLUGIN_HANDLED;
	}
	new target[64], amount[32], name[MAX_NAME_LENGTH];
	read_argv(1, target, charsmax(target));
	read_argv(2, amount, charsmax(amount));
	if (id)
	{
		get_user_name(id, name, charsmax(name));
	}
	else
	{
		copy(name, charsmax(name), "consola");
	}
	new JSON:data = json_init_object();
	json_object_set_number(data, "slot", id);
	json_object_set_string(data, "admin_name", name);
	json_object_set_string(data, "target", target);
	json_object_set_string(data, "amount", amount);
	json_object_set_string(data, "mode", give ? "give" : "take");
	if (!claudia_send("admin.coins", data, "cb_admin", id ? get_user_userid(id) : 0))
	{
		reply(id, "Claudia no está conectada al servicio.");
	}
	else
	{
		log_amx("[Claudia] %s: %s %s %s", name, give ? "amx_darcoins" : "amx_quitarcoins", target, amount);
	}
	return PLUGIN_HANDLED;
}

public cmd_reload(id)
{
	if (!allowed(id))
	{
		return PLUGIN_HANDLED;
	}
	new JSON:data = Invalid_JSON;
	if (!claudia_send("admin.reload", data, "cb_admin", id ? get_user_userid(id) : 0))
	{
		reply(id, "Claudia no está conectada al servicio.");
	}
	return PLUGIN_HANDLED;
}

public cb_admin(bool:ok, JSON:data, const error[], const message[], userid)
{
	new id = 0;
	if (userid)
	{
		id = find_player("k", userid);
		if (!id)
		{
			return;
		}
	}
	if (!ok)
	{
		reply(id, message);
		return;
	}
	new text[256];
	json_object_get_string(data, "message", text, charsmax(text));
	reply(id, text);
}

bool:allowed(id)
{
	if (!id)
	{
		return true;
	}
	new flags[32];
	get_cvar_string("claudia_admin_flags", flags, charsmax(flags));
	if (!(get_user_flags(id) & read_flags(flags)))
	{
		console_print(id, "No tenés acceso a este comando.");
		return false;
	}
	return true;
}

reply(id, const text[])
{
	if (id)
	{
		console_print(id, "[Claudia] %s", text);
	}
	else
	{
		server_print("[Claudia] %s", text);
	}
}
