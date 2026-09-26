/*
 * Claudia - Ajedrez
 *
 * La partida la maneja el servicio (tablero en el MOTD). Este plugin hace lo que pasa en el
 * juego mientras dura:
 *   chess.spec  {slot, on}   manda al jugador a espectador (on) o lo devuelve a su equipo (off)
 *   chess.voice {slot, on}   +voicerecord / -voicerecord (el botón de micrófono del tablero)
 *   chess.pair  {a, b, on}   voz privada: lo que dice cada uno lo escucha solo el rival
 *
 * Si se corta la conexión con el servicio, devuelve a todos a su equipo y apaga los micrófonos
 * (el servicio devuelve las apuestas al reiniciar).
 */

#include <amxmodx>
#include <cstrike>
#include <fakemeta>
#include <fun>
#include <json>
#include <claudia>

#pragma semicolon 1

#define PLUGIN  "Claudia Ajedrez"
#define VERSION "1.0.0"
#define AUTHOR  "Claudia"

new bool:g_InMatch[MAX_PLAYERS + 1];
new g_Team[MAX_PLAYERS + 1];          // equipo al que vuelve (1 T, 2 CT, 0 ninguno)
new g_Partner[MAX_PLAYERS + 1];       // rival (voz privada)
new bool:g_Voice[MAX_PLAYERS + 1];
new bool:g_Moving[MAX_PLAYERS + 1];   // cambio de equipo hecho por este plugin

public plugin_init()
{
	register_plugin(PLUGIN, VERSION, AUTHOR);
	register_clcmd("jointeam", "cmd_team");
	register_clcmd("chooseteam", "cmd_team");
	register_forward(FM_Voice_SetClientListening, "fw_voice_listening");
}

public client_disconnected(id)
{
	new partner = g_Partner[id];
	if (partner && g_Partner[partner] == id)
	{
		g_Partner[partner] = 0;
	}
	g_Partner[id] = 0;
	g_InMatch[id] = false;
	g_Team[id] = 0;
	g_Voice[id] = false;
	g_Moving[id] = false;
}

public claudia_event(const type[], JSON:data)
{
	if (equal(type, "chess.spec"))
	{
		new id = json_object_get_number(data, "slot");
		if (valid(id))
		{
			if (json_object_get_bool(data, "on"))
			{
				to_spectator(id);
			}
			else
			{
				restore_team(id);
			}
		}
	}
	else if (equal(type, "chess.voice"))
	{
		new id = json_object_get_number(data, "slot");
		if (valid(id))
		{
			set_voice(id, json_object_get_bool(data, "on"));
		}
	}
	else if (equal(type, "chess.pair"))
	{
		new a = json_object_get_number(data, "a");
		new b = json_object_get_number(data, "b");
		if (valid(a) && valid(b))
		{
			new bool:on = json_object_get_bool(data, "on");
			g_Partner[a] = on ? b : 0;
			g_Partner[b] = on ? a : 0;
		}
	}
}

public claudia_connection_changed(bool:connected)
{
	if (connected)
	{
		return;
	}
	for (new id = 1; id <= MAX_PLAYERS; id++)
	{
		if (!is_user_connected(id))
		{
			continue;
		}
		set_voice(id, false);
		g_Partner[id] = 0;
		if (g_InMatch[id])
		{
			restore_team(id);
		}
	}
}

bool:valid(id)
{
	return 1 <= id <= MAX_PLAYERS && is_user_connected(id) && !is_user_bot(id);
}

/** Mientras juega no puede volver a un equipo a mano (lo devuelve el plugin al terminar). */
public cmd_team(id)
{
	if (g_InMatch[id] && !g_Moving[id])
	{
		client_print_color(id, print_team_default, "^4[Ajedrez]^1 Estás jugando al ajedrez: volvés a tu equipo cuando termine la partida (^4/ajedrez volver^1 abre el tablero).");
		return PLUGIN_HANDLED;
	}
	return PLUGIN_CONTINUE;
}

to_spectator(id)
{
	g_InMatch[id] = true;
	new team = get_user_team(id);
	g_Team[id] = (team == 1 || team == 2) ? team : 0;
	if (!g_Team[id])
	{
		return;
	}
	if (is_user_alive(id))
	{
		// Sin mensaje de muerte: no cuenta como muerte ni deja cartel de revivir.
		user_silentkill(id);
	}
	g_Moving[id] = true;
	engclient_cmd(id, "jointeam", "6");
	g_Moving[id] = false;
	client_print_color(id, print_team_default, "^4[Ajedrez]^1 Pasaste a espectador mientras dura la partida.");
}

restore_team(id)
{
	g_InMatch[id] = false;
	set_voice(id, false);
	new team = g_Team[id];
	g_Team[id] = 0;
	if (!team || get_user_team(id) == team)
	{
		return;
	}
	g_Moving[id] = true;
	join(id, team);
	if (get_user_team(id) != team)
	{
		// Equipo lleno (mp_limitteams): al otro.
		join(id, team == 1 ? 2 : 1);
	}
	g_Moving[id] = false;
	client_print_color(id, print_team_default, "^4[Ajedrez]^1 Volviste a tu equipo: aparecés en la próxima ronda.");
}

join(id, team)
{
	new arg[2];
	arg[0] = '0' + team;
	engclient_cmd(id, "jointeam", arg);
	engclient_cmd(id, "joinclass", "5"); // modelo al azar
}

set_voice(id, bool:on)
{
	if (g_Voice[id] == on)
	{
		return;
	}
	g_Voice[id] = on;
	client_cmd(id, on ? "+voicerecord" : "-voicerecord");
}

/** Voz privada: lo que dice alguien que está jugando lo escucha solo su rival (y él escucha a su rival). */
public fw_voice_listening(receiver, sender, bool:listen)
{
	if (receiver == sender || !(1 <= sender <= MAX_PLAYERS) || !g_Partner[sender])
	{
		return FMRES_IGNORED;
	}
	new bool:allow = g_Partner[sender] == receiver;
	engfunc(EngFunc_SetClientListening, receiver, sender, allow);
	forward_return(FMV_CELL, allow);
	return FMRES_SUPERCEDE;
}
