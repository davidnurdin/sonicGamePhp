#!/usr/bin/env python3
"""Render the Sonic 1 SMS music and sound effects from the ROM.

The original sound driver (bank 3, mapped at $4000: update=$4000, playMusic=$4012, playSFX=$4015)
is executed on a Z80 emulator; every write to the PSG port ($7F) is captured per 60Hz frame and fed
to an SN76489 emulator. Loops are found by detecting when the driver RAM + PSG state repeats.
"""
import os
import subprocess
import sys
import wave

import numpy as np
import z80

ROM = open('/home/david/poc/sonic/sonic_level/sonic.sms', 'rb').read()
OUT = sys.argv[1] if len(sys.argv) > 1 else '/home/david/poc/sonicGamePhp/assets/mixer'
RATE = 44100
OVERSAMPLE = 4
CLOCK = 3579545
FPS = 60
GAIN = 1.6   # same gain for every file so music and effects keep the original balance
UPDATE, PLAY_MUSIC, PLAY_SFX, STOP = 0x4000, 0x4012, 0x4015, 0x4006


class Driver:
    def __init__(self):
        self.m = z80.Z80Machine()
        mem = bytearray(0x10000)
        mem[0x4000:0x8000] = ROM[0xC000:0x10000]   # bank 3 in slot 1
        mem[0x0000] = 0x76                         # HALT: return address of our calls
        self.m.set_memory_block(0, bytes(mem))
        self.writes = []
        self.m.set_output_callback(self._out)

    def _out(self, port, value):
        if port & 0xFF in (0x7E, 0x7F):
            self.writes.append(value)
        return None

    def call(self, addr, a=0):
        m = self.m
        m.sp = 0xDFF0
        m.memory[0xDFF0] = 0
        m.memory[0xDFF1] = 0
        m.a = a
        m.pc = addr
        m.halted = False
        m.iff1 = m.iff2 = 0
        for _ in range(2000):
            m.ticks_to_stop = 2000
            m.run()
            if m.pc in (0, 1) or m.halted:
                return
        raise RuntimeError(f'call {addr:04X} did not return (pc={m.pc:04X})')

    def active_tracks(self, tracks=range(4)):
        # TRACK struct is $2D bytes from $DC26, flags at +$28 (bit 1 = playing)
        return [t for t in tracks if self.m.memory[0xDC26 + 0x2D * t + 0x28] & 2]

    def state(self):
        return bytes(self.m.memory[0xDC00:0xDE00])


