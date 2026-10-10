#!/usr/bin/env python3
"""Render the act 3 bosses (and their projectiles) straight from the ROM sprite layouts.

Each boss decompresses its art at VRAM $2000 (sprite tiles 0-127): Green Hill / Jungle $2EEB1, Bridge /
Labyrinth $3E508, Scrap Brain / Sky Base $3EF3F (decompressArt: bank * $4000 + HL). They load
S1_BossPalette ($731C) as sprite palette, except Sky Base which keeps the level one.
A layout is drawn at the object's X / Y: rows of 6 bytes, $FE = no sprite, $FF = end of the row
(at the start of a row: end of the layout).

Writes tileset/sprites/tileset-boss.png, its "-flash" variant (_1f06: after a hit, sprite colour 15 is
white every other update) and "-water" (Labyrinth: S1_UnderwaterPalette_Boss below the water line), and
src/Entities/Objects/BossSprites.php with the rectangle of every frame.
"""
import sys
sys.path.insert(0, __file__.rsplit('/', 1)[0])
from PIL import Image
from palcycle import ROM, GAME, decompress_hy, tile_pixels, sms_rgb
from romsprites import sprite_palette, KEY

ART_GH, ART_BRIDGE, ART_SKY = 0x2EEB1, 0x3E508, 0x3EF3F
BOSS_PAL = [sms_rgb(c) for c in ROM[0x731C:0x731C + 16]]
UNDERWATER_BOSS = [sms_rgb(c) for c in ROM[0x026B + 16:0x026B + 32]]


def layout_rows(addr):
    rows = []
    for r in range(3):
        row = ROM[addr + 6 * r:addr + 6 * r + 6]
        if row[0] == 0xFF:
            break
        tiles = []
        for b in row:
            if b == 0xFF:
                break
            tiles.append(None if b == 0xFE else b)
        rows.append(tiles)
    return rows


def render(art, rows, pal):
    """rows of tile numbers (None = no sprite) -> image of palette indexes (None = transparent)."""
    width = max((len(r) for r in rows), default=1) * 8
    img = [[None] * width for _ in range(len(rows) * 16)]
    for ry, row in enumerate(rows):
        for rx, tile in enumerate(row):
            if tile is None:
                continue
            for half in range(2):
                pix = tile_pixels(art, tile + half)
                for y in range(8):
                    for x in range(8):
                        if pix[y][x]:
                            img[ry * 16 + half * 8 + y][rx * 8 + x] = pix[y][x]
    return img, pal


def frames():
    out = {}
    egg = list(range(12))   # _7922: 12 layouts of 18 bytes (normal right, hit / defeated, ...)

    def boss(prefix, art_addr, pal, entries):
        art = decompress_hy(art_addr)
        for name, rows in entries.items():
            out[f'{prefix}_{name}'] = render(art, rows, pal)

    gh = {'left': layout_rows(0x72F8), 'right': layout_rows(0x730A)}
    gh.update({f'egg{k}': layout_rows(0x7922 + 18 * k) for k in egg})
    # _79fa: exhaust flame, one 8x16 sprite: tiles 0/2 behind a ship going left, 4/6 going right
    gh.update({f'flame{k}': [[2 * k]] for k in range(4)})
    boss('gh', ART_GH, BOSS_PAL, gh)

    jungle = {'left': layout_rows(0x81F4), 'right': layout_rows(0x8206)}
    jungle.update({f'egg{k}': layout_rows(0x7922 + 18 * k) for k in egg})
    jungle.update({f'flame{k}': [[2 * k]] for k in range(4)})
    # bomb $2B (_8218): fuse _82cd frames 0-1, then the explosion _a3bb frames 1-3
    jungle.update({f'bomb{k}': layout_rows(0x82CD + 18 * k) for k in range(2)})
    jungle.update({f'boom{k}': layout_rows(0xA3BB + 18 * k) for k in (1, 2, 3)})
    boss('jungle', ART_GH, BOSS_PAL, jungle)

    for prefix, body in (('bridge', 0x865A), ('lab', 0x9493)):
        entries = {'body': layout_rows(body)}
        entries.update({f'egg{k}': layout_rows(0x7922 + 18 * k) for k in egg})
        # bullet $0D (_6ac1) in Bridge 3 / Labyrinth 3: tiles $34 / $36 every other frame (_6b72)
        entries.update({'bullet0': [[0x34]], 'bullet1': [[0x36]]})
        if prefix == 'lab':
            # thruster under the rising ship (tiles 0 / 2 at X+$10, Y+$30)
            entries.update({'thrust0': [[0x00]], 'thrust1': [[0x02]]})
            # homing torpedo $2F (_94a5): _9688 going right, _9698 going left, 2 frames 8 bytes apart
            entries.update({'torpedo_r0': layout_rows(0x9688), 'torpedo_r1': layout_rows(0x9690),
                            'torpedo_l0': layout_rows(0x9698), 'torpedo_l1': layout_rows(0x96A0)})
        boss(prefix, ART_BRIDGE, BOSS_PAL, entries)

    # Robotnik on foot (_baf9: 7 frames of 18 bytes), Scrap Brain lift _a9c0, Sky Base end _bb77
    on_foot = {f'f{k}': layout_rows(0xBAF9 + 18 * k) for k in range(7)}
    boss('sb', ART_SKY, BOSS_PAL, dict(on_foot, lift=layout_rows(0xA9C0)))
    boss('sky', ART_SKY, sprite_palette(18), dict(on_foot, end=layout_rows(0xBB77)))
    return out


def sheet(images, mapping=None, flash=False):
    """Pack the frames in rows of at most 2048px. mapping: palette index -> colour override."""
    rects = {}
    x = y = row_h = 0
    for name, (img, pal) in images.items():
        h, w = len(img), len(img[0])
        if x + w > 2048:
            x, y, row_h = 0, y + row_h + 2, 0
        rects[name] = [x, y, w, h]
        x += w + 2
        row_h = max(row_h, h)
    out = Image.new('RGB', (2048, y + row_h), KEY)
    px = out.load()
    for name, (img, pal) in images.items():
        rx, ry, w, h = rects[name]
        colours = list(mapping) if mapping else pal
        if flash:
            colours = colours[:15] + [sms_rgb(0x3F)]
        for yy in range(h):
            for xx in range(w):
                c = img[yy][xx]
                if c is not None:
                    px[rx + xx, ry + yy] = colours[c]
    return out, rects


def main():
    images = frames()
    img, rects = sheet(images)
    img.save(f'{GAME}/assets/tileset/sprites/tileset-boss.png')
    sheet(images, flash=True)[0].save(f'{GAME}/assets/tileset/sprites/tileset-boss-flash.png')
    # only Labyrinth's boss is drawn under water: the whole sheet with its underwater palette
    sheet(images, mapping=UNDERWATER_BOSS)[0].save(f'{GAME}/assets/tileset/sprites/tileset-boss-water.png')
    lines = ['<?php', '', 'namespace SonicGame\\Entities\\Objects;', '',
             '/** Frames of tileset/sprites/tileset-boss.png, generated by tools/bosssprites.py from the ROM layouts. */',
             'final class BossSprites', '{', '    public const SHEET = \'boss\';', '', '    public const FRAMES = [']
    lines += [f"        '{name}' => [{r[0]}, {r[1]}, {r[2]}, {r[3]}]," for name, r in rects.items()]
    lines += ['    ];', '}', '']
    open(f'{GAME}/src/Entities/Objects/BossSprites.php', 'w').write('\n'.join(lines))
    print(len(rects), 'frames')


if __name__ == '__main__':
    main()
