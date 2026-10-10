#!/usr/bin/env python3
"""Sky Base act 1 lightning (level header TL bit 1, _1f49): every 171 frames the cycle palettes switch
to S1_PaletteCycles_SkyBase1_Lightning1 ($649E) at frame 100 and _Lightning2 ($64DE, thunder SFX $13)
at frame 130. Several palette indices share a colour in the normal palette, so the blocks are rendered
from the ROM with their palette indices (see palcycle.py) and recoloured:
tileset{T}-light{1,2}-cycle{k}.png (+ -fg layers), k = cycle step.
"""
from PIL import Image

from palcycle import GAME, ROM, decompress_hy, read_meta, sms_rgb, tile_pixels

LEVEL, TILESET = 16, 18
PALETTES = {0: 0x645E, 1: 0x649E, 2: 0x64DE}


def palettes(addr):
    return [[sms_rgb(c) for c in ROM[addr + 16 * k:addr + 16 * k + 16]] for k in range(4)]


def main():
    meta = read_meta(LEVEL)
    base = f'{GAME}/assets/tileset/levels/tileset{TILESET}'
    src = Image.open(f'{base}-cycle0.png').convert('RGB')
    blocks = src.size[0] // 32
    art = decompress_hy(0x30000 + meta['LA'])
    mappings = ROM[0x10000 + meta['BM']:0x10000 + meta['BM'] + 16 * blocks]
    index = Image.new('P', src.size)
    idx = index.load()
    for b in range(blocks):
        for t in range(16):
            pix = tile_pixels(art, mappings[b * 16 + t])
            for y in range(8):
                for x in range(8):
                    idx[b * 32 + (t % 4) * 8 + x, (t // 4) * 8 + y] = pix[y][x]

    normal = palettes(PALETTES[0])
    for k in range(4):
        cycle = Image.open(f'{base}-cycle{k}.png').convert('RGB')
        c = cycle.load()
        bad = sum(1 for y in range(32) for x in range(cycle.size[0]) if c[x, y] != normal[k][idx[x, y]])
        print(f'cycle {k}: {bad} pixels differ from the ROM render')
        for light in (1, 2):
            pal = palettes(PALETTES[light])[k]
            changed = [i for i in range(16) if pal[i] != normal[k][i]]
            for layer in ('', '-fg'):
                try:
                    img = Image.open(f'{base}-cycle{k}{layer}.png').convert('RGBA')
                except FileNotFoundError:
                    continue
                o = img.load()
                for y in range(32):
                    for x in range(img.size[0]):
                        i = idx[x, y]
                        # keep the animated ring tiles and anything that is not the ROM colour as it is
                        if i in changed and o[x, y][3] and o[x, y][:3] == normal[k][i]:
                            o[x, y] = pal[i] + (o[x, y][3],)
                img.save(f'{base}-light{light}-cycle{k}{layer}.png')
            print(f'  light{light}: indices {changed}')


if __name__ == '__main__':
    main()
