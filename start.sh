#!/bin/bash
# Lance Sonic PHP en natif (desktop) : ./start.sh
#   SONIC_PHP=php8.3 ./start.sh                       : un autre PHP (avec les extensions SDL installées)
#   SONIC_NET_URL=ws://localhost:8090/ws ./start.sh   : un autre serveur pour le multijoueur en ligne
#                                                       (défaut : wss://sonic.mimichat.fr/ws, Online::DEFAULT_URL)
set -e
cd "$(dirname "$0")"
PHP="${SONIC_PHP:-php8.2}"
CMD=(env ${SONIC_NET_URL:+SONIC_NET_URL="$SONIC_NET_URL"} "$PHP"
    -d extension=sdl.so -d extension=sdl_image.so -d extension=sdl_ttf.so -d extension=sdl_mixer.so app.php "$@")

# var/ (options, meilleurs temps, ghosts) appartient à root quand le jeu a été lancé avec sudo :
# sudo seulement dans ce cas (sudo chown -R "$USER" var pour s'en passer ensuite)
if [ -e var ] && [ ! -w var ]; then
    echo "var/ n'est pas modifiable par $USER : lancement avec sudo (sudo chown -R $USER var pour s'en passer)"
    exec sudo "${CMD[@]}"
fi
exec "${CMD[@]}"
