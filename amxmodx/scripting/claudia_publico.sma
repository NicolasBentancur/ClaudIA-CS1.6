/*
 * Claudia - Servidor público
 *
 * - Calentamiento de 1 minuto al empezar cada mapa (se revive al instante).
 * - Menú de loadouts al aparecer (arma + deagle + chaleco/casco + todas las granadas).
 * - Votación de mapa al final del mapa (con opción de extender) y rock the vote (/rtv).
 * - Rondas con tiempos competitivos y freezetime 0 (configs/claudia/publico.cfg).
 * - HUD para los CT cuando plantan la bomba y para los T cuando se termina el tiempo.
 * - Cuenta regresiva de la bomba (10..1), sonidos de rachas/multikills (UT2004), primera sangre,
 *   humillación a cuchillo, flawless victory y "prepare to fight" al empezar la ronda.
 *   Cada jugador activa o desactiva los sonidos de muertes con /sonidos.
 * - Daño hecho (azul) y recibido (rojo) debajo de la mira; resumen de víctimas y atacantes al
 *   morir o al empezar la ronda.
 * - Revivir: sobre el cuerpo de los compañeros muertos hay un cartel; manteniendo E 5 segundos
 *   al lado del cuerpo se lo revive.
 * - Granadas: aviso en el chat con [HE]/[SG]/[FB], estela del color de cada una y las flashes de
 *   los compañeros no ciegan.
 *
 * No depende del servicio de Claudia: funciona aunque esté caído.
 * Los sonidos van en sound/claudia/anuncios/ como .wav (o .mp3); si falta alguno, se omite.
 */

#include <amxmodx>
#include <amxmisc>
#include <cstrike>
#include <engine>
#include <fakemeta>
#include <fun>
#include <hamsandwich>
#include <nvault>

#pragma semicolon 1

#define PLUGIN  "Claudia Publico"
#define VERSION "1.1.0"
#define AUTHOR  "Claudia"

#define TASK_LOADOUT  74000   // + id
#define TASK_BOMB_HUD 74100
#define TASK_COUNT    74200   // + segundo (1..10)
#define TASK_VOTE_END 74300
#define TASK_CHANGE   74301
#define TASK_TICK     74302
#define TASK_REVIVE   74303
#define TASK_PLAY     74400
#define TASK_RESPAWN  74500   // + id
#define TASK_TRAIL    74600   // + entidad
#define TASK_SUMMARY  74700   // + id

#define MAX_VOTE_ITEMS 8
#define RECENT_KEY     "cp_mapas_recientes"
#define REVIVE_SPRITE  "sprites/claudia/revivir.spr"
#define REVIVE_TIME    5.0
#define REVIVE_RANGE   72.0
#define BODY_MARK      7431   // pev_iuser4 de los carteles: BODY_MARK + equipo

/* ------------------------------------------------------------------------- */
/* Sonidos                                                                   */
/* ------------------------------------------------------------------------- */

enum _:Sounds
{
	SND_PREPARE,
	SND_FLAWLESS,
	SND_HUMILIATION,
	SND_FIRSTBLOOD,
	SND_HEADSHOT,
	SND_DOUBLE,
	SND_MULTI,
	SND_MEGA,
	SND_ULTRA,
	SND_MONSTER,
	SND_LUDICROUS,
	SND_HOLYSHIT,
	SND_SPREE,
	SND_RAMPAGE,
	SND_DOMINATING,
	SND_UNSTOPPABLE,
	SND_GODLIKE,
	SND_WICKEDSICK
}

// Ruta dentro de sound/, sin extensión.
new const SOUND_FILES[Sounds][] =
{
	"claudia/anuncios/prepare",
	"claudia/anuncios/flawless",
	"claudia/anuncios/humiliation",
	"claudia/anuncios/firstblood",
	"claudia/anuncios/headshot",
	"claudia/anuncios/doublekill",
	"claudia/anuncios/multikill",
	"claudia/anuncios/megakill",
	"claudia/anuncios/ultrakill",
	"claudia/anuncios/monsterkill",
	"claudia/anuncios/ludicrouskill",
	"claudia/anuncios/holyshit",
	"claudia/anuncios/killingspree",
	"claudia/anuncios/rampage",
	"claudia/anuncios/dominating",
	"claudia/anuncios/unstoppable",
	"claudia/anuncios/godlike",
	"claudia/anuncios/wickedsick"
};

// Duración de cada sonido en segundos: los anuncios se encadenan uno detrás del otro sin pisarse.
new const Float:SOUND_LEN[] =
{
	1.12, 2.05, 1.12, 2.29, 1.94, 1.92, 2.19, 2.52, 1.76, 2.73, 2.72, 2.24, 2.28, 2.02, 1.70, 1.92, 1.74, 2.52
};

enum { FMT_NONE, FMT_WAV, FMT_MP3 }
new g_SoundFmt[Sounds];

/* Posiciones verticales del HUD (una fila por tipo de mensaje, para que no se superpongan). */
#define HUD_Y_WARMUP    0.08   // cuenta del calentamiento
#define HUD_Y_KILLS     0.14   // primera sangre, multikills, rachas (de a uno, en cola)
#define HUD_Y_BOMB      0.20   // segundos de la bomba
#define HUD_Y_PLANT     0.26   // aviso a los T de que planten
#define HUD_Y_ROUND     0.34   // flawless victory, fin del calentamiento (2 líneas)
#define HUD_Y_SUMMARY   0.44   // víctimas (izquierda) y atacantes (derecha)
#define HUD_Y_DEALT     0.53   // daño hecho, debajo de la mira
#define HUD_Y_TAKEN     0.57   // daño recibido
#define HUD_Y_REVIVE    0.65   // "X te está reviviendo"
// (claudia_auth usa 0.28 para la cuenta de login, solo para los que todavía no entraron a un equipo)

// Multikills (kills dentro de la ventana): 2 = double ... 8+ = holy shit.
new const MULTI_SOUNDS[] = { SND_DOUBLE, SND_MULTI, SND_MEGA, SND_ULTRA, SND_MONSTER, SND_LUDICROUS, SND_HOLYSHIT };
new const MULTI_NAMES[][] = { "DOUBLE KILL", "MULTI KILL", "MEGA KILL", "ULTRA KILL", "MONSTER KILL", "LUDICROUS KILL", "HOLY SHIT" };

// Rachas (kills seguidas sin morir, igual que las recompensas de Claudia).
new const STREAK_KILLS[] = { 3, 5, 7, 10, 15, 20 };
new const STREAK_SOUNDS[] = { SND_SPREE, SND_RAMPAGE, SND_DOMINATING, SND_UNSTOPPABLE, SND_GODLIKE, SND_WICKEDSICK };
new const STREAK_NAMES[][] = { "KILLING SPREE", "RAMPAGE", "DOMINATING", "UNSTOPPABLE", "GODLIKE", "WICKED SICK" };

// Cuenta regresiva de la bomba: voces que ya trae el juego (valve/sound/fvox).
new const COUNT_WORDS[][] = { "", "one", "two", "three", "four", "five", "six", "seven", "eight", "nine", "ten" };

/* ------------------------------------------------------------------------- */
/* Loadouts                                                                  */
/* ------------------------------------------------------------------------- */

new const LO_NAMES[][] = { "AK-47", "M4A1", "AWP", "Famas", "Galil", "Scout", "MP5" };
new const LO_WEAPONS[][] = { "weapon_ak47", "weapon_m4a1", "weapon_awp", "weapon_famas", "weapon_galil", "weapon_scout", "weapon_mp5navy" };
new const LO_CSW[] = { CSW_AK47, CSW_M4A1, CSW_AWP, CSW_FAMAS, CSW_GALIL, CSW_SCOUT, CSW_MP5NAVY };
new const LO_BPAMMO[] = { 90, 90, 30, 90, 90, 90, 120 };

new g_Choice[MAX_PLAYERS + 1] = { -1, ... };
new bool:g_Auto[MAX_PLAYERS + 1];
new bool:g_ForceMenu[MAX_PLAYERS + 1];
new Float:g_SpawnTime[MAX_PLAYERS + 1];

/* ------------------------------------------------------------------------- */
/* Granadas                                                                  */
/* ------------------------------------------------------------------------- */

enum { NADE_HE, NADE_SG, NADE_FB }
new const NADE_MODELS[][] = { "models/w_hegrenade.mdl", "models/w_smokegrenade.mdl", "models/w_flashbang.mdl" };
new const NADE_COLORS[][3] = { { 255, 40, 40 }, { 40, 255, 60 }, { 170, 170, 170 } };

