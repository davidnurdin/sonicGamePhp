#!/usr/bin/env python3
"""End of the game from the Sonic 1 SMS ROM (_LABEL_258B_133 / _LABEL_1CED_131):
island screens ($16830 with the dark cloud, $169A9 cleared), the emerald burst (table $241D, 6+ emeralds),
the credits screen ($16C61) with its text script (S1_Credits_Text $2905) and animated sprites ($2848...).
Writes assets/screens/ending-*.png, credits-*.png and assets/screens/ending.data.php."""
import sys
sys.path.insert(0, __file__.rsplit('/', 1)[0])
from PIL import Image

src = open(__file__.rsplit('/', 1)[0] + '/screens.py').read()
ns = {}
exec(compile(src[:src.index('data = {}')], 'screens', 'exec'), ns)
Vdp, ROM, OUT, php, word, tile_pixels, sms_rgb = ns['Vdp'], ns['ROM'], ns['OUT'], ns['php'], ns['word'], ns['tile_pixels'], ns['sms_rgb']

data = {}

# ---------------------------------------------------------------- island screens (map screen 1 art, palette _2828)
for name, (addr, length) in {'ending-island': (0x16830, 0x179), 'ending-island-clear': (0x169A9, 0x145)}.items():
    v = Vdp(0x2828)
    v.load_art(0x30000, 0)
    v.screen(addr, length, 0)
    v.render().save(f'{OUT}/{name}.png')

# final tally (_172f, _155e with level 19): text boxes printed over the island screen, digits = HUD sprites
TALLY_TEXT = {'emerald': [0x197E], 'lives': [0x198E], 'special': [0x199E, 0x197A]}
for name, (addr, length) in {'island': (0x16830, 0x179), 'clear': (0x169A9, 0x145)}.items():
    for page, texts in TALLY_TEXT.items():
        v = Vdp(0x2828)
        v.load_art(0x30000, 0)
        v.screen(addr, length, 0)
        for box in (0x1907, 0x191C, 0x1931, 0x1946, 0x1953, 0x1960, 0x196D) + tuple(texts):
            v.print(box)
        v.render().save(f'{OUT}/ending-tally-{page}-{name}.png')
v = Vdp(0x2828)
v.load_art(0x2F92E, 0x3000)
v.digits().save(f'{OUT}/ending-digits.png')

# emerald: loadPowerUpIcon $15400, 4 raw tiles at sprite $5C (8x16 sprites $5C, $5E), sprite palette of _2828
pal = [sms_rgb(c) for c in ROM[0x2828 + 16:0x2828 + 32]]
img = Image.new('RGBA', (16, 16), (0, 0, 0, 0))
for t in range(4):
    pix = tile_pixels(ROM[0x15400:0x15480], t)
    for y in range(8):
        for x in range(8):
            if pix[y][x]:
                img.putpixel(((t // 2) * 8 + x, (t % 2) * 8 + y), pal[pix[y][x]] + (255,))
img.save(f'{OUT}/ending-emerald.png')
# 61 steps of 3 emeralds (x, y) around ($70, $60), each also drawn at (-x, -y); 2 frames a step
burst = []
a = 0x241D
for step in range(0x3D):
    burst.append([[ROM[a + 2 * k] - 256 if ROM[a + 2 * k] > 127 else ROM[a + 2 * k],
                   ROM[a + 2 * k + 1] - 256 if ROM[a + 2 * k + 1] > 127 else ROM[a + 2 * k + 1]] for k in range(3)])
    a += 6
data['burst'] = burst

# ---------------------------------------------------------------- credits
v = Vdp(0x2AD6)
v.load_art(0x31801, 0)
v.load_art(0x28B0A, 0x2000)
v.screen(0x16C61, 0x0189, 0)
data['names'] = v.names[:32 * 24]
# background tiles atlas (16 x 16 tiles, opaque)
atlas = Image.new('RGBA', (128, 128))
for n in range(256):
    t = v.tile(n)
    for y in range(8):
        for x in range(8):
            atlas.putpixel(((n % 16) * 8 + x, (n // 16) * 8 + y), v.pal[t[y][x]] + (255,))
atlas.save(f'{OUT}/credits-tiles.png')

# animated sprites: records (x, y, layout) used by the 4 animations of $D322
records = {}
anims = []
for start in (0x2848, 0x2857, 0x2869, 0x2872):
    steps = []
    a = start
    while ROM[a] != 0xFF:
        dur, ptr = ROM[a], word(a + 1)
        if ptr not in records:
            records[ptr] = len(records)
        steps.append([dur, records[ptr]])
        a += 3
    anims.append(steps)
sheet = Image.new('RGBA', (48 * len(records), 48), (0, 0, 0, 0))
frames = []
for ptr, k in sorted(records.items(), key=lambda kv: kv[1]):
    x, y = ROM[ptr], ROM[ptr + 1]
    lay = Image.new('RGBA', (48, 48), (0, 0, 0, 0))
    # processSpriteLayout from ptr + 2: rows of 6, a row starting with $FF ends it
    for row in range(3):
        base = ptr + 2 + row * 6
        if ROM[base] == 0xFF:
            break
        for col in range(6):
            n = ROM[base + col]
            if n == 0xFF:
                break
            if n < 0xFE:
                v.sprite(lay, n, col * 8, row * 16)
    sheet.paste(lay, (48 * k, 0))
    frames.append([x, y])
sheet.save(f'{OUT}/credits-sprites.png')
data['sprite_frames'] = frames
data['anims'] = anims

# text script: ['p', col, row, [tiles]] print (8 frames a character), ['w', frames], ['s', count] scroll up
# _2795: the text starts with a position (col, row); $FE = new position
script = [['p', ROM[0x2905], ROM[0x2906], []]]
a = 0x2907
while ROM[a] != 0xFF:
    b = ROM[a]
    if b == 0xFE:
        script.append(['p', ROM[a + 1], ROM[a + 2], []])
        a += 3
    elif b == 0xFD:
        script.append(['w', word(a + 1)])
        a += 3
    elif b == 0xFC:
        script.append(['s', ROM[a + 1]])
        a += 2
    else:
        if not script or script[-1][0] != 'p':
            raise SystemExit(f'text without position at {a:X}')
        script[-1][3].append(b)
        a += 1
data['script'] = script

open(f'{OUT}/ending.data.php', 'w').write('<?php\n// Generated from the Sonic 1 SMS ROM by tools/ending.py\nreturn ' + php(data) + ';\n')
print(len(script), 'script entries,', len(records), 'sprite frames, anims', anims)

# fades (the SMS palette fades go in 3 steps of the colour components): row 0 white, row 1 black, alpha 1/3, 2/3, 1
fade = Image.new('RGBA', (24, 16), (0, 0, 0, 0))
for row, colour in enumerate([(255, 255, 255), (0, 0, 0)]):
    for k in range(3):
        for y in range(8):
            for x in range(8):
                fade.putpixel((k * 8 + x, row * 8 + y), colour + (round(255 * (k + 1) / 3),))
fade.save(f'{OUT}/fade.png')
