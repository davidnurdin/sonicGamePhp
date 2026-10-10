#!/usr/bin/env python3
"""Render the Green Hill flower tiles patched in the name table by object $50 (doObjectCode_flower, _7b5d).

The object writes 2x2 tiles at (X, Y) and (X, Y+16): 'low' = blank tile 0 over the flower head,
'high' = flower head over the stem (2E/2F). Output: tileset/sprites/tileset-flower.png, two 16x32 frames.
"""
import sys
sys.path.insert(0, __file__.rsplit('/', 1)[0])
from PIL import Image
from palcycle import decompress_hy, tile_pixels, cycle_palettes, read_meta, GAME

HEAD = [[0xF0, 0xF1], [0xE2, 0xF2]]
STEM = [[0x2E, 0x2F], [0x2E, 0x2F]]
BLANK = [[0x00, 0x00], [0x00, 0x00]]


def main():
    meta = read_meta(1)
    art = decompress_hy(0x30000 + meta['LA'])
    palette = cycle_palettes(meta['CP'], meta['CC'])[0]
    out = Image.new('RGB', (32, 32))
    for frame, (top, bottom) in enumerate([(BLANK, HEAD), (HEAD, STEM)]):
        for half, quad in enumerate((top, bottom)):
            for ty in range(2):
                for tx in range(2):
                    pix = tile_pixels(art, quad[ty][tx])
                    for y in range(8):
                        for x in range(8):
                            out.putpixel((frame * 16 + tx * 8 + x, half * 16 + ty * 8 + y), palette[pix[y][x]])
    out.save(f'{GAME}/assets/tileset/sprites/tileset-flower.png')


if __name__ == '__main__':
    main()
