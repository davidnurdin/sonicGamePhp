<?php

namespace SonicGame\Entities\Objects;

/**
 * Labyrinth water line (object $40, doObjectCode_meta_water). Every 60Hz frame the line moves by a signed
 * 1/256 px step from _8e36; the step index advances every 16 frames over 32 entries, so the surface
 * slowly bobs up and down by ~26px. Below the line ($D2DC) the whole palette is swapped for
 * S1_UnderwaterPalette and Sonic is underwater once his top is below it.
 */
class Water
{
    private const STEPS = [
        -0x02, -0x04, -0x08, -0x10, -0x18, -0x28, -0x38, -0x38, -0x38, -0x38, -0x28, -0x18, -0x10, -0x08, -0x04, -0x02,
        0x02, 0x04, 0x08, 0x10, 0x18, 0x28, 0x38, 0x38, 0x38, 0x38, 0x28, 0x18, 0x10, 0x08, 0x04, 0x02,
    ];
    private const FRAME = 1 / 60;

    private float $line;
    private int $step = 0;
    private int $frames = 0;
    private float $clock = 0;

    public function __construct(float $y)
    {
        $this->line = $y;
    }

    public function update(float $deltaTime): void
    {
        $this->clock += $deltaTime;
        while ($this->clock >= self::FRAME) {
            $this->clock -= self::FRAME;
            $this->line += self::STEPS[$this->step] / 256;
            if (++$this->frames % 16 === 0) {
                $this->step = ($this->step + 1) % 32;
            }
        }
    }

    public function getLine(): float
    {
        return $this->line;
    }

    /** Frame counter, used to scroll the surface sprites. */
    public function getFrames(): int
    {
        return $this->frames;
    }
}