// Armas de fuego (para contar disparos y la precisión).
new const GUNS[][] =
{
	"weapon_p228", "weapon_scout", "weapon_xm1014", "weapon_mac10", "weapon_aug", "weapon_elite",
	"weapon_fiveseven", "weapon_ump45", "weapon_sg550", "weapon_galil", "weapon_famas", "weapon_usp",
	"weapon_glock18", "weapon_awp", "weapon_mp5navy", "weapon_m249", "weapon_m3", "weapon_m4a1",
	"weapon_tmp", "weapon_g3sg1", "weapon_deagle", "weapon_sg552", "weapon_ak47", "weapon_p90"
};

/* ------------------------------------------------------------------------- */
/* Estado                                                                    */
/* ------------------------------------------------------------------------- */

new g_Streak[MAX_PLAYERS + 1];
new g_Multi[MAX_PLAYERS + 1];
new Float:g_LastKill[MAX_PLAYERS + 1];
new bool:g_FirstBlood;
new g_TeamDeaths[3];
new bool:g_KillSounds[MAX_PLAYERS + 1];
new Float:g_QueueEnd;   // cuándo termina el último anuncio encolado

// Loadout: sigue en la zona de compra desde que apareció, o recién revivido (puede elegir un rato).
new bool:g_PickOk[MAX_PLAYERS + 1];
new Float:g_ReviveGrace[MAX_PLAYERS + 1];

// Daño de la ronda: [atacante][víctima]
new g_Dmg[MAX_PLAYERS + 1][MAX_PLAYERS + 1];
new g_Hits[MAX_PLAYERS + 1][MAX_PLAYERS + 1];
new bool:g_Killed[MAX_PLAYERS + 1][MAX_PLAYERS + 1];
new g_Shots[MAX_PLAYERS + 1];
new g_GunHits[MAX_PLAYERS + 1];
new g_ClipBefore[MAX_PLAYERS + 1];
new g_HealthBefore[MAX_PLAYERS + 1];
new Float:g_DmgTakeBefore[MAX_PLAYERS + 1];

// Revivir
new g_BodySprite[MAX_PLAYERS + 1];
new Float:g_Body[MAX_PLAYERS + 1][3];
new Float:g_BodyTime[MAX_PLAYERS + 1];
new g_Reviving[MAX_PLAYERS + 1];
new Float:g_ReviveStart[MAX_PLAYERS + 1];

// Calentamiento
new bool:g_Warmup = true;
new bool:g_WarmupRunning;
new Float:g_WarmupEnd;

// Flashes
new g_FlashOwner;
new Float:g_FlashTime;
new g_TrailSprite;

new bool:g_BombMap;
new bool:g_RoundActive;
new Float:g_RoundStart;
new Float:g_RoundTime;
new bool:g_PlantWarned;
new bool:g_Planted;
new Float:g_Explode;

// Votación de mapa
new Array:g_Maps;
new g_VoteMenu = -1;
new bool:g_Voting;
new bool:g_VoteDone;
new bool:g_VoteIsRtv;
new g_VoteCount;
new g_VoteMaps[MAX_VOTE_ITEMS][32];
new g_Votes[MAX_VOTE_ITEMS + 1];
new bool:g_HasExtend;
new bool:g_Voted[MAX_PLAYERS + 1];
new g_Extends;
new bool:g_Rtv[MAX_PLAYERS + 1];
new g_NextMap[32];

new g_HudDealt, g_HudTaken, g_HudVictims, g_HudAttackers;
new g_Vault = INVALID_HANDLE;
new g_msgBarTime;

new g_pLoadoutTime, g_pVoteTime, g_pVoteStart, g_pVoteMaps, g_pExtendMinutes, g_pExtendMax;
new g_pRtvRatio, g_pRtvDelay, g_pRecentMaps, g_pPlantWarn, g_pMultiWindow, g_pSounds;
new g_pWarmupTime, g_pWarmupActive, g_pReviveScale, g_pReviveHeight;

public plugin_precache()
{
	new path[96];
	for (new i = 0; i < Sounds; i++)
	{
		formatex(path, charsmax(path), "sound/%s.wav", SOUND_FILES[i]);
		if (file_exists(path))
		{
			formatex(path, charsmax(path), "%s.wav", SOUND_FILES[i]);
			precache_sound(path);
			g_SoundFmt[i] = FMT_WAV;
			continue;
		}
		formatex(path, charsmax(path), "sound/%s.mp3", SOUND_FILES[i]);
		if (file_exists(path))
		{
			precache_generic(path);
			g_SoundFmt[i] = FMT_MP3;
		}
	}
	precache_model(REVIVE_SPRITE);
	g_TrailSprite = precache_model("sprites/laserbeam.spr");
}

public plugin_init()
{
	register_plugin(PLUGIN, VERSION, AUTHOR);

	g_pLoadoutTime   = register_cvar("cp_loadout_time", "15");
	g_pVoteTime      = register_cvar("cp_vote_time", "20");
	g_pVoteStart     = register_cvar("cp_vote_start", "180");
	g_pVoteMaps      = register_cvar("cp_vote_maps", "5");
	g_pExtendMinutes = register_cvar("cp_extend_minutes", "15");
	g_pExtendMax     = register_cvar("cp_extend_max", "2");
	g_pRtvRatio      = register_cvar("cp_rtv_ratio", "0.6");
	g_pRtvDelay      = register_cvar("cp_rtv_delay", "120");
	g_pRecentMaps    = register_cvar("cp_recent_maps", "3");
	g_pPlantWarn     = register_cvar("cp_plant_warn", "20");
	g_pMultiWindow   = register_cvar("cp_multikill_window", "3.0");
	g_pSounds        = register_cvar("cp_sounds", "1");
	g_pWarmupTime    = register_cvar("cp_warmup_time", "60");
	// Lo leen otros plugins (claudia_stats no cuenta las kills del calentamiento).
	g_pWarmupActive  = register_cvar("cp_warmup_active", "1");
	g_pReviveScale   = register_cvar("cp_revive_scale", "0.15");
	g_pReviveHeight  = register_cvar("cp_revive_height", "40");

	RegisterHam(Ham_Spawn, "player", "fw_spawn_post", true);
	RegisterHam(Ham_TakeDamage, "player", "fw_take_damage_pre", false);
	RegisterHam(Ham_TakeDamage, "player", "fw_take_damage_post", true);
	RegisterHam(Ham_Think, "grenade", "fw_grenade_think");
	for (new i = 0; i < sizeof GUNS; i++)
	{
		RegisterHam(Ham_Weapon_PrimaryAttack, GUNS[i], "fw_attack_pre", false);
		RegisterHam(Ham_Weapon_PrimaryAttack, GUNS[i], "fw_attack_post", true);
	}
	register_forward(FM_SetModel, "fw_set_model", true);
	register_forward(FM_AddToFullPack, "fw_add_to_full_pack", true);

	register_event("HLTV", "ev_new_round", "a", "1=0", "2=0");
	register_logevent("ev_round_start", 2, "1=Round_Start");
	register_logevent("ev_round_end", 2, "1=Round_End");
	register_event("DeathMsg", "ev_death", "a");
	register_event("SendAudio", "ev_bomb_planted", "a", "2&%!MRAD_BOMBPL");
	register_event("SendAudio", "ev_bomb_defused", "a", "2&%!MRAD_BOMBDEF");
	register_event("SendAudio", "ev_t_win", "a", "2&%!MRAD_terwin");
	register_event("SendAudio", "ev_ct_win", "a", "2&%!MRAD_ctwin");

	register_message(get_user_msgid("TextMsg"), "msg_text");
	register_message(get_user_msgid("ScreenFade"), "msg_screen_fade");
	g_msgBarTime = get_user_msgid("BarTime");

	register_clcmd("say", "cmd_say");
	register_clcmd("say_team", "cmd_say");
	register_clcmd("cp_loadout", "cmd_loadout");

	g_HudDealt = CreateHudSyncObj();
	g_HudTaken = CreateHudSyncObj();
	g_HudVictims = CreateHudSyncObj();
	g_HudAttackers = CreateHudSyncObj();

	g_BombMap = find_ent_by_class(-1, "func_bomb_target") > 0 || find_ent_by_class(-1, "info_bomb_target") > 0;
	g_Vault = nvault_open("claudia_publico");

	load_maps();
	set_task(1.0, "task_tick", TASK_TICK, _, _, "b");
	set_task(0.1, "task_revive", TASK_REVIVE, _, _, "b");
}

public plugin_cfg()
{
	new dir[128];
	get_configsdir(dir, charsmax(dir));
	server_cmd("exec %s/claudia/publico.cfg", dir);
	server_exec();
	remember_map();
	g_Warmup = get_pcvar_num(g_pWarmupTime) > 0;
	set_pcvar_num(g_pWarmupActive, g_Warmup ? 1 : 0);
}

