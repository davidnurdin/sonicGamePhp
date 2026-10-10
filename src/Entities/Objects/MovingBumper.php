<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Moving bumper (object $21, doObjectCode_platform_bumber, special stages): a 32x16 bumper (_9b6e) going 1 px a
 * frame left for $60 frames, then right for $60. Touching its line (box $0602, $1C x 6) reverses Sonic's
 * vertical speed (sfx 7), then it rests 8 frames.
 */
class MovingBumper extends Mechanism
{
    private int $counter = 0;
    private int $rest = 0;
    private bool $bounce = false;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x21, 'Bumper sliding', $tileX * 32, $tileY * 32, 0x1C, 6);
        $this->tilesetName = RomSprites::SHEET;
    }

    protected function tick(array $player): void
    {
        $this->x += $this->counter < 0x60 ? -1 : 1;
        $this->counter = ($this->counter + 1) % 0xC0;
        if ($this->rest > 0) {
            $this->rest--;
            return;
        }
        $sx = self::sonicX($player);
        $sy = self::sonicY($player);
        if ($sx <= $this->x + 0x1C && $this->x + 2 <= $sx + 24 && $sy <= $this->y + 6 && $this->y + 6 <= $sy + 32) {
            $this->bounce = true;
            $this->rest = 8;
            $this->sounds[] = 0x07;
        }
    }

    /** Sonic hit it this frame: his vertical speed is to be reversed. */
    public function takeBounce(): bool
    {
        $bounce = $this->bounce;
        $this->bounce = false;
        return $bounce;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        $this->blit(RomSprites::BUMPER, $this->x, $this->y + 1, $cameraX, $cameraY);
    }
}
