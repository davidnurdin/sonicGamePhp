#!/usr/bin/env python3
"""Render the Labyrinth underwater textures from the ROM palettes.

Below the water line the original reloads the whole CRAM with S1_UnderwaterPalette ($024B: 16 tile
colours + 16 sprite colours, no palette cycling) on a line interrupt. Every colour of the Labyrinth
tile palette ($638E) / sprite palette ($639E) is mapped to the colour with the same index in that
palette, which gives "{name}-water.png" textures drawn below the water line.
Robotnik and the capsule only appear underwater in Labyrinth act 3, where the line interrupt loads
S1_UnderwaterPalette_Boss ($026B) instead: the boss sprite palette ($731C) is mapped onto its sprite half.
"""
from PIL import Image

ROM = open('/home/david/poc/sonic/sonic_level/sonic.sms', 'rb').read()
ASSETS = '/home/david/poc/sonicGamePhp/assets/tileset'


def pal(addr, n=16):
    return [((c & 3) * 85, ((c >> 2) & 3) * 85, ((c >> 4) & 3) * 85) for c in ROM[addr:addr + n]]


TILES, SPRITES, UNDERWATER = pal(0x638E), pal(0x639E), pal(0x024B, 32)
BOSS, UNDERWATER_BOSS = pal(0x731C), pal(0x026B, 32)
TILE_MAP = {TILES[i]: UNDERWATER[i] for i in range(16)}
SPRITE_MAP = {SPRITES[i]: UNDERWATER[16 + i] for i in range(16)}
BOSS_MAP = {BOSS[i]: UNDERWATER_BOSS[16 + i] for i in range(16)}


def nearest(c, mapping):
    key = min(mapping, key=lambda k: sum((a - b) ** 2 for a, b in zip(k, c)))
    return mapping[key]


def convert(src, dst, mapping, keep=()):
    im = Image.open(src).convert('RGBA')
    cache = {}
    px = im.load()
    for y in range(im.height):
        for x in range(im.width):
            r, g, b, a = px[x, y]
            if a == 0 or (r, g, b) in keep:
                continue
            c = (r, g, b)
            if c not in cache:
                cache[c] = mapping.get(c) or nearest(c, mapping)
            px[x, y] = cache[c] + (a,)
    im.save(dst)
    print(dst)


for n in (10, 11, 12):
    for suffix in ('', '-fg'):
        convert(f'{ASSETS}/levels/tileset{n}{suffix}.png', f'{ASSETS}/levels/tileset{n}-water{suffix}.png', TILE_MAP)
for name, key, mapping in (('sonic', (0, 72, 0), SPRITE_MAP), ('objects', (170, 0, 170), SPRITE_MAP),
                           ('enemies', (170, 0, 170), SPRITE_MAP), ('eggman', (0, 170, 255), BOSS_MAP)):
    convert(f'{ASSETS}/sprites/tileset-{name}.png', f'{ASSETS}/sprites/tileset-{name}-water.png', mapping, keep=(key,))