public plugin_end()
{
	if (g_Maps != Invalid_Array)
	{
		ArrayDestroy(g_Maps);
	}
	if (g_Vault != INVALID_HANDLE)
	{
		nvault_close(g_Vault);
	}
}

public client_putinserver(id)
{
	g_Choice[id] = -1;
	g_Auto[id] = false;
	g_ForceMenu[id] = false;
	g_PickOk[id] = false;
	g_ReviveGrace[id] = 0.0;
	g_Streak[id] = 0;
	g_Multi[id] = 0;
	g_Voted[id] = false;
	g_Rtv[id] = false;
	g_Reviving[id] = 0;
	reset_damage(id);

	g_KillSounds[id] = true;
	new key[48], value[4];
	sound_key(id, key, charsmax(key));
	if (g_Vault != INVALID_HANDLE && nvault_get(g_Vault, key, value, charsmax(value)))
	{
		g_KillSounds[id] = value[0] != '0';
	}
}

public client_disconnected(id)
{
	remove_task(TASK_LOADOUT + id);
	remove_task(TASK_RESPAWN + id);
	remove_task(TASK_SUMMARY + id);
	remove_body(id);
	g_Rtv[id] = false;
	g_Reviving[id] = 0;
	reset_damage(id);
}

sound_key(id, key[], len)
{
	new name[32];
	get_user_name(id, name, charsmax(name));
	formatex(key, len, "sonidos_%s", name);
}

/* ========================================================================= */
/* Chat                                                                      */
/* ========================================================================= */

public cmd_say(id)
{
	new text[64];
	read_args(text, charsmax(text));
	remove_quotes(text);
	trim(text);

	new cmd[32];
	copy(cmd, charsmax(cmd), text[(text[0] == '/' || text[0] == '!') ? 1 : 0]);
	strtolower(cmd);

	if (equal(cmd, "loadout") || equal(cmd, "armas") || equal(cmd, "guns"))
	{
		if (is_user_alive(id) && !can_pick(id))
		{
			client_print_color(id, print_team_default, "^4[Loadout]^1 Ya saliste de la zona de compra: lo que elijas te lo doy la próxima ronda.");
		}
		show_loadout_menu(id);
		return PLUGIN_HANDLED;
	}
	if (equal(cmd, "sonidos") || equal(cmd, "sounds"))
	{
		g_KillSounds[id] = !g_KillSounds[id];
		new key[48];
		sound_key(id, key, charsmax(key));
		if (g_Vault != INVALID_HANDLE)
		{
			nvault_set(g_Vault, key, g_KillSounds[id] ? "1" : "0");
		}
		client_print_color(id, print_team_default, "^4[Sonidos]^1 Sonidos de muertes %s.", g_KillSounds[id] ? "^4activados^1" : "^3desactivados^1");
		return PLUGIN_HANDLED;
	}
	if (equal(cmd, "rtv") || equal(cmd, "rockthevote"))
	{
		rock_the_vote(id);
		return PLUGIN_CONTINUE;
	}
	return PLUGIN_CONTINUE;
}

public cmd_loadout(id)
{
	show_loadout_menu(id);
	return PLUGIN_HANDLED;
}

/* ========================================================================= */
/* Loadouts                                                                  */
/* ========================================================================= */

public fw_spawn_post(id)
{
	if (!is_user_alive(id))
	{
		return;
	}
	remove_body(id);
	remove_task(TASK_RESPAWN + id);
	if (is_user_bot(id))
	{
		return;
	}
	new CsTeams:team = cs_get_user_team(id);
	if (team != CS_TEAM_T && team != CS_TEAM_CT)
	{
		return;
	}
	g_SpawnTime[id] = get_gametime();
	g_PickOk[id] = true;
	remove_task(TASK_LOADOUT + id);
	// Un toque de espera: el juego reparte la bomba después de hacer aparecer a los jugadores.
	set_task(0.3, "task_loadout", TASK_LOADOUT + id);
}

public task_loadout(taskid)
{
	new id = taskid - TASK_LOADOUT;
	if (!is_user_alive(id))
	{
		return;
	}
	if (g_Auto[id] && g_Choice[id] >= 0 && !g_ForceMenu[id])
	{
		give_loadout(id, g_Choice[id]);
		client_print_color(id, print_team_default, "^4[Loadout]^1 %s + Deagle y granadas. Escribí ^4/loadout^1 para cambiarlo.", LO_NAMES[g_Choice[id]]);
		return;
	}
	g_ForceMenu[id] = false;
	show_loadout_menu(id);
}

show_loadout_menu(id)
{
	if (!is_user_connected(id))
	{
		return;
	}
	new menu = menu_create("\yLoadout \d(Deagle, chaleco y granadas incluidas)", "loadout_handler");
	new label[64];
	for (new i = 0; i < sizeof LO_NAMES; i++)
	{
		formatex(label, charsmax(label), "%s%s", LO_NAMES[i], g_Choice[id] == i ? " \y*" : "");
		menu_additem(menu, label);
	}
	formatex(label, charsmax(label), "Darme siempre el mismo: %s", g_Auto[id] ? "\ySí" : "\rNo");
	menu_additem(menu, label);
	menu_setprop(menu, MPROP_PERPAGE, 0);
	menu_setprop(menu, MPROP_EXIT, MEXIT_FORCE);
	menu_setprop(menu, MPROP_EXITNAME, "Cerrar");
	menu_display(id, menu, 0, max(1, get_pcvar_num(g_pLoadoutTime)));
}

public loadout_handler(id, menu, item)
{
	menu_destroy(menu);
	if (item < 0 || !is_user_connected(id))
	{
		return PLUGIN_HANDLED;
	}
	if (item == sizeof LO_NAMES)
	{
		g_Auto[id] = !g_Auto[id];
		if (g_Auto[id] && g_Choice[id] < 0)
		{
			client_print_color(id, print_team_default, "^4[Loadout]^1 Elegí un arma y te la doy sola cada ronda.");
		}
		show_loadout_menu(id);
		return PLUGIN_HANDLED;
	}
	g_Choice[id] = item;
	if (can_pick(id))
	{
		give_loadout(id, item);
	}
	else
	{
		client_print_color(id, print_team_default, "^4[Loadout]^1 Te doy ^4%s^1 la próxima vez que aparezcas.", LO_NAMES[item]);
	}
	return PLUGIN_HANDLED;
}

/**
 * Puede elegir (o cambiar) el arma ahora: no salió de la zona de compra desde que apareció,
 * o lo acaban de revivir (aparece lejos de la zona y tiene cp_loadout_time segundos).
 */
bool:can_pick(id)
{
	if (!is_user_alive(id))
	{
		return false;
	}
	if (get_gametime() < g_ReviveGrace[id])
	{
		return true;
	}
	return g_PickOk[id] && cs_get_user_buyzone(id) != 0;
}

/** Cada 0,1 s: el que sale de la zona de compra ya no puede cambiar el arma hasta la próxima ronda. */
check_buyzones()
{
	new players[MAX_PLAYERS], num;
	get_players(players, num, "ach");
	new Float:now = get_gametime();
	for (new i = 0; i < num; i++)
	{
		new id = players[i];
		// El recién aparecido puede no tener todavía la señal de zona de compra.
		if (g_PickOk[id] && now - g_SpawnTime[id] > 0.5 && now >= g_ReviveGrace[id] && !cs_get_user_buyzone(id))
		{
			g_PickOk[id] = false;
		}
	}
}

give_loadout(id, idx)
{
	new bool:hasC4 = bool:user_has_weapon(id, CSW_C4);
	strip_user_weapons(id);
	give_item(id, "weapon_knife");
	give_item(id, "weapon_hegrenade");
	give_item(id, "weapon_flashbang");
	give_item(id, "weapon_smokegrenade");
	cs_set_user_bpammo(id, CSW_FLASHBANG, 2);
	give_item(id, "weapon_deagle");
	cs_set_user_bpammo(id, CSW_DEAGLE, 35);
	give_item(id, LO_WEAPONS[idx]);
	cs_set_user_bpammo(id, LO_CSW[idx], LO_BPAMMO[idx]);
	cs_set_user_armor(id, 100, CS_ARMOR_VESTHELM);
	if (cs_get_user_team(id) == CS_TEAM_CT)
	{
		cs_set_user_defuse(id, 1);
	}
	if (hasC4)
	{
		give_item(id, "weapon_c4");
		cs_set_user_plant(id, 1, 1);
	}
}

/* ========================================================================= */
/* Calentamiento                                                             */
/* ========================================================================= */

