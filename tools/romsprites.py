#!/usr/bin/env python3
"""Render object sprites straight from the Sonic 1 SMS ROM (level sprite art + sprite palette).

A sprite layout is the ROM format: rows of up to 6 bytes, each byte a 8x16 sprite (tiles n, n+1 of the
level sprite art loaded at VRAM $2000), $FE = empty row, $FF = nothing. Colour 0 is transparent.
Writes tileset/sprites/tileset-rom.png, the sheet used by the objects that are not in the ripped sheets;
ROM_SPRITES in src/Entities/Objects/RomSprites.php gives the position of each frame.
"""
import sys
sys.path.insert(0, __file__.rsplit('/', 1)[0])
from PIL import Image
from palcycle import ROM, GAME, decompress_hy, tile_pixels, sms_rgb, read_meta

PALETTE_POINTERS = 0x627C
KEY = (170, 0, 170)


def sprite_art(level):
    meta = read_meta(level)
    return decompress_hy(meta['Unknown_09'] * 0x4000 + meta['SA'])


def sprite_palette(level, ip=None):
    meta = read_meta(level)
    ip = meta['IP'] if ip is None else ip
    ptr = int.from_bytes(ROM[PALETTE_POINTERS + 2 * ip:PALETTE_POINTERS + 2 * ip + 2], 'little')
    return [sms_rgb(c) for c in ROM[ptr + 16:ptr + 32]]


def render(level, rows, palette=None, art_offset=None):
    """rows: list of rows of tile indices (None = nothing). Returns an RGB image keyed with KEY."""
    art = decompress_hy(art_offset) if art_offset else sprite_art(level)
    pal = palette or sprite_palette(level)
    width = max(len(r) for r in rows) * 8
    img = Image.new('RGB', (width, len(rows) * 16), KEY)
    px = img.load()
    for ry, row in enumerate(rows):
        for rx, tile in enumerate(row):
            if tile is None:
                continue
            for half in range(2):
                pix = tile_pixels(art, tile + half)
                for y in range(8):
                    for x in range(8):
                        if pix[y][x]:
                            px[rx * 8 + x, ry * 16 + half * 8 + y] = pal[pix[y][x]]
    return img


# Sky Base tiles: rotating turret $37 (_b227), cannon $33 firing (_ae04), cannon ball $34 (_ae81),
# propeller $31 (_ab01), bullet $0D (_6b70)
SKY_TILES = [0x66, 0x70, 0x72, 0x4C, 0x4E, 0x6C, 0x6E, 0x64, 0x68, 0x6A, 0x48, 0x4A, 0x50, 0x52,
             0x74, 0x76, 0x78, 0x7A, 0x7C, 0x7E, 0x02, 0x04, 0x1C, 0x3C, 0x1E, 0x3E, 0x38, 0x3A, 0x1A, 0x06, 0x08]

SONIC_ART = 0x20000


