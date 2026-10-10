#!/usr/bin/env python3
"""Add the camera limits of the level headers to assets/levels/levelN.meta.

Header bytes 5-12 are four 16-bit words (RAM_LEVEL_LEFT/RIGHT/TOP/BOTTOM, $D273-$D27A): the range of the
camera position (top-left of the 256x192 screen). The old LX/LW/LY/LH keys of the .meta files were read
as bytes, so they are kept as is and the words are stored as LEFT / RIGHT / TOP / BOTTOM.
Our level N is the ROM level index N-1 up to 19 (End Sequence = $12), then N ($13 has no header).
"""
import re

ROM = open('/home/david/poc/sonic/sonic_level/sonic.sms', 'rb').read()
POINTERS = 0x15580


def word(a):
    return ROM[a] | ROM[a + 1] << 8


for n in range(1, 36):
    index = n - 1 if n <= 19 else n
    header = POINTERS + word(POINTERS + 2 * index)
    left, right, top, bottom = (word(header + 5 + 2 * k) for k in range(4))
    path = f'/home/david/poc/sonicGamePhp/assets/levels/level{n}.meta'
    meta = open(path).read()
    meta = re.sub(r"\n  '(LEFT|RIGHT|TOP|BOTTOM)' => -?\d+,", '', meta)
    extra = ''.join(f"\n  '{k}' => {v}," for k, v in (('LEFT', left), ('RIGHT', right), ('TOP', top), ('BOTTOM', bottom)))
    meta = meta.rstrip()
    assert meta.endswith(')')
    meta = meta[:-1].rstrip() + extra + '\n)'
    open(path, 'w').write(meta)
    print(n, f'${index:02X}', left, right, top, bottom)
