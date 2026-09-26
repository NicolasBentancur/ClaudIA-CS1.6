/*
 * Claudia - Radio por el chat de voz
 *
 * El servicio baja el tema (yt-dlp), lo convierte a wav mono 16 kHz en trozos de pocos segundos
 * dentro de cstrike/<dir>/000.wav... y manda:
 *   radio.play {id, dir, chunks:[ms...], title}   empezar a pasar el tema
 *   radio.stop {id}                                cortarlo (saltado)
 * Cada trozo se reproduce con VoiceTranscoder (VTC_PlaySound, vía ReAPI) a cada jugador que
 * escucha la radio. Al terminar el último avisa "radio.done" para que el servicio pase al siguiente.
 *
 * /radio on y /radio off prenden o apagan la radio para cada uno (se recuerda por nick). Por
 * defecto la escuchan todos (cvar cr_default_on).
 *
 * Requiere: ReAPI y VoiceTranscoder cargados (metamod).
 */

#include <amxmodx>
#include <json>
#include <nvault>
#include <reapi>
#include <claudia>

#pragma semicolon 1

#define PLUGIN  "Claudia Radio"
#define VERSION "1.0.0"
#define AUTHOR  "Claudia"

#define TASK_CHUNK 76000
#define TASK_DONE  76001
#define MAX_CHUNKS 160

new g_Track;                 // id del tema que suena (0 = nada)
new g_Dir[64];
new g_Chunks[MAX_CHUNKS];    // duración de cada trozo (ms)
new g_ChunkCount;
new g_Index;
new g_Title[96];

new bool:g_Off[MAX_PLAYERS + 1];
new bool:g_Vtc;
new g_Vault = INVALID_HANDLE;
new g_pDefaultOn, g_pPace;

public plugin_init()
{
	register_plugin(PLUGIN, VERSION, AUTHOR);
	g_pDefaultOn = register_cvar("cr_default_on", "1");
	// VoiceTranscoder manda el audio un poco más rápido que el tiempo real (~0,7 %): el trozo
	// siguiente se adelanta en esa proporción para que no queden huecos.
	g_pPace = register_cvar("cr_pace", "0.993");
	g_Vault = nvault_open("claudia_radio");
}

public plugin_cfg()
{
	g_Vtc = has_vtc();
	if (!g_Vtc)
	{
		log_amx("[Radio] VoiceTranscoder no está cargado: la radio no va a sonar.");
	}
}

public plugin_end()
{
	if (g_Vault != INVALID_HANDLE)
	{
		nvault_close(g_Vault);
	}
}

public client_putinserver(id)
{
	g_Off[id] = get_pcvar_num(g_pDefaultOn) == 0;
	new key[48], value[4];
	vault_key(id, key, charsmax(key));
	if (g_Vault != INVALID_HANDLE && nvault_get(g_Vault, key, value, charsmax(value)))
	{
		g_Off[id] = value[0] == '0';
	}
}

vault_key(id, key[], len)
{
	new name[32];
	get_user_name(id, name, charsmax(name));
	formatex(key, len, "radio_%s", name);
}

/* /radio on | /radio off: se resuelven acá sin pasar por el servicio. */
public claudia_say_command(id, const cmd[], const args[])
{
	if (!equal(cmd, "radio") && !equal(cmd, "musica"))
	{
		return PLUGIN_CONTINUE;
	}
	// El argumento entero (el núcleo ya lo recorta): "/radio apagar la luz" es un pedido, no apagar la radio.
	new bool:on = equali(args, "on") || equali(args, "prender");
	new bool:off = equali(args, "off") || equali(args, "apagar");
	if (!on && !off)
	{
		return PLUGIN_CONTINUE;
	}
	g_Off[id] = off;
	new key[48];
	vault_key(id, key, charsmax(key));
	if (g_Vault != INVALID_HANDLE)
	{
		nvault_set(g_Vault, key, off ? "0" : "1");
	}
	if (off)
	{
		client_print_color(id, print_team_default, "^4[Radio]^1 Radio ^3apagada^1 para vos (lo que ya estaba sonando termina en unos segundos). ^4/radio on^1 para volver a escucharla.");
	}
	else if (g_Track)
	{
		client_print_color(id, print_team_default, "^4[Radio]^1 Radio ^4prendida^1. Sonando: ^4%s^1.", g_Title);
	}
	else
	{
		client_print_color(id, print_team_default, "^4[Radio]^1 Radio ^4prendida^1. Pedí un tema con ^4/radio <nombre o link>^1.");
	}
	return PLUGIN_HANDLED;
}

public claudia_event(const type[], JSON:data)
{
	if (equal(type, "radio.play"))
	{
		start_track(data);
	}
	else if (equal(type, "radio.stop"))
	{
		if (json_object_get_number(data, "id") == g_Track)
		{
			stop_track();
		}
	}
}

public claudia_connection_changed(bool:connected)
{
	if (!connected)
	{
		stop_track();
	}
}

start_track(JSON:data)
{
	stop_track();
	if (!g_Vtc)
	{
		return;
	}
	new JSON:chunks = json_object_get_value(data, "chunks");
	if (chunks == Invalid_JSON)
	{
		return;
	}
	g_ChunkCount = min(json_array_get_count(chunks), MAX_CHUNKS);
	for (new i = 0; i < g_ChunkCount; i++)
	{
		g_Chunks[i] = json_array_get_number(chunks, i);
	}
	json_free(chunks);
	json_object_get_string(data, "dir", g_Dir, charsmax(g_Dir));
	json_object_get_string(data, "title", g_Title, charsmax(g_Title));
	// La ruta la arma el servicio, pero igual: nada de subir de carpeta.
	if (contain(g_Dir, "..") != -1 || g_ChunkCount == 0)
	{
		g_ChunkCount = 0;
		return;
	}
	g_Track = json_object_get_number(data, "id");
	g_Index = 0;
	task_chunk();
}

stop_track()
{
	remove_task(TASK_CHUNK);
	remove_task(TASK_DONE);
	g_Track = 0;
	g_ChunkCount = 0;
	g_Index = 0;
}

/** Pasa el trozo actual a cada jugador que escucha la radio y programa el siguiente. */
public task_chunk()
{
	if (!g_Track || g_Index >= g_ChunkCount)
	{
		return;
	}
	new path[96];
	formatex(path, charsmax(path), "%s/%03d.wav", g_Dir, g_Index);
	new players[MAX_PLAYERS], num;
	get_players(players, num, "ch");
	for (new i = 0; i < num; i++)
	{
		if (!g_Off[players[i]])
		{
			VTC_PlaySound(players[i], path);
		}
	}
	new Float:len = float(g_Chunks[g_Index]) / 1000.0;
	g_Index++;
	if (g_Index < g_ChunkCount)
	{
		set_task(floatmax(0.5, len * get_pcvar_float(g_pPace)), "task_chunk", TASK_CHUNK);
	}
	else
	{
		set_task(len + 0.5, "task_done", TASK_DONE);
	}
}

public task_done()
{
	new id = g_Track;
	stop_track();
	if (id)
	{
		new JSON:data = json_init_object();
		json_object_set_number(data, "id", id);
		claudia_send("radio.done", data);
	}
}