warmup_tick()
{
	new players[MAX_PLAYERS], num;
	get_players(players, num, "h");
	if (!g_WarmupRunning)
	{
		// Arranca cuando el primer jugador entra a un equipo (al cambiar de mapa tardan en conectarse).
		for (new i = 0; i < num; i++)
		{
			new team = get_user_team(players[i]);
			if (!is_user_bot(players[i]) && (team == 1 || team == 2))
			{
				g_WarmupRunning = true;
				g_WarmupEnd = get_gametime() + float(get_pcvar_num(g_pWarmupTime));
				break;
			}
		}
		if (!g_WarmupRunning)
		{
			return;
		}
	}

	new left = floatround(g_WarmupEnd - get_gametime(), floatround_ceil);
	if (left <= 0)
	{
		end_warmup();
		return;
	}
	set_dhudmessage(255, 200, 0, -1.0, HUD_Y_WARMUP, 0, 0.0, 1.0, 0.0, 0.1);
	show_dhudmessage(0, "CALENTAMIENTO - %d s^nSe revive al instante", left);

	for (new i = 0; i < num; i++)
	{
		new id = players[i];
		new team = get_user_team(id);
		if (!is_user_alive(id) && (team == 1 || team == 2) && !task_exists(TASK_RESPAWN + id) && can_respawn(id))
		{
			set_task(1.0, "task_respawn", TASK_RESPAWN + id);
		}
	}
}

/** Ya eligió equipo y modelo (no está en el menú de selección). */
bool:can_respawn(id)
{
	return get_ent_data(id, "CBasePlayer", "m_iJoiningState") == 0 && get_ent_data(id, "CBasePlayer", "m_iMenu") != 3;
}

public task_respawn(taskid)
{
	new id = taskid - TASK_RESPAWN;
	new team = get_user_team(id);
	if (g_Warmup && is_user_connected(id) && !is_user_alive(id) && (team == 1 || team == 2))
	{
		ExecuteHamB(Ham_CS_RoundRespawn, id);
	}
}

end_warmup()
{
	g_Warmup = false;
	g_WarmupRunning = false;
	set_pcvar_num(g_pWarmupActive, 0);
	for (new id = 1; id <= MAX_PLAYERS; id++)
	{
		remove_task(TASK_RESPAWN + id);
		g_Streak[id] = 0;
		g_Multi[id] = 0;
	}
	set_dhudmessage(0, 255, 120, -1.0, HUD_Y_ROUND, 0, 0.0, 4.0, 0.1, 0.5);
	show_dhudmessage(0, "¡Terminó el calentamiento!^nArranca la partida");
	server_cmd("sv_restartround 1");
}

/* ========================================================================= */
/* Rondas y bomba                                                            */
/* ========================================================================= */

public ev_new_round()
{
	// Resumen de la ronda para los que terminaron vivos (los muertos ya lo vieron al morir).
	for (new id = 1; id <= MAX_PLAYERS; id++)
	{
		if (is_user_connected(id) && is_user_alive(id) && !is_user_bot(id))
		{
			show_summary(id);
		}
		remove_body(id);
		g_Reviving[id] = 0;
	}
	for (new id = 1; id <= MAX_PLAYERS; id++)
	{
		reset_damage(id);
	}
	g_FirstBlood = false;
	g_TeamDeaths[1] = 0;
	g_TeamDeaths[2] = 0;
	stop_bomb();
	// Anuncios de la ronda anterior que quedaron en cola: ya no tienen sentido.
	remove_task(TASK_PLAY);
	g_QueueEnd = 0.0;
	enqueue(SND_PREPARE, false, 0.5, 0, 0, 0, "");
}

public ev_round_start()
{
	g_RoundActive = true;
	g_RoundStart = get_gametime();
	g_RoundTime = get_cvar_float("mp_roundtime") * 60.0;
	g_PlantWarned = false;
	stop_bomb();
}

public ev_round_end()
{
	g_RoundActive = false;
	stop_bomb();
}

public ev_bomb_planted()
{
	if (g_Planted)
	{
		return;
	}
	new Float:timer = get_cvar_float("mp_c4timer");
	g_Planted = true;
	g_Explode = get_gametime() + timer;
	task_bomb_hud();
	set_task(1.0, "task_bomb_hud", TASK_BOMB_HUD, _, _, "b");
	for (new n = 1; n <= 10; n++)
	{
		if (timer - float(n) > 0.0)
		{
			set_task(timer - float(n), "task_count", TASK_COUNT + n);
		}
	}
}

public ev_bomb_defused()
{
	stop_bomb();
}

stop_bomb()
{
	g_Planted = false;
	remove_task(TASK_BOMB_HUD);
	for (new n = 1; n <= 10; n++)
	{
		remove_task(TASK_COUNT + n);
	}
}

public task_bomb_hud()
{
	new left = floatround(g_Explode - get_gametime(), floatround_ceil);
	if (!g_Planted || left <= 0)
	{
		remove_task(TASK_BOMB_HUD);
		return;
	}
	// Lo ven los dos equipos (y los espectadores), cada uno con su texto.
	set_dhudmessage(255, 40, 40, -1.0, HUD_Y_BOMB, 0, 0.0, 0.95, 0.0, 0.0);
	new players[MAX_PLAYERS], num;
	get_players(players, num, "ch");
	for (new i = 0; i < num; i++)
	{
		switch (get_user_team(players[i]))
		{
			case 2: show_dhudmessage(players[i], "Apurate a desactivar la bomba, quedan %d segundos", left);
			case 1: show_dhudmessage(players[i], "Defendé la bomba, explota en %d segundos", left);
			default: show_dhudmessage(players[i], "La bomba explota en %d segundos", left);
		}
	}
}

public task_count(taskid)
{
	new n = taskid - TASK_COUNT;
	if (g_Planted && 1 <= n <= 10)
	{
		client_cmd(0, "spk ^"fvox/%s^"", COUNT_WORDS[n]);
	}
}

/** Cada segundo: calentamiento, aviso de plantar a los T y votación de mapa. */
public task_tick()
{
	if (g_Warmup)
	{
		warmup_tick();
	}

	if (g_BombMap && g_RoundActive && !g_Planted && !g_PlantWarned && !g_Warmup)
	{
		new left = floatround(g_RoundTime - (get_gametime() - g_RoundStart), floatround_ceil);
		if (left > 0 && left <= get_pcvar_num(g_pPlantWarn))
		{
			g_PlantWarned = true;
			plant_warn(left);
		}
	}

	if (!g_Voting && !g_VoteDone && get_cvar_float("mp_timelimit") > 0.0)
	{
		new left = get_timeleft();
		if (left > 0 && left <= get_pcvar_num(g_pVoteStart))
		{
			start_vote(false);
		}
	}
}

plant_warn(left)
{
	set_dhudmessage(255, 140, 0, -1.0, HUD_Y_PLANT, 1, 0.5, 4.0, 0.1, 0.3);
	new players[MAX_PLAYERS], num;
	get_players(players, num, "aeh", "TERRORIST");
	for (new i = 0; i < num; i++)
	{
		show_dhudmessage(players[i], "Apurate a plantar la bomba, dale^nQuedan %d segundos", left);
	}
}

public ev_t_win()
{
	check_flawless(1);
}

public ev_ct_win()
{
	check_flawless(2);
}

check_flawless(winner)
{
	if (g_Warmup)
	{
		return;
	}
	new loser = winner == 1 ? 2 : 1;
	new players[MAX_PLAYERS], wn, ln;
	get_players(players, wn, "eh", winner == 1 ? "TERRORIST" : "CT");
	get_players(players, ln, "eh", loser == 1 ? "TERRORIST" : "CT");
	if (wn == 0 || ln == 0 || g_TeamDeaths[winner] > 0)
	{
		return;
	}
	set_dhudmessage(winner == 1 ? 255 : 60, 80, winner == 1 ? 60 : 255, -1.0, HUD_Y_ROUND, 1, 0.5, 4.0, 0.1, 0.3);
	show_dhudmessage(0, "FLAWLESS VICTORY^n%s ganaron sin perder a nadie", winner == 1 ? "Los terroristas" : "Los anti-terroristas");
	// Después de la radio de "Terrorists/Counter-Terrorists Win" (y de los anuncios de la última kill).
	enqueue(SND_FLAWLESS, false, 1.6, 0, 0, 0, "");
}

/* ========================================================================= */
/* Muertes: rachas, multikills, primera sangre, humillación                  */
/* ========================================================================= */

