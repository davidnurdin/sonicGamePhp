#!/usr/bin/env python3
"""Sky Base boss glass (doObjectCode_boss_skyBase, _b821): once broken, 15 patches of 2x2 tiles ($BA45, 12 bytes
each: X, Y, 4 name table words) are written into the background, one a frame. Renders them with the level 18 art
and tile palette into tileset/sprites/tileset-skyglass.png (15 cells of 16x16) and prints their positions."""
import sys
sys.path.insert(0, __file__.rsplit('/', 1)[0])
from PIL import Image
from palcycle import ROM, GAME, decompress_hy, tile_pixels, sms_rgb, read_meta

PALETTE_POINTERS = 0x627C
TABLE = 0xBA45


def main():
    meta = read_meta(18)
    art = decompress_hy(0x30000 + meta['LA'])
    ptr = int.from_bytes(ROM[PALETTE_POINTERS + 2 * meta['IP']:PALETTE_POINTERS + 2 * meta['IP'] + 2], 'little')
    pal = [sms_rgb(c) for c in ROM[ptr:ptr + 16]]
    out = Image.new('RGB', (15 * 16, 16))
    positions = []
    for i in range(15):
        e = ROM[TABLE + 12 * i:TABLE + 12 * i + 12]
        x, y = e[0] | e[1] << 8, e[2] | e[3] << 8
        positions.append((x, y))
        for t in range(4):
            tile = e[4 + 2 * t] | (e[5 + 2 * t] & 1) << 8
            pix = tile_pixels(art, tile)
            for py in range(8):
                for px in range(8):
                    out.putpixel((i * 16 + (t % 2) * 8 + px, (t // 2) * 8 + py), pal[pix[py][px]])
    out.save(f'{GAME}/assets/tileset/sprites/tileset-skyglass.png')
    print(positions)


if __name__ == '__main__':
    main()
