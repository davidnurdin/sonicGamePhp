#!/usr/bin/env python3
"""Debug invincibility (F5): Sonic with red eyes (the black pupils next to the white of the eyes become red).

- tileset-sonic-god.png from tileset-sonic.png;
- tileset-sonic-god-water.png: the same pixels on tileset-sonic-water.png (Labyrinth underwater palette), kept
  pure red so that the debug mode is still seen underwater;
- tileset-rom-god.png: Sonic's frames drawn from the ROM sheet (waiting $15-$17, spring, water slide), the rest
  of tileset-rom.png unchanged.
"""
from PIL import Image

SPRITES = '/home/david/poc/sonicGamePhp/assets/tileset/sprites'
RED = (255, 0, 0)
# RomSprites::SONIC_WAIT, SONIC_SPRING, SONIC_SLIDE
ROM_SONIC = [(960, 0, 24, 32), (986, 0, 24, 32), (1012, 0, 24, 32), (1146, 0, 24, 32), (1190, 0, 24, 32), (1216, 0, 24, 32)]


def eyes(im, box=None):
    """Pixels to turn red: black next to white (4-neighbourhood), inside box when given."""
    px = im.load()
    W, H = im.size
    x0, y0, w, h = box or (0, 0, W, H)
    found = []
    for y in range(y0, y0 + h):
        for x in range(x0, x0 + w):
            if px[x, y][:3] != (0, 0, 0):
                continue
            near = [px[x + dx, y + dy][:3] for dx, dy in ((1, 0), (-1, 0), (0, 1), (0, -1))
                    if x0 <= x + dx < x0 + w and y0 <= y + dy < y0 + h]
            if (255, 255, 255) in near:
                found.append((x, y))
    return found


def paint(im, pixels, colour):
    px = im.load()
    for x, y in pixels:
        px[x, y] = colour + px[x, y][3:]
    return im


sonic = Image.open(f'{SPRITES}/tileset-sonic.png').convert('RGBA')
mask = eyes(sonic)
paint(sonic.copy(), mask, RED).save(f'{SPRITES}/tileset-sonic-god.png')

# underwater the shoes are no longer red: their black pixels next to the white strap stay black
spx = sonic.load()
shoes = lambda x, y: any(0 <= x + dx < sonic.width and 0 <= y + dy < sonic.height
                         and spx[x + dx, y + dy][0] >= 170 and spx[x + dx, y + dy][1:3] == (0, 0)
                         for dx in range(-2, 3) for dy in range(-2, 3))
water = Image.open(f'{SPRITES}/tileset-sonic-water.png').convert('RGBA')
paint(water, [(x, y) for x, y in mask if not shoes(x, y)], RED).save(f'{SPRITES}/tileset-sonic-god-water.png')

rom = Image.open(f'{SPRITES}/tileset-rom.png').convert('RGB')
for box in ROM_SONIC:
    paint(rom, eyes(rom, box), RED)
rom.save(f'{SPRITES}/tileset-rom-god.png')
print(len(mask), 'eye pixels')