public ev_death()
{
	new killer = read_data(1);
	new victim = read_data(2);
	new headshot = read_data(3);
	new weapon[24];
	read_data(4, weapon, charsmax(weapon));

	if (1 <= victim <= MAX_PLAYERS)
	{
		g_Streak[victim] = 0;
		g_Multi[victim] = 0;
		g_Reviving[victim] = 0;
		new team = get_user_team(victim);
		if (team == 1 || team == 2)
		{
			g_TeamDeaths[team]++;
			if (!g_Warmup && g_RoundActive)
			{
				place_body(victim, team);
			}
		}
		if (1 <= killer <= MAX_PLAYERS && killer != victim)
		{
			g_Killed[killer][victim] = true;
		}
		if (!is_user_bot(victim))
		{
			// El último golpe se anota después del DeathMsg (TakeDamage termina después de Killed).
			set_task(0.1, "task_summary", TASK_SUMMARY + victim);
		}
	}
	if (!(1 <= killer <= MAX_PLAYERS) || killer == victim || !is_user_connected(killer)
		|| !is_user_connected(victim) || get_user_team(killer) == get_user_team(victim) || g_Warmup)
	{
		return;
	}

	new Float:now = get_gametime();
	g_Multi[killer] = now - g_LastKill[killer] <= get_pcvar_float(g_pMultiWindow) ? g_Multi[killer] + 1 : 1;
	g_LastKill[killer] = now;
	g_Streak[killer]++;

	new name[32], vname[32];
	get_user_name(killer, name, charsmax(name));
	get_user_name(victim, vname, charsmax(vname));

	// Orden de cada kill: primera sangre, humillación, multikill y racha. Cada anuncio (cartel y
	// sonido juntos) espera a que termine el anterior, también los de kills anteriores.
	new text[96];
	new bool:played = false;

	if (!g_FirstBlood)
	{
		g_FirstBlood = true;
		formatex(text, charsmax(text), "%s hizo la primera sangre", name);
		played = enqueue(SND_FIRSTBLOOD, true, 0.0, 255, 60, 60, text) || played;
	}
	if (equal(weapon, "knife"))
	{
		formatex(text, charsmax(text), "%s humilló a %s con el cuchillo", name, vname);
		played = enqueue(SND_HUMILIATION, true, 0.0, 255, 200, 0, text) || played;
	}
	if (g_Multi[killer] >= 2)
	{
		new m = min(g_Multi[killer], sizeof MULTI_SOUNDS + 1) - 2;
		formatex(text, charsmax(text), "%s: %s", name, MULTI_NAMES[m]);
		played = enqueue(MULTI_SOUNDS[m], true, 0.0, 255, 120, 0, text) || played;
	}
	for (new i = 0; i < sizeof STREAK_KILLS; i++)
	{
		if (g_Streak[killer] == STREAK_KILLS[i])
		{
			formatex(text, charsmax(text), "%s: %s (%d kills seguidas)", name, STREAK_NAMES[i], g_Streak[killer]);
			played = enqueue(STREAK_SOUNDS[i], true, 0.0, 0, 200, 255, text) || played;
			break;
		}
	}
	// El headshot es solo para el que lo hizo y únicamente si no hay otro anuncio sonando.
	if (headshot && !played && get_gametime() >= g_QueueEnd)
	{
		play_to(killer, SND_HEADSHOT, 0.0, true);
	}
}

/**
 * Encola un anuncio: el sonido y (si hay texto) el cartel salen juntos cuando termina el anterior.
 * min_delay = espera mínima desde ahora. Si la cola ya está muy atrasada, el anuncio se descarta
 * (para no escuchar un "double kill" 10 segundos después). Devuelve si se encoló.
 */
bool:enqueue(snd, bool:kill, Float:min_delay, r, g, b, const text[])
{
	new Float:now = get_gametime();
	new Float:delay = floatmax(min_delay, g_QueueEnd - now);
	if (delay > 6.0)
	{
		return false;
	}
	g_QueueEnd = now + delay + SOUND_LEN[snd] + 0.2;
	new params[102];
	params[0] = snd;
	params[1] = kill;
	params[2] = r;
	params[3] = g;
	params[4] = b;
	copy(params[5], charsmax(params) - 5, text);
	if (delay <= 0.0)
	{
		task_announce(params);
	}
	else
	{
		set_task(delay, "task_announce", TASK_PLAY, params, sizeof params);
	}
	return true;
}

public task_announce(const params[])
{
	new snd = params[0];
	if (params[5])
	{
		// Dura lo mismo que el sonido: el siguiente cartel sale en la misma fila sin superponerse.
		set_dhudmessage(params[2], params[3], params[4], -1.0, HUD_Y_KILLS, 0, 0.0, SOUND_LEN[snd], 0.05, 0.15);
		show_dhudmessage(0, "%s", params[5]);
	}
	if (get_pcvar_num(g_pSounds) && g_SoundFmt[snd] != FMT_NONE)
	{
		do_play(0, snd, bool:params[1]);
	}
}

/* ========================================================================= */
/* Daño: debajo de la mira y resumen de la ronda                             */
/* ========================================================================= */

public fw_take_damage_pre(victim, inflictor, attacker, Float:damage, bits)
{
	g_HealthBefore[victim] = get_user_health(victim);
	g_DmgTakeBefore[victim] = entity_get_float(victim, EV_FL_dmg_take);
	return HAM_IGNORED;
}

/**
 * Daño aplicado (ya descontado el chaleco): recibido en rojo y hecho en azul, debajo de la mira.
 * Sale de pev->dmg_take, que el juego suma sin recortar a la vida que quedaba (un AWP al cuerpo
 * muestra todo el daño, no 100). Si no cambió, se usa la vida perdida.
 */
public fw_take_damage_post(victim, inflictor, attacker, Float:damage, bits)
{
	new dmg = floatround(entity_get_float(victim, EV_FL_dmg_take) - g_DmgTakeBefore[victim]);
	if (dmg <= 0)
	{
		dmg = g_HealthBefore[victim] - max(0, get_user_health(victim));
	}
	if (dmg <= 0)
	{
		return HAM_IGNORED;
	}
	if (!is_user_bot(victim))
	{
		set_hudmessage(200, 40, 40, -1.0, HUD_Y_TAKEN, 0, 0.0, 1.0, 0.0, 0.3, -1);
		ShowSyncHudMsg(victim, g_HudTaken, "%d", dmg);
	}
	if (!(1 <= attacker <= MAX_PLAYERS) || attacker == victim || !is_user_connected(attacker))
	{
		return HAM_IGNORED;
	}
	g_Dmg[attacker][victim] += dmg;
	g_Hits[attacker][victim]++;
	// Con arma de fuego el que inflige es el propio atacante (con HE es la granada).
	if (inflictor == attacker && get_user_weapon(attacker) != CSW_KNIFE)
	{
		g_GunHits[attacker]++;
	}
	if (!is_user_bot(attacker))
	{
		set_hudmessage(50, 110, 220, -1.0, HUD_Y_DEALT, 0, 0.0, 1.0, 0.0, 0.3, -1);
		ShowSyncHudMsg(attacker, g_HudDealt, "%d", dmg);
	}
	return HAM_IGNORED;
}

public task_summary(taskid)
{
	new id = taskid - TASK_SUMMARY;
	if (is_user_connected(id))
	{
		show_summary(id);
	}
}

public fw_attack_pre(weapon)
{
	new id = get_ent_data_entity(weapon, "CBasePlayerItem", "m_pPlayer");
	if (1 <= id <= MAX_PLAYERS)
	{
		g_ClipBefore[id] = get_ent_data(weapon, "CBasePlayerWeapon", "m_iClip");
	}
	return HAM_IGNORED;
}

public fw_attack_post(weapon)
{
	new id = get_ent_data_entity(weapon, "CBasePlayerItem", "m_pPlayer");
	if (1 <= id <= MAX_PLAYERS && get_ent_data(weapon, "CBasePlayerWeapon", "m_iClip") < g_ClipBefore[id])
	{
		g_Shots[id]++;
	}
	return HAM_IGNORED;
}

reset_damage(id)
{
	for (new i = 0; i <= MAX_PLAYERS; i++)
	{
		g_Dmg[id][i] = 0;
		g_Hits[id][i] = 0;
		g_Killed[id][i] = false;
	}
	g_Shots[id] = 0;
	g_GunHits[id] = 0;
}

/** Nombre recortado a 14 caracteres para que el resumen de la derecha entre en pantalla. */
short_name(id, name[], len)
{
	get_user_name(id, name, len);
	if (strlen(name) > 14)
	{
		// Sin partir un carácter UTF-8 a la mitad.
		new cut = 13;
		while (cut > 0 && (name[cut] & 0xC0) == 0x80)
		{
			cut--;
		}
		name[cut] = 0;
		add(name, len, ".");
	}
}

