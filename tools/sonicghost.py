#!/usr/bin/env python3
"""Ghost of the best run (title menu > options): tileset-sonic-ghost.png, Sonic in white metal.

Each pixel of tileset-sonic.png becomes a silver shade from its brightness (dark outlines stay a blue grey,
light parts a bluish white), drawn slightly transparent (alpha 235); the colour key (0, 72, 0) becomes
transparent. The texture is loaded without colour key: SDL blends it with its alpha channel.
"""
from PIL import Image

SRC = '/home/david/poc/sonicGamePhp/assets/tileset/sprites/tileset-sonic.png'
KEY = (0, 72, 0)
ALPHA = 235

im = Image.open(SRC).convert('RGB')
out = Image.new('RGBA', im.size, (0, 0, 0, 0))
src, dst = im.load(), out.load()
for y in range(im.height):
    for x in range(im.width):
        r, g, b = src[x, y]
        if (r, g, b) == KEY:
            continue
        lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255
        v = 0.45 + 0.55 * lum ** 0.5        # lift the dark blues into a light metal grey
        dst[x, y] = (int(235 * v + 20), int(238 * v + 17), int(245 * v + 10), ALPHA)
out.save(SRC.replace('.png', '-ghost.png'))
print(SRC.replace('.png', '-ghost.png'))
