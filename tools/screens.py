#!/usr/bin/env python3
"""Render the Sonic 1 SMS title screen, map screens and act-complete screen from the ROM.

Mini VDP: HY-compressed art loaded at the VRAM addresses the game uses, RLE screen layouts
(decompressScreen at $0501), `print` text strings and 8x16 sprite layouts (processSpriteLayout).
Writes PNGs to assets/screens and the animation / positioning data to assets/screens/screens.data.php.
"""
import os
from PIL import Image

from palcycle import ROM, GAME, decompress_hy, tile_pixels, sms_rgb

OUT = f'{GAME}/assets/screens'
os.makedirs(OUT, exist_ok=True)


def word(a):
    return ROM[a] | ROM[a + 1] << 8


class Vdp:
    def __init__(self, palette_addr):
        self.vram_tiles = bytearray(512 * 32)
        self.names = [0] * (32 * 28)
        pal = ROM[palette_addr:palette_addr + 32]
        self.pal = [sms_rgb(c) for c in pal]

    def load_art(self, rom_addr, vram_addr):
        art = decompress_hy(rom_addr)
        self.vram_tiles[vram_addr:vram_addr + len(art)] = art[:len(self.vram_tiles) - vram_addr]

    def screen(self, rom_addr, length, high):
        """decompressScreen: two equal bytes in a row = run (next byte = extra count), $FF = skip."""
        pos = 0
        i = rom_addr
        end = rom_addr + length
        prev = None
        while i < end:
            b = ROM[i]
            if prev is not None and b == prev:
                i += 1
                if i >= end:
                    break
                count = ROM[i] or 256
                if b == 0xFF:
                    pos += count
                else:
                    for _ in range(count):
                        self.names[pos] = b | high << 8
                        pos += 1
                i += 1
                prev = None
                continue
            if b == 0xFF:
                pos += 1
            else:
                self.names[pos] = b | high << 8
                pos += 1
            prev = b
            i += 1

    def print(self, addr, high=0):
        col, row = ROM[addr], ROM[addr + 1]
        pos = row * 32 + col
        a = addr + 2
        while ROM[a] != 0xFF:
            self.names[pos] = ROM[a] | high << 8
            pos += 1
            a += 1

    def print_bytes(self, col, row, tiles, high=0):
        for k, t in enumerate(tiles):
            self.names[row * 32 + col + k] = t | high << 8

    def tile(self, n):
        return tile_pixels(self.vram_tiles, n)

    def render(self, priority_only=False):
        img = Image.new('RGBA', (256, 192), (0, 0, 0, 0))
        px = img.load()
        for ty in range(24):
            for tx in range(32):
                e = self.names[ty * 32 + tx]
                if priority_only and not e & 0x1000:
                    continue
                t = self.tile(e & 0x1FF)
                hf, vf, pal = e & 0x200, e & 0x400, 16 if e & 0x800 else 0
                for y in range(8):
                    for x in range(8):
                        c = t[7 - y if vf else y][7 - x if hf else x]
                        if priority_only and c == 0:
                            continue
                        px[tx * 8 + x, ty * 8 + y] = self.pal[pal + c] + (255,)
        return img

    def sprite(self, img, n, x, y):
        """8x16 sprite, tiles taken from VRAM $2000 (tile 256+). The VDP draws sprites one line lower."""
        px = img.load()
        base = 256 + (n & 0xFE)
        for half in range(2):
            t = self.tile(base + half)
            for yy in range(8):
                for xx in range(8):
                    c = t[yy][xx]
                    X, Y = x + xx, y + 1 + half * 8 + yy
                    if c and 0 <= X < img.size[0] and 0 <= Y < img.size[1]:
                        px[X, Y] = self.pal[16 + c] + (255,)

    def layout(self, addr):
        """processSpriteLayout data: up to 3 rows x 6 columns of 8x16 sprites, $FF ends a row early / $FE = gap."""
        img = Image.new('RGBA', (48, 48), (0, 0, 0, 0))
        for row in range(3):
            first = ROM[addr + row * 6]
            if first == 0xFF:
                break
            for col in range(6):
                n = ROM[addr + row * 6 + col]
                if n < 0xFE:
                    self.sprite(img, n, col * 8, row * 16)
        return img

    def digits(self):
        img = Image.new('RGBA', (80, 16), (0, 0, 0, 0))
        for d in range(10):
            self.sprite(img, 0x80 + 2 * d, d * 8, -1)
        return img


