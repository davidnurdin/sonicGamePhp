#!/usr/bin/env python3
"""Decode level block art from the Sonic 1 SMS ROM and write palette-cycle variants of the tilesets.

For every level whose header has more than one colour cycle (CC), each 32x32 block is rendered
with palette indices, checked against the existing tileset PNG, then recoloured with every cycle
palette and saved as tileset{T}-cycle{k}.png next to the original.
"""
import ast
import re
import sys
from PIL import Image

ROM = open('/home/david/poc/sonic/sonic_level/sonic.sms', 'rb').read()
GAME = '/home/david/poc/sonicGamePhp'
CYCLE_POINTERS = 0x628C

LEVEL_TO_TILESET = {1: 1, 2: 2, 3: 3, 4: 4, 5: 5, 6: 6, 7: 7, 8: 8, 9: 9, 10: 10, 11: 11, 12: 12,
                    13: 14, 14: 15, 15: 16, 16: 18, 17: 19, 18: 26, 19: 1, 20: 15, 21: 15, 22: 15, 23: 15,
                    24: 15, 25: 15, 26: 26, 27: 27, 28: 30, 29: 30}


def read_meta(level):
    text = open(f'{GAME}/assets/levels/level{level}.meta').read()
    return {k: int(v) for k, v in re.findall(r"'(\w+)'\s*=>\s*(\d+)", text)}


def decompress_hy(offset):
    magic, offsets_ptr, rows_ptr, row_count = (int.from_bytes(ROM[offset + i:offset + i + 2], 'little') for i in range(0, 8, 2))
    assert magic == 0x5948, f'no HY art at {offset:05X}'
    bitmask = ROM[offset + 8:offset + offsets_ptr]
    offsets = ROM[offset + offsets_ptr:offset + rows_ptr]
    rows_base = offset + rows_ptr
    out = bytearray()
    oi = ri = 0
    for b in bitmask:
        for i in range(8):
            if not (b >> i) & 1:
                use = ri
                ri += 4
            else:
                use = offsets[oi]
                oi += 1
                if use >= 0xF0:
                    use = ((use - 0xF0) << 8) + offsets[oi]
                    oi += 1
                use *= 4
            out += ROM[rows_base + use:rows_base + use + 4]
    return bytes(out)


def tile_pixels(art, index):
    rows = []
    for y in range(8):
        p = art[index * 32 + y * 4:index * 32 + y * 4 + 4]
        if len(p) < 4:
            p = bytes(4)
        rows.append([((p[0] >> (7 - x)) & 1) | (((p[1] >> (7 - x)) & 1) << 1) |
                     (((p[2] >> (7 - x)) & 1) << 2) | (((p[3] >> (7 - x)) & 1) << 3) for x in range(8)])
    return rows


def sms_rgb(c):
    return ((c & 3) * 85, ((c >> 2) & 3) * 85, ((c >> 4) & 3) * 85)


def cycle_palettes(cp, cc):
    ptr = int.from_bytes(ROM[CYCLE_POINTERS + 2 * cp:CYCLE_POINTERS + 2 * cp + 2], 'little')
    return [[sms_rgb(c) for c in ROM[ptr + 16 * k:ptr + 16 * k + 16]] for k in range(cc)]


def main():
    done = set()
    for level, tileset in sorted(LEVEL_TO_TILESET.items()):
        meta = read_meta(level)
        if meta.get('CC', 0) <= 1 or tileset in done:
            continue
        src = Image.open(f'{GAME}/assets/tileset/levels/tileset{tileset}.png').convert('RGB')
        blocks = src.size[0] // 32
        art = decompress_hy(0x30000 + meta['LA'])
        mappings = ROM[0x10000 + meta['BM']:0x10000 + meta['BM'] + 16 * blocks]
        palettes = cycle_palettes(meta['CP'], meta['CC'])

        index = Image.new('P', src.size)
        idx = index.load()
        for b in range(blocks):
            for t in range(16):
                pix = tile_pixels(art, mappings[b * 16 + t])
                for y in range(8):
                    for x in range(8):
                        idx[b * 32 + (t % 4) * 8 + x, (t // 4) * 8 + y] = pix[y][x]

        # How well does cycle frame 0 reproduce the existing PNG? Ring tiles ($FC-$FF) are animated so they may differ.
        s = src.load()
        mismatch = sum(1 for y in range(32) for x in range(src.size[0]) if s[x, y] != palettes[0][idx[x, y]])
        total = 32 * src.size[0]
        print(f'level {level:2d} tileset {tileset:2d} CP={meta["CP"]} CC={meta["CC"]} CS={meta["CS"]} mismatch {mismatch}/{total} ({100 * mismatch / total:.2f}%)')
        if mismatch / total > 0.02:
            print('  -> rendering does not match, skipped')
            continue

        cycled = [i for i in range(16) if len({p[i] for p in palettes}) > 1]
        for k in range(1, len(palettes)):
            out = src.copy()
            o = out.load()
            for y in range(32):
                for x in range(src.size[0]):
                    if idx[x, y] in cycled and s[x, y] == palettes[0][idx[x, y]]:
                        o[x, y] = palettes[k][idx[x, y]]
            out.save(f'{GAME}/assets/tileset/levels/tileset{tileset}-cycle{k}.png')
        print(f'  cycled indices {cycled} -> wrote {len(palettes) - 1} variants')
        done.add(tileset)


if __name__ == '__main__':
    main()
