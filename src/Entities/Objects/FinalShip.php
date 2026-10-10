<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Final animation (object $53, doObjectCode_anim_final): Robotnik's ship flies right at 1 px/frame, the camera
 * following it ($40 px from the left of the screen) while Sonic is hidden. After 255 even frames Sonic appears
 * 60 px right of and 64 px above the ship (sfx 6) and drops on it (box $1008 of _LABEL_3956_11): he bounces
 * back up (sfx 1), the ship is damaged (_bf33) and sinks 1/4 px a frame, an explosion every $18 frames;
 * after 10 of them the level ends.
 *
 * Events taken by the GameObjectManager: 'appear' [x, y] (Sonic's top-left), 'bounce', 'explosion' [x, y], 'done'.
 */
class FinalShip extends Mechanism
{
    private int $delay = 0xFF;
    private bool $hit = false;
    private int $timer = 0;
    private int $explosions = 0;
    private bool $sonicShown = false;
    private bool $following = true;
    private ?float $cameraX = null;
    private float $y0;
    /** @var array[] */
    public array $events = [];

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x53, 'Final animation', $tileX * 32, $tileY * 32, 40, 48);
        $this->tilesetName = BossSprites::SHEET;
        $this->y0 = $tileY * 32;
    }

    /** Camera X of the original screen while it follows the ship (set from the level camera at first). */
    public function getCameraX(float $current): ?float
    {
        if (!$this->following) return null;
        $this->cameraX ??= $current;
        return $this->cameraX;
    }

    public function isSonicShown(): bool
    {
        return $this->sonicShown;
    }

    protected function tick(array $player): void
    {
        if ($this->frames % 2 === 0 && $this->delay > 0 && --$this->delay === 0) {
            $this->sonicShown = true;
            $this->events[] = ['appear', $this->x + 0x3C, $this->y - 0x40];
            $this->sounds[] = 0x06;
        }
        $this->x += 1;
        if ($this->hit) {
            $this->y += 0.25;
        }
        if ($this->following && $this->cameraX !== null && $this->cameraX + 0x40 < $this->x) {
            $this->cameraX++;
        }
        if (!$this->hit && $this->sonicShown) {
            // _LABEL_3956_11 with $1008: X from ship + 8 to ship + width ($20), Y from ship + $10 to ship + height ($1C)
            $sx = self::sonicX($player);
            $sy = self::sonicY($player);
            if ($sx <= $this->x + 0x20 && $this->x + 8 <= $sx + 24 && $sy <= $this->y + 0x1C && $this->y + 0x10 <= $sy + 32) {
                $this->hit = true;
                $this->following = false;
                $this->timer = 1;
                $this->events[] = ['bounce'];
                $this->sounds[] = 0x01;
            }
        }
        if ($this->hit && --$this->timer === 0) {
            // _7a3a: an explosion somewhere in the 32x32 at the ship's position
            $this->events[] = ['explosion', $this->x + mt_rand(0, 31), $this->y + mt_rand(0, 31)];
            $this->timer = 0x18;
            if (++$this->explosions === 10) {
                $this->events[] = ['done'];
            }
        }
    }

    public function render(float $cameraX, float $cameraY): void
    {
        // _bf21 / _bf33 are the egg mobile layouts 0 / 4 of _7922 (boss art, S1_BossPalette)
        $this->tilesetName = BossSprites::SHEET;
        $this->blit(BossSprites::FRAMES[$this->hit ? 'gh_egg4' : 'gh_egg0'], $this->x, $this->y + 1, $cameraX, $cameraY);
        // _79fa: the exhaust flame behind the ship (going right: tiles 4 / 6 at X - 8, Y + $10), on even frames
        if ($this->frames % 2 === 0) {
            $this->blit(BossSprites::FRAMES['gh_flame' . (2 + intdiv($this->frames & 2, 2))], $this->x - 8, $this->y + 0x10 + 1, $cameraX, $cameraY);
        }
    }
}
