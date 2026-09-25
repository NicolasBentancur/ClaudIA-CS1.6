#!/usr/bin/env sh
# Inicia el servicio de Claudia en Linux.
#   ./start.sh          primer plano
#   ./start.sh -d       como demonio (luego: php bin/claudia.php stop|restart|status)
cd "$(dirname "$0")" || exit 1
if [ ! -f vendor/autoload.php ]; then
    echo "Faltan las dependencias. Ejecutá primero: composer install --no-dev"
    exit 1
fi
exec php bin/claudia.php start "$@"
