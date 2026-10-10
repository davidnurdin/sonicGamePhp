#!/usr/bin/env python3
"""Debug invincibility (F5): tileset-sonic-god.png, Sonic with red eyes (the black pupils next to the white
of the eyes become red)."""
from PIL import Image

SRC = '/home/david/poc/sonicGamePhp/assets/tileset/sprites/tileset-sonic.png'
im = Image.open(SRC).convert('RGB')
px = im.load()
out = im.copy()
o = out.load()
W, H = im.size
for y in range(H):
    for x in range(W):
        if px[x, y] != (0, 0, 0):
            continue
        near = [px[x + dx, y + dy] for dx, dy in ((1, 0), (-1, 0), (0, 1), (0, -1)) if 0 <= x + dx < W and 0 <= y + dy < H]
        if (255, 255, 255) in near:
            o[x, y] = (255, 0, 0)
out.save(SRC.replace('.png', '-god.png'))