/** Víctimas (con daño, impactos y precisión) a la izquierda; atacantes a la derecha. */
show_summary(id)
{
	new text[512], len, name[32];
	new acc = g_Shots[id] > 0 ? min(100, g_GunHits[id] * 100 / g_Shots[id]) : 0;
	len = formatex(text, charsmax(text), "Víctimas - precisión %d%% (%d/%d)^nnombre: daño (impactos)^n", acc, g_GunHits[id], g_Shots[id]);
	new lines = 0;
	for (new v = 1; v <= MAX_PLAYERS && len < charsmax(text) - 64; v++)
	{
		if (v == id || (g_Dmg[id][v] == 0 && !g_Killed[id][v]) || !is_user_connected(v))
		{
			continue;
		}
		short_name(v, name, charsmax(name));
		len += formatex(text[len], charsmax(text) - len, "%s: %d (%d)%s^n", name, g_Dmg[id][v], g_Hits[id][v], g_Killed[id][v] ? " - muerto" : "");
		lines++;
	}
	if (lines > 0)
	{
		set_hudmessage(80, 140, 255, 0.02, HUD_Y_SUMMARY, 0, 0.0, 6.0, 0.1, 0.5, -1);
		ShowSyncHudMsg(id, g_HudVictims, "%s", text);
	}

	len = formatex(text, charsmax(text), "Atacantes^nnombre: daño (impactos)^n");
	lines = 0;
	for (new a = 1; a <= MAX_PLAYERS && len < charsmax(text) - 64; a++)
	{
		if (a == id || g_Dmg[a][id] == 0 || !is_user_connected(a))
		{
			continue;
		}
		short_name(a, name, charsmax(name));
		len += formatex(text[len], charsmax(text) - len, "%s: %d (%d)%s^n", name, g_Dmg[a][id], g_Hits[a][id], g_Killed[a][id] ? " - te mató" : "");
		lines++;
	}
	if (lines > 0)
	{
		set_hudmessage(255, 90, 90, 0.70, HUD_Y_SUMMARY, 0, 0.0, 6.0, 0.1, 0.5, -1);
		ShowSyncHudMsg(id, g_HudAttackers, "%s", text);
	}
}

/* ========================================================================= */
/* Revivir                                                                   */
/* ========================================================================= */

/** Guarda dónde quedó el cuerpo y pone el cartel "REVIVIR" encima (lo ven solo los compañeros). */
place_body(id, team)
{
	remove_body(id);
	new ent = create_entity("info_target");
	if (!is_valid_ent(ent))
	{
		return;
	}
	entity_set_string(ent, EV_SZ_classname, "cp_revivir");
	entity_set_model(ent, REVIVE_SPRITE);
	entity_set_int(ent, EV_INT_movetype, MOVETYPE_NONE);
	entity_set_int(ent, EV_INT_solid, SOLID_NOT);
	entity_set_int(ent, EV_INT_rendermode, kRenderTransAdd);
	entity_set_float(ent, EV_FL_renderamt, 200.0);
	entity_set_float(ent, EV_FL_scale, get_pcvar_float(g_pReviveScale));
	entity_set_int(ent, EV_INT_iuser4, BODY_MARK + team);
	g_BodySprite[id] = ent;
	g_BodyTime[id] = get_gametime();
	update_body(id);
}

/** Ubica el cuerpo en el piso debajo del jugador muerto y pone el cartel encima. */
update_body(id)
{
	new Float:start[3], Float:end[3];
	entity_get_vector(id, EV_VEC_origin, start);
	end[0] = start[0];
	end[1] = start[1];
	end[2] = start[2] - 4096.0;
	engfunc(EngFunc_TraceLine, start, end, IGNORE_MONSTERS, id, 0);
	get_tr2(0, TR_vecEndPos, end);
	g_Body[id] = end;
	if (g_BodySprite[id] && is_valid_ent(g_BodySprite[id]))
	{
		end[2] += get_pcvar_float(g_pReviveHeight);
		entity_set_origin(g_BodySprite[id], end);
	}
}

/**
 * Mientras dura la animación de muerte el jugador todavía se mueve (cae, se desliza): el cartel
 * lo sigue hasta que pasa a espectador, que es cuando el juego deja el cadáver fijo.
 */
follow_bodies()
{
	new Float:now = get_gametime();
	for (new id = 1; id <= MAX_PLAYERS; id++)
	{
		if (g_BodySprite[id] && now - g_BodyTime[id] < 4.0 && is_user_connected(id) && !is_user_alive(id)
			&& entity_get_int(id, EV_INT_iuser1) == 0)
		{
			update_body(id);
		}
	}
}

remove_body(id)
{
	if (g_BodySprite[id] && is_valid_ent(g_BodySprite[id]))
	{
		remove_entity(g_BodySprite[id]);
	}
	g_BodySprite[id] = 0;
	for (new r = 1; r <= MAX_PLAYERS; r++)
	{
		if (g_Reviving[r] == id)
		{
			cancel_revive(r);
		}
	}
}

public fw_add_to_full_pack(es, e, ent, host, hostflags, player, pset)
{
	if (player || !get_orig_retval() || !(1 <= host <= MAX_PLAYERS))
	{
		return FMRES_IGNORED;
	}
	new mark = entity_get_int(ent, EV_INT_iuser4);
	if ((mark == BODY_MARK + 1 || mark == BODY_MARK + 2) && get_user_team(host) != mark - BODY_MARK)
	{
		set_es(es, ES_Effects, get_es(es, ES_Effects) | EF_NODRAW);
	}
	return FMRES_IGNORED;
}

/** Cada 0,1 s: zona de compra, carteles que siguen al cuerpo y los vivos que mantienen E al lado del cuerpo de un compañero lo reviven. */
public task_revive()
{
	check_buyzones();
	follow_bodies();
	if (g_Warmup)
	{
		return;
	}
	new players[MAX_PLAYERS], num;
	get_players(players, num, "ach");
	new Float:now = get_gametime();
	for (new i = 0; i < num; i++)
	{
		new id = players[i];
		new target = 0;
		if (g_RoundActive && (get_user_button(id) & IN_USE))
		{
			target = find_body(id);
		}
		if (!target)
		{
			if (g_Reviving[id])
			{
				cancel_revive(id);
			}
			continue;
		}
		if (g_Reviving[id] != target)
		{
			if (g_Reviving[id])
			{
				cancel_revive(id);
			}
			g_Reviving[id] = target;
			g_ReviveStart[id] = now;
			bar_time(id, floatround(REVIVE_TIME));
			new name[32];
			get_user_name(id, name, charsmax(name));
			set_dhudmessage(90, 255, 130, -1.0, HUD_Y_REVIVE, 0, 0.0, REVIVE_TIME, 0.1, 0.2);
			show_dhudmessage(target, "%s te está reviviendo...", name);
			continue;
		}
		if (now - g_ReviveStart[id] >= REVIVE_TIME)
		{
			g_Reviving[id] = 0;
			bar_time(id, 0);
			revive(target, id);
		}
	}
}

/** El cuerpo de un compañero muerto más cercano en rango (y que nadie más esté reviviendo). */
find_body(id)
{
	new Float:o[3];
	entity_get_vector(id, EV_VEC_origin, o);
	new team = get_user_team(id);
	new best = 0;
	new Float:bestDist = REVIVE_RANGE;
	for (new t = 1; t <= MAX_PLAYERS; t++)
	{
		if (!g_BodySprite[t] || t == id || !is_user_connected(t) || is_user_alive(t) || get_user_team(t) != team)
		{
			continue;
		}
		new bool:taken = false;
		for (new r = 1; r <= MAX_PLAYERS; r++)
		{
			if (r != id && g_Reviving[r] == t)
			{
				taken = true;
				break;
			}
		}
		if (taken)
		{
			continue;
		}
		new Float:dx = o[0] - g_Body[t][0], Float:dy = o[1] - g_Body[t][1], Float:dz = o[2] - g_Body[t][2];
		new Float:dist = floatsqroot(dx * dx + dy * dy);
		if (dist <= bestDist && floatabs(dz) <= 90.0)
		{
			best = t;
			bestDist = dist;
		}
	}
	return best;
}

cancel_revive(id)
{
	g_Reviving[id] = 0;
	if (is_user_connected(id))
	{
		bar_time(id, 0);
	}
}

bar_time(id, seconds)
{
	message_begin(MSG_ONE_UNRELIABLE, g_msgBarTime, _, id);
	write_short(seconds);
	message_end();
}

revive(target, reviver)
{
	if (!is_user_connected(target) || is_user_alive(target) || get_user_team(target) != get_user_team(reviver))
	{
		return;
	}
	new Float:body[3];
	body = g_Body[target];
	remove_body(target);
	g_ForceMenu[target] = true;
	g_ReviveGrace[target] = get_gametime() + float(get_pcvar_num(g_pLoadoutTime));
	ExecuteHamB(Ham_CS_RoundRespawn, target);
	if (!is_user_alive(target))
	{
		return;
	}
	place_player(target, body, reviver);

	new name[32], tname[32];
	get_user_name(reviver, name, charsmax(name));
	get_user_name(target, tname, charsmax(tname));
	client_print_color(0, reviver, "^4[Revivir]^3 %s^1 revivió a ^3%s^1.", name, tname);
	client_cmd(target, "spk items/smallmedkit1");
	client_cmd(reviver, "spk items/smallmedkit1");
}

