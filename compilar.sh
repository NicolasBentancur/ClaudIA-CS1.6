#!/usr/bin/env sh
# Compila los plugins de Claudia y arma build/cstrike con todo lo que va en el servidor de CS.
# Usa $AMXXPC (ruta a amxxpc) o, si no, addons/amxmodx/scripting/amxxpc de $AMXX_DIR.
set -e
cd "$(dirname "$0")"

if [ -z "$AMXXPC" ]; then
    AMXXPC="${AMXX_DIR:-.tools/amxx/addons/amxmodx}/scripting/amxxpc"
fi
if [ ! -x "$AMXXPC" ]; then
    echo "No encuentro amxxpc ($AMXXPC). Definí AMXXPC o AMXX_DIR (AMX Mod X 1.10 base para Linux)."
    exit 1
fi
AMXXINC="$(dirname "$AMXXPC")/include"
# amxxpc carga amxxpc32.so por nombre: sin esto, fuera de su carpeta no encuentra la biblioteca.
LD_LIBRARY_PATH="$(dirname "$AMXXPC")${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
export LD_LIBRARY_PATH

OUT=build/cstrike
rm -rf "$OUT"
mkdir -p "$OUT/addons/amxmodx/plugins" "$OUT/addons/amxmodx/configs/claudia" "$OUT/addons/amxmodx/data/lang" \
         "$OUT/addons/amxmodx/scripting/include" "$OUT/sound/claudia/anuncios" "$OUT/sprites/claudia"

# Un plugin que no compila (por ejemplo claudia_radio sin los .inc de ReAPI) no corta el script:
# igual se copian configs, sonidos y sprites, y al final sale con error. amxxpc sale con 0 aunque
# no compile (y deja un .amxx roto), así que el error se detecta en lo que imprime.
FAIL=0
for p in claudia_publico claudia_core claudia_auth claudia_stats claudia_admin claudia_ajedrez claudia_radio; do
    echo "== $p"
    amxx="$OUT/addons/amxmodx/plugins/$p.amxx"
    if ! log=$("$AMXXPC" "amxmodx/scripting/$p.sma" -i"$AMXXINC" -i"amxmodx/scripting/include" -o"$amxx" 2>&1) \
        || printf '%s\n' "$log" | grep -Eq '^[0-9]+ Errors?\.$'; then
        FAIL=1
        rm -f "$amxx"
    fi
    printf '%s\n' "$log"
done

cp amxmodx/configs/plugins-claudia.ini "$OUT/addons/amxmodx/configs/"
cp amxmodx/configs/claudia/* "$OUT/addons/amxmodx/configs/claudia/"
cp amxmodx/data/lang/claudia.txt "$OUT/addons/amxmodx/data/lang/"
cp amxmodx/scripting/*.sma "$OUT/addons/amxmodx/scripting/"
cp amxmodx/scripting/include/claudia.inc "$OUT/addons/amxmodx/scripting/include/"
cp sound/claudia/*.wav "$OUT/sound/claudia/"
cp sound/claudia/anuncios/*.wav "$OUT/sound/claudia/anuncios/"
cp sprites/claudia/*.spr "$OUT/sprites/claudia/"

if [ "$FAIL" -ne 0 ]; then
    echo "Hubo errores de compilación (claudia_radio necesita los .inc de ReAPI en la carpeta include del compilador)."
    exit 1
fi
echo "Listo: copiá el contenido de $OUT dentro de la carpeta cstrike del servidor."
