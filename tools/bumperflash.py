#!/usr/bin/env python3
"""Bonus stage bumper flash (_57be / _1f06): after a bounce on a big bumper, background colour 14 is set to
white ($3F) every other call for 4 frames. Writes tileset31-flash.png: tileset31 with every pixel of colour
index 14 turned white (the blocks are decoded from the ROM to know the palette index of each pixel)."""
import sys
sys.path.insert(0, __file__.rsplit('/', 1)[0])
from PIL import Image
from palcycle import ROM, GAME, read_meta, decompress_hy, tile_pixels, cycle_palettes, sms_rgb

meta = read_meta(28)
src = Image.open(f'{GAME}/assets/tileset/levels/tileset31.png').convert('RGBA')
blocks = src.size[0] // 32
art = decompress_hy(0x30000 + meta['LA'])
mappings = ROM[0x10000 + meta['BM']:0x10000 + meta['BM'] + 16 * blocks]
palette = cycle_palettes(meta['CP'], 1)[0]
out = src.copy()
s, o = src.load(), out.load()
mismatch = changed = 0
for b in range(blocks):
    for t in range(16):
        pix = tile_pixels(art, mappings[b * 16 + t])
        for y in range(8):
            for x in range(8):
                X, Y = b * 32 + (t % 4) * 8 + x, (t // 4) * 8 + y
                if s[X, Y][:3] != palette[pix[y][x]]:
                    mismatch += 1
                elif pix[y][x] == 14:
                    o[X, Y] = sms_rgb(0x3F) + (255,)
                    changed += 1
print(f'{blocks} blocks, mismatch {mismatch}, colour 14 pixels {changed}, colour 14 = {palette[14]}')
out.save(f'{GAME}/assets/tileset/levels/tileset31-flash.png')