/** Pone al revivido sobre su cuerpo (o al lado, si no entra). */
place_player(id, const Float:floor_pos[3], fallback)
{
	new const Float:OFFSETS[][2] = { { 0.0, 0.0 }, { 36.0, 0.0 }, { -36.0, 0.0 }, { 0.0, 36.0 }, { 0.0, -36.0 },
		{ 36.0, 36.0 }, { -36.0, -36.0 }, { 36.0, -36.0 }, { -36.0, 36.0 } };
	new Float:o[3];
	for (new i = 0; i < sizeof OFFSETS; i++)
	{
		o[0] = floor_pos[0] + OFFSETS[i][0];
		o[1] = floor_pos[1] + OFFSETS[i][1];
		o[2] = floor_pos[2] + 37.0;
		if (hull_free(o, id))
		{
			teleport(id, o);
			return;
		}
	}
	// No hay lugar: al lado del que lo revivió.
	entity_get_vector(fallback, EV_VEC_origin, o);
	o[2] += 2.0;
	for (new i = 1; i < sizeof OFFSETS; i++)
	{
		new Float:p[3];
		p[0] = o[0] + OFFSETS[i][0];
		p[1] = o[1] + OFFSETS[i][1];
		p[2] = o[2];
		if (hull_free(p, id))
		{
			teleport(id, p);
			return;
		}
	}
}

bool:hull_free(const Float:o[3], id)
{
	engfunc(EngFunc_TraceHull, o, o, 0, HULL_HUMAN, id, 0);
	return !get_tr2(0, TR_StartSolid) && !get_tr2(0, TR_AllSolid) && get_tr2(0, TR_InOpen);
}

teleport(id, const Float:o[3])
{
	entity_set_origin(id, o);
	entity_set_vector(id, EV_VEC_velocity, Float:{ 0.0, 0.0, 0.0 });
}

/* ========================================================================= */
/* Granadas: aviso en el chat, estelas y flashes de los compañeros           */
/* ========================================================================= */

/** "Fire in the hole!" por radio -> [HE]/[SG]/[FB] Nick: mensaje, con el color de cada granada. */
public msg_text(msgid, dest, receiver)
{
	new args = get_msg_args();
	if (args < 5)
	{
		return PLUGIN_CONTINUE;
	}
	new last[32];
	get_msg_arg_string(args, last, charsmax(last));
	if (!equal(last, "#Fire_in_the_hole"))
	{
		return PLUGIN_CONTINUE;
	}
	new num[8];
	get_msg_arg_string(2, num, charsmax(num));
	new sender = str_to_num(num);
	if (!(1 <= sender <= MAX_PLAYERS) || !is_user_connected(sender) || !is_user_connected(receiver))
	{
		return PLUGIN_CONTINUE;
	}
	new name[32];
	get_user_name(sender, name, charsmax(name));
	switch (get_user_weapon(sender))
	{
		case CSW_SMOKEGRENADE: client_print_color(receiver, sender, "^4[SG]^1 %s: ¡Tiro humo!", name);
		case CSW_FLASHBANG: client_print_color(receiver, print_team_grey, "^3[FB]^1 %s: ¡Tiro flash, date vuelta!", name);
		default: client_print_color(receiver, print_team_red, "^3[HE]^1 %s: ¡Cuidado, granada!", name);
	}
	return PLUGIN_HANDLED;
}

public fw_set_model(ent, const model[])
{
	if (!is_valid_ent(ent))
	{
		return FMRES_IGNORED;
	}
	for (new i = 0; i < sizeof NADE_MODELS; i++)
	{
		if (equal(model, NADE_MODELS[i]))
		{
			new classname[16];
			entity_get_string(ent, EV_SZ_classname, classname, charsmax(classname));
			if (equal(classname, "grenade"))
			{
				// La entidad todavía no llegó a los clientes: la estela se pide un instante después.
				new params[2];
				params[0] = ent;
				params[1] = i;
				set_task(0.05, "task_trail", TASK_TRAIL + ent, params, sizeof params);
			}
			break;
		}
	}
	return FMRES_IGNORED;
}

public task_trail(const params[])
{
	new ent = params[0], type = params[1];
	if (!is_valid_ent(ent))
	{
		return;
	}
	message_begin(MSG_BROADCAST, SVC_TEMPENTITY);
	write_byte(TE_BEAMFOLLOW);
	write_short(ent);
	write_short(g_TrailSprite);
	write_byte(12);   // vida de la estela (décimas de segundo)
	write_byte(6);    // ancho
	write_byte(NADE_COLORS[type][0]);
	write_byte(NADE_COLORS[type][1]);
	write_byte(NADE_COLORS[type][2]);
	write_byte(200);  // brillo
	message_end();
}

/** Al explotar una flash se anota de quién es, para que el ScreenFade no ciegue a sus compañeros. */
public fw_grenade_think(ent)
{
	new model[32];
	entity_get_string(ent, EV_SZ_model, model, charsmax(model));
	if (equal(model, NADE_MODELS[NADE_FB]) && entity_get_float(ent, EV_FL_dmgtime) <= get_gametime())
	{
		g_FlashOwner = entity_get_edict(ent, EV_ENT_owner);
		g_FlashTime = get_gametime();
	}
	return HAM_IGNORED;
}

public msg_screen_fade(msgid, dest, receiver)
{
	// Solo el destello blanco de la flash, en el mismo instante en que explotó.
	if (get_msg_arg_int(4) != 255 || get_msg_arg_int(5) != 255 || get_msg_arg_int(6) != 255)
	{
		return PLUGIN_CONTINUE;
	}
	if (floatabs(get_gametime() - g_FlashTime) > 0.05 || !(1 <= g_FlashOwner <= MAX_PLAYERS) || g_FlashOwner == receiver
		|| !is_user_connected(g_FlashOwner) || !(1 <= receiver <= MAX_PLAYERS))
	{
		return PLUGIN_CONTINUE;
	}
	return get_user_team(g_FlashOwner) == get_user_team(receiver) ? PLUGIN_HANDLED : PLUGIN_CONTINUE;
}

/* ========================================================================= */
/* Reproducción de sonidos                                                   */
/* ========================================================================= */

/** kill = sonido de muertes (cada jugador lo puede apagar con /sonidos). */
play_to(id, snd, Float:delay, bool:kill)
{
	if (!get_pcvar_num(g_pSounds) || g_SoundFmt[snd] == FMT_NONE)
	{
		return;
	}
	if (delay <= 0.0)
	{
		do_play(id, snd, kill);
		return;
	}
	new params[3];
	params[0] = id;
	params[1] = snd;
	params[2] = kill;
	set_task(delay, "task_play", TASK_PLAY, params, sizeof params);
}

public task_play(const params[])
{
	do_play(params[0], params[1], bool:params[2]);
}

do_play(id, snd, bool:kill)
{
	if (id != 0)
	{
		play_one(id, snd, kill);
		return;
	}
	new players[MAX_PLAYERS], num;
	get_players(players, num, "ch");
	for (new i = 0; i < num; i++)
	{
		play_one(players[i], snd, kill);
	}
}

play_one(id, snd, bool:kill)
{
	if (!is_user_connected(id) || (kill && !g_KillSounds[id]))
	{
		return;
	}
	if (g_SoundFmt[snd] == FMT_WAV)
	{
		client_cmd(id, "spk ^"%s^"", SOUND_FILES[snd]);
	}
	else
	{
		client_cmd(id, "mp3 play ^"sound/%s.mp3^"", SOUND_FILES[snd]);
	}
}

/* ========================================================================= */
/* Votación de mapa                                                          */
/* ========================================================================= */

load_maps()
{
	g_Maps = ArrayCreate(32);
	new path[128];
	get_configsdir(path, charsmax(path));
	add(path, charsmax(path), "/claudia/mapas.ini");
	if (!file_exists(path))
	{
		copy(path, charsmax(path), "mapcycle.txt");
	}
	new f = fopen(path, "rt");
	if (!f)
	{
		return;
	}
	new line[64], map[32], current[32];
	get_mapname(current, charsmax(current));
	while (!feof(f))
	{
		fgets(f, line, charsmax(line));
		trim(line);
		if (!line[0] || line[0] == ';' || (line[0] == '/' && line[1] == '/'))
		{
			continue;
		}
		argparse(line, 0, map, charsmax(map));
		if (map[0] && !equali(map, current) && is_map_valid(map))
		{
			ArrayPushString(g_Maps, map);
		}
	}
	fclose(f);
}

