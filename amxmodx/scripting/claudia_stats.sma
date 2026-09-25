/*
 * Claudia - Estadísticas
 *
 * Cuenta kills, muertes, headshots, disparos, impactos, tiempo jugado y rondas de cada jugador
 * identificado, y los manda al servicio por lotes (cada 60 s y al desconectarse).
 * Solo usa hamsandwich/fakemeta (no depende de ReGameDLL).
 *
 * Precisión: un disparo cuenta como acierto si al menos una bala/perdigón le pega a un
 * jugador enemigo, así la escopeta no pasa del 100 %.
 */

#include <amxmodx>
#include <cstrike>
#include <fakemeta>
#include <hamsandwich>
#include <claudia>

#pragma semicolon 1

#define PLUGIN  "Claudia Stats"
#define VERSION "1.0.0"
#define AUTHOR  "Claudia"

#define TASK_FLUSH    73001
#define TASK_PLAYTIME 73002

#define FLUSH_INTERVAL    60.0
#define PLAYTIME_INTERVAL 5

enum _:StatFields
{
	ST_KILLS,
	ST_DEATHS,
	ST_HEADSHOTS,
	ST_SHOTS,
	ST_HITS,
	ST_PLAYTIME,
	ST_ROUNDS
};

new const FIELD_NAMES[StatFields][] = { "kills", "deaths", "headshots", "shots", "hits", "playtime", "rounds" };

new const WEAPONS[][] =
{
	"weapon_p228", "weapon_scout", "weapon_xm1014", "weapon_mac10", "weapon_aug", "weapon_elite",
	"weapon_fiveseven", "weapon_ump45", "weapon_sg550", "weapon_galil", "weapon_famas", "weapon_usp",
	"weapon_glock18", "weapon_awp", "weapon_mp5navy", "weapon_m249", "weapon_m3", "weapon_m4a1",
	"weapon_tmp", "weapon_g3sg1", "weapon_deagle", "weapon_sg552", "weapon_ak47", "weapon_p90"
};

new g_Stats[MAX_PLAYERS + 1][StatFields];
new g_ClipBefore[MAX_PLAYERS + 1];
new bool:g_InAttack[MAX_PLAYERS + 1];
new bool:g_HitThisShot[MAX_PLAYERS + 1];

public plugin_init()
{
	register_plugin(PLUGIN, VERSION, AUTHOR);

	register_event("DeathMsg", "ev_death", "a");
	register_logevent("ev_round_end", 2, "1=Round_End");
	for (new i = 0; i < sizeof WEAPONS; i++)
	{
		RegisterHam(Ham_Weapon_PrimaryAttack, WEAPONS[i], "fw_attack_pre", false);
		RegisterHam(Ham_Weapon_PrimaryAttack, WEAPONS[i], "fw_attack_post", true);
	}
	RegisterHam(Ham_TraceAttack, "player", "fw_trace_attack_post", true);

	set_task(FLUSH_INTERVAL, "task_flush", TASK_FLUSH, _, _, "b");
	set_task(float(PLAYTIME_INTERVAL), "task_playtime", TASK_PLAYTIME, _, _, "b");
}

public client_putinserver(id)
{
	reset(id);
}

public claudia_player_leaving(id)
{
	// El núcleo llama esto antes de avisar la salida: se mandan los últimos datos.
	send_batch(id);
}

public claudia_auth_changed(id, bool:registered, bool:logged)
{
	// Lo acumulado antes de identificarse no se cuenta.
	if (!logged)
	{
		reset(id);
	}
}

public ev_death()
{
	new killer = read_data(1);
	new victim = read_data(2);
	new headshot = read_data(3);

	if (1 <= victim <= MAX_PLAYERS && tracked(victim))
	{
		g_Stats[victim][ST_DEATHS]++;
	}
	if (1 <= killer <= MAX_PLAYERS && killer != victim && tracked(killer) && is_user_connected(victim)
		&& cs_get_user_team(killer) != cs_get_user_team(victim))
	{
		g_Stats[killer][ST_KILLS]++;
		if (headshot)
		{
			g_Stats[killer][ST_HEADSHOTS]++;
		}
	}
}

