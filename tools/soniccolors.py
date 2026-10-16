#!/usr/bin/env python3
"""Rival Sonics of the multiplayer mode: tileset-sonic-<colour>.png, Sonic in another colour.

Only Sonic's two blues of tileset-sonic.png are replaced (dark (0, 0, 170) and light (85, 85, 255)), with
colours of the Master System palette (multiples of 85); skin, shoes, eyes, outlines and the colour key
(0, 72, 0) are kept. Loaded like tileset-sonic.png (same colour key) by Versus::loadTextures().
"""
from PIL import Image

SRC = '/home/david/poc/sonicGamePhp/assets/tileset/sprites/tileset-sonic.png'
DARK, LIGHT = (0, 0, 170), (85, 85, 255)
COLOURS = {
    'red': ((170, 0, 0), (255, 85, 85)),
    'green': ((0, 170, 0), (85, 255, 85)),
    'yellow': ((170, 170, 0), (255, 255, 85)),
    'purple': ((85, 0, 170), (170, 85, 255)),
    'orange': ((170, 85, 0), (255, 170, 0)),
}

im = Image.open(SRC).convert('RGB')
for name, (dark, light) in COLOURS.items():
    out = im.copy()
    px = out.load()
    for y in range(out.height):
        for x in range(out.width):
            if px[x, y] == DARK:
                px[x, y] = dark
            elif px[x, y] == LIGHT:
                px[x, y] = light
    path = SRC.replace('.png', '-' + name + '.png')
    out.save(path)
    print(path)
