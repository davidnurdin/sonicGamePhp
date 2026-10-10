<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;
use SonicGame\SoundManager\SoundManager;

/**
 * Fireball head (object $3F, _8c16): X + 10, Y + 8. After 150 frames it spits a fireball (SFX $18) moving
 * 1px/frame, to the left when its block is $52 (head facing left), else to the right; the fireball
 * goes back into the head once it has left the screen. Hitbox 4x10 at (+2, +4).
 */
class FireballHead extends Mechanism
{
    private const LEFT = [208, 32, 8, 16];
    private const RIGHT = [224, 32, 8, 16];

    private float $startX;
    private float $startY;
    private int $delay = 0x96;
    private int $direction;

    public function __construct(Sdl $sdl, int $tileX, int $tileY, int $block)
    {
        parent::__construct($sdl, 0x3F, 'Fire ball thrower', $tileX * 32 + 10, $tileY * 32 + 8, 8, 16);
        $this->startX = $this->x;
        $this->startY = $this->y;
        $this->direction = $block === 0x52 ? -1 : 1;
    }

    protected function tick(array $player): void
    {
        if ($this->delay > 0) {
            if (--$this->delay === 0) {
                $this->sounds[] = SoundManager::SFX_FIREBALL;
            }
            return;
        }
        $this->x += $this->direction;
        // the original puts it back once it is off-screen (Sonic is about at the middle of the screen)
        if (abs($this->x - self::sonicX($player)) > 160) {
            $this->x = $this->startX;
            $this->y = $this->startY;
            $this->delay = 0x96;
            }
    }

    public function getHurtBox(): ?array
    {
        return $this->delay > 0 ? null : self::box($this->x + 2, $this->y + 4, 4, 10);
    }

    public function render(float $cameraX, float $cameraY): void
    {
        if ($this->delay > 0) return;
        $this->blit($this->direction < 0 ? self::LEFT : self::RIGHT, $this->x, $this->y, $cameraX, $cameraY);
    }
}
