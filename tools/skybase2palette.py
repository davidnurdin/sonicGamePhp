#!/usr/bin/env python3
"""Sky Base act 2 (level 17, tileset 19): the level header's cycle palette CP 8 (S1_PaletteCycles_8, $651E)
replaces the whole tile palette on every cycle step (_23c9), so the level is shown with it (red balloon,
teal hull) and not with its initial exterior palette; colours 9-12 rotate (the fans seen from the front).
The blocks are rendered from the ROM with their palette indices (see palcycle.py) and the pixels in the
colour of the old palette are recoloured: tileset19.png (step 0), tileset19-cycle{k}.png (+ -fg layers).
Delete the 4096px slices (-0/-1) afterwards, the game makes them again.
"""
from PIL import Image

from palcycle import GAME, ROM, decompress_hy, read_meta, sms_rgb, tile_pixels

LEVEL, TILESET = 17, 19
OLD = 0x645E + 16 * 3     # the palette the PNG was ripped with (Sky Base 1 cycle, step 3)
CYCLES = 0x651E


def palette(addr):
    return [sms_rgb(c) for c in ROM[addr:addr + 16]]


def main():
    meta = read_meta(LEVEL)
    base = f'{GAME}/assets/tileset/levels/tileset{TILESET}'
    sources = {layer: Image.open(f'{base}{layer}.png').convert('RGBA') for layer in ('', '-fg')}
    width = sources[''].size[0]
    art = decompress_hy(0x30000 + meta['LA'])
    mappings = ROM[0x10000 + meta['BM']:0x10000 + meta['BM'] + 16 * (width // 32)]
    idx = [[0] * width for _ in range(32)]
    for b in range(width // 32):
        for t in range(16):
            pix = tile_pixels(art, mappings[b * 16 + t])
            for y in range(8):
                for x in range(8):
                    idx[(t // 4) * 8 + y][b * 32 + (t % 4) * 8 + x] = pix[y][x]
    old = palette(OLD)
    for k in range(4):
        new = palette(CYCLES + 16 * k)
        for layer, src in sources.items():
            img = src.copy()
            o = img.load()
            kept = 0
            for y in range(32):
                for x in range(width):
                    r, g, b, a = o[x, y]
                    if not a:
                        continue
                    i = idx[y][x]
                    if (r, g, b) == old[i]:
                        o[x, y] = new[i] + (a,)
                    else:
                        kept += 1   # animated ring tiles...
            img.save(f'{base}-cycle{k}{layer}.png')
            if k == 0:
                img.save(f'{base}{layer}.png')
            print(f'cycle {k}{layer}: {kept} pixels kept')


if __name__ == '__main__':
    main()