class PSG:
    """SN76489 as in the Master System: 3 tone channels + noise, 16-bit LFSR tapped on bits 0 and 3."""
    VOL = [10 ** (-2 * i / 20) for i in range(15)] + [0.0]

    def __init__(self):
        self.period = [0x3FF, 0x3FF, 0x3FF, 0]
        self.vol = [15, 15, 15, 15]
        self.latch = 0
        self.phase = [0.0] * 3
        self.out = [1, 1, 1]
        self.noise_ctrl = 0
        self.lfsr = 0x8000
        self.noise_counter = 0.0
        self.noise_out = 0

    def write(self, v):
        if v & 0x80:
            self.latch = (v >> 4) & 7
            ch, is_vol = self.latch >> 1, self.latch & 1
            if is_vol:
                self.vol[ch] = v & 0x0F
            elif ch < 3:
                self.period[ch] = (self.period[ch] & 0x3F0) | (v & 0x0F)
            else:
                self.noise_ctrl = v & 7
                self.lfsr = 0x8000
        else:
            ch, is_vol = self.latch >> 1, self.latch & 1
            if is_vol:
                self.vol[ch] = v & 0x0F
            elif ch < 3:
                self.period[ch] = (self.period[ch] & 0x0F) | ((v & 0x3F) << 4)
            else:
                self.noise_ctrl = v & 7
                self.lfsr = 0x8000

    def render(self, n):
        step = CLOCK / 16 / (RATE * OVERSAMPLE)   # chip "ticks" per internal sample
        mix = np.zeros(n)
        for ch in range(3):
            p = self.period[ch]
            amp = self.VOL[self.vol[ch]]
            if p <= 1:
                mix += amp                # constant level (used for samples / silence)
                continue
            # square wave toggling every p ticks
            t = self.phase[ch] + step * np.arange(1, n + 1)
            toggles = np.floor(t / p).astype(np.int64)
            level = np.where((toggles + (0 if self.out[ch] > 0 else 1)) % 2 == 0, 1.0, -1.0)
            mix += level * amp
            total = self.phase[ch] + step * n
            if int(total // p) % 2:
                self.out[ch] = -self.out[ch]
            self.phase[ch] = total % p
        # noise
        amp = self.VOL[self.vol[3]]
        rate = self.noise_ctrl & 3
        nper = [0x10, 0x20, 0x40, self.period[2] or 1][rate] * 2   # LFSR shifts every 2 toggles
        white = self.noise_ctrl & 4
        out = np.empty(n)
        c, lfsr = self.noise_counter, self.lfsr
        for i in range(n):
            c += step
            while c >= nper:
                c -= nper
                bit = ((lfsr & 1) ^ ((lfsr >> 3) & 1)) if white else (lfsr & 1)
                lfsr = (lfsr >> 1) | (bit << 15)
            out[i] = 1.0 if lfsr & 1 else -1.0
        self.noise_counter, self.lfsr = c, lfsr
        mix += out * amp
        return mix / 4


def capture(kind, index, max_frames=60 * 240, find_loop=True):
    """Run the driver, return (per-frame PSG writes, loop_start_frame or None, end_frame)."""
    d = Driver()
    d.call(STOP)
    d.writes = []
    d.call(PLAY_MUSIC if kind == 'music' else PLAY_SFX, index)
    frames = []
    seen = {}
    loop = None
    quiet, last_ptrs = 0, None
    for f in range(max_frames):
        d.writes = []
        d.call(UPDATE)
        frames.append(d.writes)
        st = d.state()
        if not d.active_tracks(range(4) if kind == 'music' else [4]):
            break
        # jingles end on a held rest: track pointers frozen and every channel muted
        ptrs = bytes(d.m.memory[0xDC1C:0xDC26])
        silent = all(v in (0x9F, 0xBF, 0xDF, 0xFF) for v in d.writes if v & 0x90 == 0x90)
        quiet = quiet + 1 if (ptrs == last_ptrs and silent) else 0
        last_ptrs = ptrs
        if quiet >= 90:
            del frames[-90:]
            break
        if find_loop:
            if st in seen:
                loop = seen[st]
                frames.pop()
                break
            seen[st] = f
    return frames, loop


def synth(frames, warm=None):
    psg = PSG()
    for f in warm or []:
        for v in f:
            psg.write(v)
    spf = RATE / FPS
    chunks, acc = [], 0.0
    for f in frames:
        for v in f:
            psg.write(v)
        acc += spf
        n = int(acc)
        acc -= n
        hi = psg.render(n * OVERSAMPLE)
        chunks.append(hi.reshape(n, OVERSAMPLE).mean(axis=1))   # box-filter decimation (anti-aliasing)
    out = np.concatenate(chunks) if chunks else np.zeros(1)
    # DC blocker (the PSG output has a DC component when channels hold a constant level)
    y = np.empty_like(out)
    prev_x = prev_y = 0.0
    for i, v in enumerate(out):
        prev_y = v - prev_x + 0.9995 * prev_y
        prev_x = v
        y[i] = prev_y
    return y


def save(samples, path):
    data = np.clip(samples * GAIN, -1, 1)
    pcm = (data * 32767).astype('<i2')
    wav = path.rsplit('.', 1)[0] + '.wav'
    with wave.open(wav, 'wb') as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(RATE)
        w.writeframes(pcm.tobytes())
    if path.endswith('.ogg'):
        subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', '-i', wav, '-c:a', 'libvorbis', '-q:a', '5', path], check=True)
        os.remove(wav)


MUSIC = {0x00: 'greenhill', 0x01: 'bridge', 0x02: 'jungle', 0x03: 'labyrinth', 0x04: 'scrapbrain', 0x05: 'skybase',
         0x06: 'title', 0x07: 'map', 0x08: 'invincibility', 0x09: 'actcomplete', 0x0A: 'death', 0x0B: 'boss',
         0x0E: 'ending', 0x10: 'specialstage', 0x13: 'allemeralds', 0x14: 'emerald'}

if __name__ == '__main__':
    os.makedirs(f'{OUT}/music', exist_ok=True)
    os.makedirs(f'{OUT}/sfx', exist_ok=True)
    only = sys.argv[2] if len(sys.argv) > 2 else None
    for idx, name in MUSIC.items():
        if only and only != name:
            continue
        frames, loop = capture('music', idx)
        frames += [[]] * 20     # let the last notes release
        if loop is not None and loop > 0:
            save(synth(frames[:loop]), f'{OUT}/music/{name}-intro.ogg')
            save(synth(frames[loop:], warm=frames[:loop]), f'{OUT}/music/{name}.ogg')
        else:
            save(synth(frames), f'{OUT}/music/{name}.ogg')
        print(f'music {idx:02X} {name:14s} frames={len(frames):5d} loop={loop}')
    if not only:
        for idx in range(0x23):
            frames, _ = capture('sfx', idx, max_frames=60 * 6, find_loop=False)
            while frames and not frames[-1]:
                frames.pop()
            save(synth(frames + [[]] * 6), f'{OUT}/sfx/sfx{idx:02x}.wav')
            print(f'sfx {idx:02X} frames={len(frames)}')