def php(value, indent=0):
    pad = '    ' * indent
    if isinstance(value, dict):
        items = ',\n'.join(f"{pad}    {php(k)} => {php(v, indent + 1)}" for k, v in value.items())
        return '[\n' + items + f'\n{pad}]'
    if isinstance(value, list):
        return '[' + ', '.join(php(v, indent) for v in value) + ']'
    if isinstance(value, str):
        return "'" + value + "'"
    return str(value)


data = {}

# ---------------------------------------------------------------- title screen ($12xx)
v = Vdp(0x13E1)
v.load_art(0x26000, 0x0000)
v.load_art(0x28B0A, 0x2000)
v.screen(0x16000, 0x012E, 0)
v.print(0x1362)
v.render().save(f'{OUT}/title.png')
v.print(0x1352)
v.render().save(f'{OUT}/title-press.png')
sheet = Image.new('RGBA', (96, 48), (0, 0, 0, 0))
sheet.paste(v.layout(0x13BD), (0, 0))
sheet.paste(v.layout(0x13CF), (48, 0))
sheet.save(f'{OUT}/title-sonic.png')
# wagging finger: 22 alternating frames of 8 (frame 0 = $13BD), then frame 0 held; sprite layout at (128, 24)
data['title'] = {'x': 128, 'y': 24, 'frame_ticks': 8, 'wags': 22, 'press_period': 100, 'press_visible': 64}

# ---------------------------------------------------------------- map screens
ZONE_TITLE = [word(0x1209 + 2 * lvl) for lvl in range(18)]
maps = {
    1: dict(art=0x30000, sprites=0x2926B, bg=(0x1627E, 0x178), fg=(0x163F6, 0x145), pal=0x0F0E),
    2: dict(art=0x31801, sprites=0x29942, bg=(0x1653B, 0x170), fg=(0x166AB, 0x153), pal=0x0F2E),
}
data['maps'] = {}
for m, cfg in maps.items():
    levels = range(0, 9) if m == 1 else range(9, 18)
    layouts = {}
    sheet_frames = []
    v = Vdp(cfg['pal'])
    v.load_art(cfg['art'], 0)
    v.load_art(cfg['sprites'], 0x2000)
    v.load_art(0x2F92E, 0x3000)
    v.digits().save(f'{OUT}/map{m}-digits.png')
    for lvl in levels:
        v = Vdp(cfg['pal'])
        v.load_art(cfg['art'], 0)
        v.load_art(cfg['sprites'], 0x2000)
        v.load_art(0x2F92E, 0x3000)
        v.screen(*cfg['bg'], 0x10)
        v.screen(*cfg['fg'], 0x00)
        v.print(ZONE_TITLE[lvl], 0x10)
        zone = lvl // 3 + 1
        v.render().save(f'{OUT}/map-zone{zone}.png')
        v.render(priority_only=True).save(f'{OUT}/map-zone{zone}-fg.png')
        # Sonic's position / animation on the map for this act
        ptr = word(0x0F4E + 3 * lvl)
        frames = []
        seen = set()
        a = ptr
        while a not in seen:
            seen.add(a)
            lay, x, y, dur = word(a), ROM[a + 2], ROM[a + 3], ROM[a + 4]
            if dur == 0:
                loop_to = lay
                break
            if lay not in layouts:
                layouts[lay] = len(layouts)
                sheet_frames.append(v.layout(lay))
            frames.append([layouts[lay], x, y, dur])
            a += 5
        data['maps'][lvl + 1] = {'screen': m, 'zone': zone, 'frames': frames}
    sheet = Image.new('RGBA', (48 * len(sheet_frames), 48), (0, 0, 0, 0))
    for k, f in enumerate(sheet_frames):
        sheet.paste(f, (48 * k, 0))
    sheet.save(f'{OUT}/map{m}-sonic.png')
