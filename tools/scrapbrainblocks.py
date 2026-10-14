#!/usr/bin/env python3
"""Scrap Brain (tilesets 14/15/16): the ripped PNGs stop at block 175 but the maps use blocks up to 185,
drawn black. The missing blocks are rendered from the ROM (block mappings BM, art LA, see palcycle.py) with
the palette of each variant (cycle step k; the plain PNG was ripped at step 0 / 1 / 2 for acts 1 / 2 / 3) and appended; the -fg layers get the
priority blocks (solidity bit 7) with colour 0 transparent. Delete the 4096px slices (-0/-1) afterwards,
the game makes them again.

  --check   only compare the rendering with the existing blocks
"""
import sys
from PIL import Image

from palcycle import GAME, ROM, cycle_palettes, decompress_hy, read_meta, tile_pixels

BLOCKS = 192
TILESETS = {14: 13, 15: 14, 16: 15}     # tileset => a level using it


def block_indices(meta):
    art = decompress_hy(0x30000 + meta['LA'])
    mappings = ROM[0x10000 + meta['BM']:0x10000 + meta['BM'] + 16 * BLOCKS]
    out = []
    for b in range(BLOCKS):
        idx = [[0] * 32 for _ in range(32)]
        for t in range(16):
            pix = tile_pixels(art, mappings[b * 16 + t])
            for y in range(8):
                for x in range(8):
                    idx[(t // 4) * 8 + y][(t % 4) * 8 + x] = pix[y][x]
        out.append(idx)
    return out


def draw(img, b, idx, pal, fg, priority):
    o = img.load()
    for y in range(32):
        for x in range(32):
            i = idx[y][x]
            if fg and (not priority or i == 0):
                o[b * 32 + x, y] = (0, 0, 0, 0)
            else:
                o[b * 32 + x, y] = pal[i] + (255,)


def main():
    check = '--check' in sys.argv
    for tileset, level in TILESETS.items():
        meta = read_meta(level)
        blocks = block_indices(meta)
        solidity = open(f'{GAME}/assets/levels/level{level}.solidity', 'rb').read()
        palettes = cycle_palettes(meta['CP'], meta['CC'])
        base = f'{GAME}/assets/tileset/levels/tileset{tileset}'
        variants = [('', tileset - 14)] + [(f'-cycle{k}', k) for k in range(len(palettes))]
        for name, k in variants:
            for layer in ('', '-fg'):
                path = f'{base}{name}{layer}.png'
                src = Image.open(path).convert('RGBA')
                have = src.size[0] // 32
                ref = Image.new('RGBA', (have * 32, 32))
                for b in range(have):
                    draw(ref, b, blocks[b], palettes[k], layer == '-fg', b < len(solidity) and solidity[b] & 0x80)
                a, r = src.load(), ref.load()
                diff = sum(1 for y in range(32) for x in range(have * 32)
                           if (a[x, y][3] or r[x, y][3]) and a[x, y] != r[x, y])
                print(f'{path.split("/")[-1]}: {have} blocks, {diff} pixels differ')
                if check or have >= BLOCKS:
                    continue
                out = Image.new('RGBA', (BLOCKS * 32, 32))
                out.paste(src, (0, 0))
                for b in range(have, BLOCKS):
                    draw(out, b, blocks[b], palettes[k], layer == '-fg', b < len(solidity) and solidity[b] & 0x80)
                out.save(path)


if __name__ == '__main__':
    main()