/** Fin de ronda: cuenta una ronda jugada a cada identificado que está en un equipo (cuotas de los grupos). */
public ev_round_end()
{
	new players[MAX_PLAYERS], num;
	get_players(players, num, "ch");
	for (new i = 0; i < num; i++)
	{
		new id = players[i];
		new CsTeams:team = cs_get_user_team(id);
		if (tracked(id) && (team == CS_TEAM_T || team == CS_TEAM_CT))
		{
			g_Stats[id][ST_ROUNDS]++;
		}
	}
}

public fw_attack_pre(weapon)
{
	new id = get_ent_data_entity(weapon, "CBasePlayerItem", "m_pPlayer");
	if (1 <= id <= MAX_PLAYERS)
	{
		g_ClipBefore[id] = get_ent_data(weapon, "CBasePlayerWeapon", "m_iClip");
		g_InAttack[id] = true;
		g_HitThisShot[id] = false;
	}
	return HAM_IGNORED;
}

public fw_attack_post(weapon)
{
	new id = get_ent_data_entity(weapon, "CBasePlayerItem", "m_pPlayer");
	if (1 <= id <= MAX_PLAYERS)
	{
		g_InAttack[id] = false;
		if (tracked(id) && get_ent_data(weapon, "CBasePlayerWeapon", "m_iClip") < g_ClipBefore[id])
		{
			g_Stats[id][ST_SHOTS]++;
			if (g_HitThisShot[id])
			{
				g_Stats[id][ST_HITS]++;
			}
		}
	}
	return HAM_IGNORED;
}

public fw_trace_attack_post(victim, attacker, Float:damage, Float:direction[3], tr, damagebits)
{
	if (1 <= attacker <= MAX_PLAYERS && attacker != victim && g_InAttack[attacker]
		&& is_user_connected(victim) && cs_get_user_team(attacker) != cs_get_user_team(victim))
	{
		g_HitThisShot[attacker] = true;
	}
	return HAM_IGNORED;
}

public task_playtime()
{
	new players[MAX_PLAYERS], num;
	get_players(players, num, "ch");
	for (new i = 0; i < num; i++)
	{
		new id = players[i];
		new CsTeams:team = cs_get_user_team(id);
		if (tracked(id) && (team == CS_TEAM_T || team == CS_TEAM_CT))
		{
			g_Stats[id][ST_PLAYTIME] += PLAYTIME_INTERVAL;
		}
	}
}

public task_flush()
{
	send_batch(0);
}

/** Manda los contadores de un jugador (id) o de todos (0) y los reinicia. */
send_batch(only)
{
	if (!claudia_connected())
	{
		return;
	}
	new JSON:list = json_init_array();
	new count = 0;
	for (new id = 1; id <= MAX_PLAYERS; id++)
	{
		if ((only && id != only) || !claudia_is_logged(id) || !has_data(id))
		{
			continue;
		}
		new JSON:p = json_init_object();
		json_object_set_number(p, "slot", id);
		for (new f = 0; f < StatFields; f++)
		{
			json_object_set_number(p, FIELD_NAMES[f], g_Stats[id][f]);
		}
		json_array_append_value(list, p);
		json_free(p);
		reset(id);
		count++;
	}
	if (!count)
	{
		json_free(list);
		return;
	}
	new JSON:data = json_init_object();
	json_object_set_value(data, "players", list);
	json_free(list);
	claudia_send("stats", data);
}

bool:tracked(id)
{
	return is_user_connected(id) && !is_user_bot(id) && claudia_is_logged(id);
}

bool:has_data(id)
{
	for (new f = 0; f < StatFields; f++)
	{
		if (g_Stats[id][f])
		{
			return true;
		}
	}
	return false;
}

reset(id)
{
	for (new f = 0; f < StatFields; f++)
	{
		g_Stats[id][f] = 0;
	}
	g_InAttack[id] = false;
	g_HitThisShot[id] = false;
}