data['map_lives'] = {'x': 40, 'y': 167}

# ---------------------------------------------------------------- act complete ($155E)
ACT_TILES = list(ROM[0x1B51:0x1B69])
ACT_OFFSET = list(ROM[0x1B69:0x1B69 + 36])
for name, (addr, length) in {'actcomplete': (0x1612E, 0xBB), 'actcomplete-bonus': (0x161E9, 0x95)}.items():
    v = Vdp(0x1B8D)
    v.load_art(0x2751F, 0)
    v.load_art(0x2F92E, 0x3000)
    v.screen(addr, length, 0)
    if name == 'actcomplete':
        base = [list(v.names) for _ in range(1)][0]
        for act in range(3):
            v.names = list(base)
            for r in range(4):
                v.print_bytes(0x15, 5 + r, ACT_TILES[act * 8 + r * 2:act * 8 + r * 2 + 2])
            v.render().save(f'{OUT}/actcomplete-act{act + 1}.png')
        v.digits().save(f'{OUT}/actcomplete-digits.png')
    else:
        v.render().save(f'{OUT}/{name}.png')
# chaos emerald icon (tiles $AD $AE / $BD $BE), printed from column 16 - count/2, rows 20-21, 2 columns apart
v = Vdp(0x1B8D)
v.load_art(0x2751F, 0)
v.names = [0] * (32 * 28)
v.print_bytes(0, 0, [0xAD, 0xAE])
v.print_bytes(0, 1, [0xBD, 0xBE])
v.render().crop((0, 0, 16, 16)).save(f'{OUT}/actcomplete-emerald.png')

# ---------------------------------------------------------------- game over / continue (_1401)
# act complete art, screen $167FE, palette _14fc; continue: "CONTINUE" (_14e6, _14f1 on rows 12-13),
# one 2x2 icon per continue (_14de: tiles $80 $81 / $90 $91, rows 15-16, centred on column $0B by _16d9)
# and the countdown digit as a sprite at ($8C, $5E)
v = Vdp(0x14FC)
v.load_art(0x2751F, 0)
v.load_art(0x2F92E, 0x3000)
v.screen(0x167FE, 0x32, 0)
v.render().save(f'{OUT}/gameover.png')
v.print(0x14E6)
v.print(0x14F1)
v.render().save(f'{OUT}/continue.png')
v.digits().save(f'{OUT}/continue-digits.png')
v.names = [0] * (32 * 28)
v.print_bytes(0, 0, [0x80, 0x81])
v.print_bytes(0, 1, [0x90, 0x91])
v.render().crop((0, 0, 16, 16)).save(f'{OUT}/continue-icon.png')
data['continue'] = {'icon_row': 15, 'icon_col': 0x0B, 'digit': [0x8C, 0x5E]}

thresholds = [(ROM[0x153E + 2 * k], ROM[0x153F + 2 * k]) for k in range(8)]
bonus = []
for k in range(8):
    p = word(0x154E + 2 * k)
    bonus.append(int(ROM[p:p + 4].hex()))
bcd = lambda b: (b >> 4) * 10 + (b & 15)
data['time_bonus'] = [[bcd(mn) * 60 + bcd(sc), bonus[k]] for k, (mn, sc) in enumerate(thresholds)]
data['act_act'] = ACT_OFFSET[:28]
data['act_layout'] = {'score': [136, 80], 'time_bonus': [136, 104], 'rings': [152, 128], 'ring_value': [192, 128],
                      'lives': [56, 159], 'bonus_rings': [152, 104], 'emerald_row': 20, 'emerald_col': 16}

open(f'{OUT}/screens.data.php', 'w').write('<?php\n// Generated from the Sonic 1 SMS ROM by screens.py\nreturn ' + php(data) + ';\n')
print('time bonus', data['time_bonus'])
for k, val in data['maps'].items():
    print(k, val)