/** Guarda el mapa actual entre los recientes (localinfo sobrevive a los cambios de mapa). */
remember_map()
{
	new recent[192], current[32], out[192];
	get_localinfo(RECENT_KEY, recent, charsmax(recent));
	get_mapname(current, charsmax(current));
	copy(out, charsmax(out), current);
	new keep = get_pcvar_num(g_pRecentMaps) - 1;
	new token[32], pos = 0;
	while (keep > 0 && (pos = argparse(recent, pos, token, charsmax(token))) != -1)
	{
		if (token[0] && !equali(token, current))
		{
			format(out, charsmax(out), "%s %s", out, token);
			keep--;
		}
	}
	set_localinfo(RECENT_KEY, out);
}

bool:is_recent(const map[])
{
	new recent[192], token[32], pos = 0;
	get_localinfo(RECENT_KEY, recent, charsmax(recent));
	while ((pos = argparse(recent, pos, token, charsmax(token))) != -1)
	{
		if (equali(token, map))
		{
			return true;
		}
	}
	return false;
}

start_vote(bool:rtv)
{
	new total = ArraySize(g_Maps);
	if (g_Voting || total == 0)
	{
		return;
	}

	// Candidatos: primero los que no se jugaron hace poco.
	new Array:pool = ArrayCreate(32);
	new map[32];
	for (new pass = 0; pass < 2 && ArraySize(pool) < get_pcvar_num(g_pVoteMaps); pass++)
	{
		for (new i = 0; i < total; i++)
		{
			ArrayGetString(g_Maps, i, map, charsmax(map));
			if ((pass == 0) != is_recent(map))
			{
				ArrayPushString(pool, map);
			}
		}
	}
	g_VoteCount = 0;
	new want = clamp(get_pcvar_num(g_pVoteMaps), 2, MAX_VOTE_ITEMS - 1);
	while (g_VoteCount < want && ArraySize(pool) > 0)
	{
		new pick = random(ArraySize(pool));
		ArrayGetString(pool, pick, g_VoteMaps[g_VoteCount], charsmax(g_VoteMaps[]));
		ArrayDeleteItem(pool, pick);
		g_VoteCount++;
	}
	ArrayDestroy(pool);

	g_HasExtend = !rtv && g_Extends < get_pcvar_num(g_pExtendMax);
	g_VoteIsRtv = rtv;
	g_Voting = true;
	arrayset(g_Votes, 0, sizeof g_Votes);
	arrayset(g_Voted, false, sizeof g_Voted);

	g_VoteMenu = menu_create("\yVotá el próximo mapa", "vote_handler");
	for (new i = 0; i < g_VoteCount; i++)
	{
		menu_additem(g_VoteMenu, g_VoteMaps[i]);
	}
	if (g_HasExtend)
	{
		new label[64], current[32];
		get_mapname(current, charsmax(current));
		formatex(label, charsmax(label), "\yExtender %s \d(%d min)", current, get_pcvar_num(g_pExtendMinutes));
		menu_additem(g_VoteMenu, label);
	}
	menu_setprop(g_VoteMenu, MPROP_PERPAGE, 0);
	menu_setprop(g_VoteMenu, MPROP_EXIT, MEXIT_NEVER);

	new votetime = clamp(get_pcvar_num(g_pVoteTime), 5, 60);
	new players[MAX_PLAYERS], num;
	get_players(players, num, "ch");
	for (new i = 0; i < num; i++)
	{
		menu_display(players[i], g_VoteMenu, 0, votetime);
	}
	client_print_color(0, print_team_default, "^4[Mapa]^1 %s Votá el próximo mapa (%d segundos).", rtv ? "¡Rock the vote!" : "Se termina el mapa.", votetime);
	client_cmd(0, "spk ^"Gman/Gman_Choose2^"");
	set_task(float(votetime), "end_vote", TASK_VOTE_END);
}

public vote_handler(id, menu, item)
{
	if (!g_Voting || menu != g_VoteMenu || item < 0 || g_Voted[id] || !is_user_connected(id))
	{
		return PLUGIN_HANDLED;
	}
	new limit = g_VoteCount + (g_HasExtend ? 1 : 0);
	if (item >= limit)
	{
		return PLUGIN_HANDLED;
	}
	g_Voted[id] = true;
	g_Votes[item]++;
	new name[32];
	get_user_name(id, name, charsmax(name));
	client_print_color(0, id, "^4[Mapa]^3 %s^1 votó ^4%s", name, item < g_VoteCount ? g_VoteMaps[item] : "extender");
	return PLUGIN_HANDLED;
}

public end_vote()
{
	if (!g_Voting)
	{
		return;
	}
	g_Voting = false;

	// Cierra el menú a los que no votaron.
	new players[MAX_PLAYERS], num, oldm, newm;
	get_players(players, num, "ch");
	for (new i = 0; i < num; i++)
	{
		player_menu_info(players[i], oldm, newm);
		if (newm == g_VoteMenu)
		{
			menu_cancel(players[i]);
			show_menu(players[i], 0, "^n", 1);
		}
	}
	menu_destroy(g_VoteMenu);
	g_VoteMenu = -1;

	new items = g_VoteCount + (g_HasExtend ? 1 : 0);
	new best = 0, total = 0;
	for (new i = 0; i < items; i++)
	{
		total += g_Votes[i];
		if (g_Votes[i] > best)
		{
			best = g_Votes[i];
		}
	}
	new winner;
	if (total == 0)
	{
		winner = random(g_VoteCount);
	}
	else
	{
		new tied[MAX_VOTE_ITEMS + 1], n;
		for (new i = 0; i < items; i++)
		{
			if (g_Votes[i] == best)
			{
				tied[n++] = i;
			}
		}
		winner = tied[random(n)];
	}

	if (winner >= g_VoteCount)
	{
		new minutes = get_pcvar_num(g_pExtendMinutes);
		set_cvar_float("mp_timelimit", get_cvar_float("mp_timelimit") + float(minutes));
		g_Extends++;
		client_print_color(0, print_team_default, "^4[Mapa]^1 Ganó extender: el mapa sigue ^4%d^1 minutos más (%d votos).", minutes, best);
		return;
	}

	copy(g_NextMap, charsmax(g_NextMap), g_VoteMaps[winner]);
	set_cvar_string("amx_nextmap", g_NextMap);
	g_VoteDone = true;
	arrayset(g_Rtv, false, sizeof g_Rtv);
	if (g_VoteIsRtv)
	{
		client_print_color(0, print_team_default, "^4[Mapa]^1 Ganó ^4%s^1 (%d votos). Cambiando en 5 segundos...", g_NextMap, best);
		set_task(5.0, "task_change", TASK_CHANGE);
	}
	else
	{
		client_print_color(0, print_team_default, "^4[Mapa]^1 Próximo mapa: ^4%s^1 (%d votos).", g_NextMap, best);
	}
}

public task_change()
{
	server_cmd("changelevel %s", g_NextMap);
}

rock_the_vote(id)
{
	if (g_Voting)
	{
		client_print_color(id, print_team_default, "^4[Mapa]^1 Ya hay una votación en curso.");
		return;
	}
	if (task_exists(TASK_CHANGE))
	{
		return;
	}
	new Float:delay = get_pcvar_float(g_pRtvDelay);
	if (get_gametime() < delay)
	{
		client_print_color(id, print_team_default, "^4[Mapa]^1 Todavía no se puede: esperá %d segundos.", floatround(delay - get_gametime(), floatround_ceil));
		return;
	}
	if (g_Rtv[id])
	{
		client_print_color(id, print_team_default, "^4[Mapa]^1 Ya votaste para cambiar de mapa.");
		return;
	}
	g_Rtv[id] = true;

	new players[MAX_PLAYERS], num, count;
	get_players(players, num, "ch");
	for (new i = 0; i < num; i++)
	{
		if (g_Rtv[players[i]])
		{
			count++;
		}
	}
	new needed = max(1, floatround(float(num) * get_pcvar_float(g_pRtvRatio), floatround_ceil));
	new name[32];
	get_user_name(id, name, charsmax(name));
	if (count < needed)
	{
		client_print_color(0, id, "^4[Mapa]^3 %s^1 quiere cambiar de mapa (%d/%d). Escribí ^4rtv^1 para sumarte.", name, count, needed);
		return;
	}
	if (g_VoteDone)
	{
		client_print_color(0, print_team_default, "^4[Mapa]^1 ¡Rock the vote! Cambiando a ^4%s^1 en 5 segundos...", g_NextMap);
		set_task(5.0, "task_change", TASK_CHANGE);
		return;
	}
	start_vote(true);
}
