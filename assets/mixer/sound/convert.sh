#!/bin/bash

# Vérifie qu’un fichier a été passé en argument
if [ -z "$1" ]; then
  echo "Usage: $0 fichier.mp3"
  exit 1
fi

# Chemin absolu du fichier source
INPUT="$1"

# Vérifie que le fichier existe
if [ ! -f "$INPUT" ]; then
  echo "Fichier introuvable : $INPUT"
  exit 1
fi

# Supprime l’extension pour créer le nom de sortie
OUTPUT="${INPUT%.*}.ogg"

# Conversion avec ffmpeg
ffmpeg -i "$INPUT" -c:a libvorbis -qscale:a 5 "$OUTPUT"