def sonic_frame(frame, level):
    """Sonic frame of the ROM: 3bpp ($20000 + frame * $120), 12 tiles in the order of the layout _591d."""
    pal = sprite_palette(level)
    img = Image.new('RGB', (24, 32), KEY)
    px = img.load()
    base = SONIC_ART + frame * 0x120
    for k in range(12):
        col, block, half = (k // 2) % 3, k // 6, k % 2
        for r in range(8):
            b = ROM[base + k * 24 + r * 3:base + k * 24 + r * 3 + 3]
            for x in range(8):
                c = ((b[0] >> (7 - x)) & 1) | (((b[1] >> (7 - x)) & 1) << 1) | (((b[2] >> (7 - x)) & 1) << 2)
                if c:
                    px[col * 8 + x, block * 16 + half * 8 + r] = pal[c]
    return img


# name => (level whose sprite art/palette is used, rows of the ROM layout without the leading $FE row)
SPRITES = {
    'gh_platform': (1, [[0x18, 0x1A, 0x18, 0x1A]]),                # _6911
    'bridge_platform': (4, [[0x6C, 0x6E, 0x6C, 0x6E]]),            # _6931, _b5b5
    'log_platform': (7, [[0x6C, 0x6E, 0x6E, 0x48]]),               # _6923, _7ed9 ($28)
    'log_vertical': (7, [[0x18, 0x1A], [0x28, 0x2E]]),            # _7e89 ($27)
    'log_roll0': (7, [[0x3A, 0x3C]]),                              # _8022 ($29)
    'log_roll1': (7, [[0x36, 0x38]]),
    'log_roll2': (7, [[0x4C, 0x4E]]),
    'sky_platform': (16, [[0x36, 0x36, 0x36, 0x36]]),              # _b37b ($38, $3B)
    'propeller0': (16, [[0x1C], [0x3C]]),                          # _b388, at X-8, Y+8
    'propeller1': (16, [[0x1E], [0x3E]]),
    'propeller2': (16, [[0x38], [0x3A]]),
    'propeller3': (16, [[0x1A]]),                                   # at X-8, Y+$0C
    'seesaw_pad': (4, [[0x36, 0x38, 0x3A]]),                       # _8830 ($4E)
    'seesaw_weight': (4, [[0x3C, 0x3E]]),                          # _8834
    'unidus_ball': (10, [[0x24]]),
    # Sky Base boss weapon at ($430, $1A0), _ba31 / firing _ba3b: art loaded by the electric beam $46 ($3EF3F)
    'sky_weapon0': (18, [[0x20, 0x22]], 0x3EF3F),
    'sky_weapon1': (18, [[0x24, 0x26]], 0x3EF3F),
    'beam0': (18, [[0x36, 0x38]], 0x3EF3F),                        # electric beam $46 segments (_bcc7)
    'beam1': (18, [[0x56, 0x58]], 0x3EF3F),
    'beam_emitter': (18, [[0x40, 0x42]], 0x3EF3F),                 # _bcdd
    'eball0': (18, [[0x44, 0x46]], 0x3EF3F),                       # boss shot $47 (_bdce)
    'eball1': (18, [[0x48, 0x08]], 0x3EF3F),                                 # $35 orbiting balls, $36 thrown ball
}


def main():
    images = {name: render(e[0], e[1], art_offset=e[2] if len(e) > 2 else None) for name, e in SPRITES.items()}
    # teleport beam (Sonic animation $0E, frames 25-33)
    for f in range(25, 34):
        images[f'teleport{f - 25}'] = sonic_frame(f, 18)
    # end sequence $53: Robotnik's ship (_bf21) and the damaged one with its flame (_bf33), boss art + S1_BossPalette
    boss_pal = [sms_rgb(c) for c in ROM[0x731C:0x731C + 16]]
    ship = [[0x4A, 0x4C, 0x4E, 0x50, 0x52], [0x6A, 0x6C, 0x6E, 0x70, 0x72]]
    images['ship0'] = render(19, [[0x2A, 0x2C, 0x2E, 0x30, 0x32]] + ship, boss_pal)
    # (_bf33 has a 4th row, $5C $5E, that processSpriteLayout never draws: it stops after 3 rows)
    images['ship1'] = render(19, [[0x2A, 0x34, 0x36, 0x38, 0x32]] + ship, boss_pal)
    # Sky Base: passing cloud $30 (_aa63), moving spiked wall $39 (_b45b + the row drawn above by _3581)
    images['cloud'] = render(17, [[0x40, 0x42, 0x44, 0x46]])
    images['spike_wall'] = render(17, [[0x16, 0x18]] * 4)
    # special stages: moving bumper $21 (_9b6e), flipper $4C at rest (_9a7e), swinging (_9a90), up (_9aa2)
    images['bumper'] = render(32, [[0x08, 0x0A, 0x28, 0x2A]])
    images['flipper0'] = render(31, [[None] * 4, [0x38, 0x3A, 0x3C, 0x3E]])
    images['flipper1'] = render(31, [[0x48, 0x4A, 0x4C, 0x4E], [0x68, 0x6A, 0x6C, 0x6E]])
    images['flipper2'] = render(31, [[None, 0x12, 0x14, 0x16], [None, 0x32, 0x34, 0x36]])
    # shield: Sonic's frame $18 shown instead of his own every other frame (_5206)
    images['shield'] = sonic_frame(0x18, 1)
    # Sonic waiting (animation $0D after $168 idle frames): frames $15, $16, $17
    for f in (0x15, 0x16, 0x17):
        images[f'wait{f}'] = sonic_frame(f, 1)
    # Sky Base bomb $32 (_ad0b): walking left 0-1, right 2-3, lit 4, 5
    for k, pair in enumerate([(0x0A, 0x0C), (0x0E, 0x10), (0x2A, 0x2C), (0x2E, 0x30), (0x12, 0x14), (0x32, 0x34)]):
        images[f'bomb{k}'] = render(16, [list(pair)])
    # Sonic thrown up by a spring / the Bridge balance, not rolling (animation $13): frame $26
    images['spring'] = sonic_frame(0x26, 1)
    # Bridge falling piece $2E (_8481): tiles $70 $72, drawn 16px under the object
    images['bridge_plank'] = render(4, [[0x70, 0x72]])
    # Labyrinth water slide (block behaviours 13-16, $D28C): animation $11, frames $12 / $24 six frames each
    images['slide0'] = sonic_frame(0x12, 1)
    images['slide1'] = sonic_frame(0x24, 1)
    width = sum(im.width + 2 for im in images.values())
    height = max(im.height for im in images.values())
    # second row: single 8x16 sprites of the Sky Base sprite art (level 17), composed by the objects
    tiles = SKY_TILES
    width = max(width, len(tiles) * 10)
    sheet = Image.new('RGB', (width, height + 2 + 16), KEY)
    for i, t in enumerate(tiles):
        sheet.paste(render(17, [[t]]), (i * 10, height + 2))
    print('    public const SKY_TILE_Y = %d;  // x = index * 10 in SKY_TILES' % (height + 2))
    x = 0
    for name, im in images.items():
        sheet.paste(im, (x, 0))
        print(f"        '{name}' => [{x}, 0, {im.width}, {im.height}],")
        x += im.width + 2
    sheet.save(f'{GAME}/assets/tileset/sprites/tileset-rom.png')


if __name__ == '__main__':
    main()
