#!/usr/bin/env python3
"""Rival Sonics of the multiplayer mode: tileset-sonic-<colour>.png, Sonic in another colour, and
tileset-rom-sonic-<colour>.png, his frames drawn from the ROM art (waiting, spring, slide: RomSprites) in that
colour, at the same places as in tileset-rom.png (colour key (170, 0, 170), the rest of the sheet left out).

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

ROM = SRC.replace('tileset-sonic.png', 'tileset-rom.png')
# RomSprites::SONIC_WAIT, SONIC_SPRING, SONIC_SLIDE: x of the 24x32 frames, at y 0
ROM_FRAMES = [960, 986, 1012, 1146, 1190, 1216]


def recolour(im, dark, light):
    out = im.copy()
    px = out.load()
    for y in range(out.height):
        for x in range(out.width):
            if px[x, y] == DARK:
                px[x, y] = dark
            elif px[x, y] == LIGHT:
                px[x, y] = light
    return out


im = Image.open(SRC).convert('RGB')
rom = Image.open(ROM).convert('RGB')
for name, (dark, light) in COLOURS.items():
    path = SRC.replace('.png', '-' + name + '.png')
    recolour(im, dark, light).save(path)
    print(path)
    frames = Image.new('RGB', (rom.width, 32), (170, 0, 170))
    for x in ROM_FRAMES:
        frames.paste(recolour(rom.crop((x, 0, x + 24, 32)), dark, light), (x, 0))
    path = ROM.replace('.png', '-sonic-' + name + '.png')
    frames.save(path, optimize=True)
    print(path)
