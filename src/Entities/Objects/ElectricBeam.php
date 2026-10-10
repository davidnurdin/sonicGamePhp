<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Sky Base boss electric beam (object $46, doObjectCode_boss_electricBeam): a lightning column between two
 * emitters (Y $11A and $1D2) at X = $390 + $11, only during the boss fight (unknown0 bit 4).
 * Until 6 hits on the glass ($D2EC): it slides to $410 switched off, then sweeps back while on (SFX $13 every
 * 16 frames). From 6 hits it follows Sonic and is on 64 frames out of 128 (never at exactly 6 hits).
 */
class ElectricBeam extends Mechanism
{
    private const BASE_X = 0x390;
    private const TOP = 0x11A;
    private const BOTTOM = 0x1D2;

    private int $offset = 0;
    private bool $firing = false;
    private bool $fight = false;
    private int $hits = 0;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x46, 'Electric beam', $tileX * 32, $tileY * 32, 16, 16);
        $this->tilesetName = RomSprites::SHEET;
    }

    public function setBoss(bool $fight, int $hits): void
    {
        $this->fight = $fight;
        $this->hits = $hits;
    }

    protected function tick(array $player): void
    {
        if (!$this->fight) {
            $this->firing = false;
            return;
        }
        if ($this->hits < 6) {
            if (!$this->firing) {
                if ($this->offset < 0x80) {
                    $this->offset++;
                } elseif (($this->frames & 1) === 0) {
                    $this->firing = true;
                }
            } else {
                if (($this->frames & 0x0F) === 0) $this->sounds[] = 0x13;
                if (--$this->offset <= 0) {
                    $this->offset = 0;
                    $this->firing = false;
                }
            }
            return;
        }
        if (self::sonicX($player) < $this->x) {
            $this->offset = max(0, $this->offset - 1);
        } else {
            $this->offset = min(0x80, $this->offset + 1);
        }
        $this->firing = ($this->frames & 0x40) === 0 && $this->hits !== 6;
        if ($this->firing && ($this->frames & 0x1F) === 0) $this->sounds[] = 0x13;
    }

    /** Sonic is hit when his 24px object is within -14..+12 px of the column. */
    public function getHurtBox(): ?array
    {
        if (!$this->firing) return null;
        $x = self::BASE_X + $this->offset;
        return self::box($x - 14 + 6, self::TOP, 26 - 12, self::BOTTOM + 16 - self::TOP);
    }

    public function render(float $cameraX, float $cameraY): void
    {
        if (!$this->fight) return;
        $x = self::BASE_X + $this->offset;
        foreach ([self::TOP, self::BOTTOM] as $y) {
            $this->blit(RomSprites::BEAM_EMITTER, $x, $y + 1, $cameraX, $cameraY);
        }
        if (!$this->firing) return;
        // _bcc7: 10 segments from Y $130, alternating, shifted every other frame
        $start = ($this->frames >> 1) & 1;
        for ($i = 0; $i < 10; $i++) {
            $this->blit(RomSprites::BEAM[($start + $i) & 1], $x, 0x130 + 16 * $i + 1, $cameraX, $cameraY);
        }
    }
}
