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

OUT=build/cstrike
rm -rf "$OUT"
mkdir -p "$OUT/addons/amxmodx/plugins" "$OUT/addons/amxmodx/configs/claudia" "$OUT/addons/amxmodx/data/lang" \
         "$OUT/addons/amxmodx/scripting/include" "$OUT/sound/claudia/anuncios" "$OUT/sprites/claudia"

for p in claudia_publico claudia_core claudia_auth claudia_stats claudia_admin claudia_ajedrez claudia_radio; do
    echo "== $p"
    "$AMXXPC" "amxmodx/scripting/$p.sma" -i"$AMXXINC" -i"amxmodx/scripting/include" -o"$OUT/addons/amxmodx/plugins/$p.amxx"
done

cp amxmodx/configs/plugins-claudia.ini "$OUT/addons/amxmodx/configs/"
cp amxmodx/configs/claudia/* "$OUT/addons/amxmodx/configs/claudia/"
cp amxmodx/data/lang/claudia.txt "$OUT/addons/amxmodx/data/lang/"
cp amxmodx/scripting/*.sma "$OUT/addons/amxmodx/scripting/"
cp amxmodx/scripting/include/claudia.inc "$OUT/addons/amxmodx/scripting/include/"
cp sound/claudia/*.wav "$OUT/sound/claudia/"
cp sound/claudia/anuncios/*.wav "$OUT/sound/claudia/anuncios/"
cp sprites/claudia/*.spr "$OUT/sprites/claudia/"

echo "Listo: copiá el contenido de $OUT dentro de la carpeta cstrike del servidor."
