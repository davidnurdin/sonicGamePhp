<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Flipper (object $4C, doObjectCode_platform_flipper, special stages): a pinball flipper pointing left, raised
 * while button 2 is held ($11: 0 at rest, 1-3 swinging, 4-5 up, one step a frame). Sonic coming down on it
 * (box of the state) is put on its surface (_9aaf: Y = flipper + table[(Sonic X + 12) & 31]); faster than
 * 3 px a frame he bounces back at half speed. At rest / up he stands and slides (X speed -4 / +$1A per frame);
 * hit while it swings up he is thrown by the tables _99fe (X) and _9a3e (Y).
 */
class Flipper extends Mechanism
{
    // Sonic's top relative to the flipper, by (Sonic X + 12) & 31: at rest (_999e), swinging (_99be), up (_99de)
    private const SURFACE = [
        [-1, -1, -2, -2, -2, -3, -3, -3, -4, -4, -4, -4, -5, -5, -5, -5, -6, -6, -6, -6, -6, -7, -7, -7, -7, -7, -7, -6, -6, -5, -4, -2],
        [-22, -22, -22, -10, -9, -8, -8, -8, -7, -7, -7, -6, -6, -6, -5, -5, -5, -5, -4, -4, -4, -4, -3, -3, -3, -3, -2, -2, -1, 0, 2, 4],
        [-22, -22, -22, -22, -22, -22, -22, -22, -22, -22, -22, -22, -18, -19, -20, -20, -20, -19, -18, -17, -16, -14, -13, -12, -11, -9, -8, -7, -6, -5, -3, -1],
    ];
    // launch while swinging, 1/256 px per frame
    private const LAUNCH_X = [-2048, -2048, -1792, -1536, -1280, -1024, -800, -640, -576, -512, -448, -384, -320, -256, -224, -192,
        -160, -128, -96, -64, -32, -24, -22, -20, -18, -16, -14, -12, -10, -8, -4, -2];
    private const LAUNCH_Y = [-1024, -1024, -1024, -1280, -1536, -1792, -2048, -2304, -2560, -2688, -2816, -2880, -2944, -3008, -3072, -3072,
        -3072, -3072, -3008, -2944, -2880, -2816, -2560, -2304, -1792, -1536, -1024, -896, -768, -576, -256, -256];
    // collision box offsets (TEMP6) of each state: x, y
    private const BOX = [[0x0C, 0x14], [0x0F, 0x08], [0x1A, 0x02]];

    private int $step = 0;
    private int $previous = 0;
    private bool $held = false;
    /** Sonic's X speed while he stands on it (px/frame): the slide adds up frame after frame. */
    private ?float $slide = null;
    /** What happens to Sonic this frame: [sonic top Y, vx px/frame or null (unchanged), vy px/frame, add vx] */
    public ?array $push = null;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x4C, 'Flipper', $tileX * 32, $tileY * 32, 0x1E, 0x16);
        $this->tilesetName = RomSprites::SHEET;
    }

    public function setButton(bool $held): void
    {
        $this->held = $held;
    }

    private function state(): int
    {
        return $this->step < 1 ? 0 : ($this->step < 4 ? 1 : 2);
    }

    /** @param array $player collision rect; ['vx', 'vy'] in px/frame */
    protected function tick(array $player): void
    {
        if ($this->held) {
            $this->previous = $this->step;
            if ($this->step < 5) $this->step++;
        } elseif ($this->step > 0) {
            $this->step--;
        }
        $state = $this->state();
        [$ox, $oy] = self::BOX[$state];
        $sx = self::sonicX($player);
        $sy = self::sonicY($player);
        if (!($sx <= $this->x + 0x1E && $this->x + $ox <= $sx + 24 && $sy <= $this->y + 0x16 && $this->y + $oy <= $sy + 32)) {
            $this->slide = null;
            return;
        }
        $vx = $player['vx'];
        $vy = $player['vy'];
        // _9aaf: only coming down
        if ($vy < 0) {
            $this->slide = null;
            return;
        }
        $index = ((int)floor($sx) + 12 - (int)$this->x) & 31;
        $top = $this->y + self::SURFACE[$state][$index];
        if ($vy >= 3 || $state === 1) {
            $this->slide = null;
        }
        if ($vy >= 3) {
            // too fast: bounces back at half speed
            $this->push = [$top, $vx, -$vy / 2];
            return;
        }
        if ($state === 1) {
            // thrown while it swings up
            if ($this->previous < $this->step) {
                $this->push = [$top, $vx + self::LAUNCH_X[$index] / 256, self::LAUNCH_Y[$index] / 256 - $vy];
            } else {
                $this->push = [$top, $vx, $vy];
            }
            return;
        }
        // stands on it and slides down (the air drag of the Player would eat these 1/256 px steps)
        $this->slide = ($this->slide ?? $vx) + ($state === 0 ? -4 : 0x1A) / 256;
        $this->push = [$top, $this->slide, 0];
    }

    public function render(float $cameraX, float $cameraY): void
    {
        $this->blit(RomSprites::FLIPPER[$this->state()], $this->x, $this->y + 1, $cameraX, $cameraY);
    }
}
