<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Renderer\Sdl;

/**
 * Animal capsule (object $25, doObjectCode_boss_capsule): 28x64, Y + 16 on load. Its top is rounded (_750e);
 * standing in the middle pushes the button down (_7540) and opens it: 8 explosions 20 frames apart, then the
 * top part is gone (no sprite, broken base _757c) and a freed animal comes out every 16 frames (12 in all)
 * while the act-complete countdown ($D289 = $A0 frames) runs.
 */
class Capsule extends GameObject
{
    private const CLOSED = [[403, 97, 32, 64], [436, 97, 32, 64]];
    private const PRESSED = [[469, 100, 32, 61]];
    private const BROKEN = [[468, 162, 33, 24]];
    // _750e: height of the top under Sonic's centre, 3 = on the button
    private const PROFILE = [0x15, 0x12, 0x11, 0x10, 0x10, 0x0F, 0x0E, 0x0D, 3, 3, 3, 3, 3, 3, 3, 3,
        3, 3, 3, 3, 3, 3, 3, 3, 0x0D, 0x0E, 0x0F, 0x10, 0x10, 0x11, 0x12, 0x15];
    private const FRAME = 1 / 60;

    private bool $opened = false;
    private int $frames = 0;
    private float $clock = 0;
    private int $explosions = 0;
    private int $animals = 0;
    private bool $finished = false;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x25, 'Capsule', $tileX * 32, $tileY * 32 + 16, 0x1C, 0x40);
        $this->tilesetName = 'eggman';
        $this->frameDuration = 16 / 60;
        $this->animations = [
            'closed' => ['flags' => [], 'coords' => self::frames(self::CLOSED)],
            'pressed' => ['flags' => [], 'coords' => self::frames(self::PRESSED)],
            'broken' => ['flags' => [], 'coords' => self::frames(self::BROKEN)],
        ];
        $this->setAnimation('closed');
    }

    public function isOpened(): bool { return $this->opened; }
    public function isFinished(): bool { return $this->finished; }

    /** Top of the capsule, where Sonic can stand (the button). */
    public function getTop(): float
    {
        return $this->y + ($this->isBroken() ? self::PROFILE[0] + 24 : 3);
    }

    /** Height of the rounded top under a centre X, null outside of it. */
    public function getTopAt(float $centerX): ?float
    {
        $rel = (int)floor($centerX - $this->x);
        if ($rel < 0 || $rel > 31 || $this->isBroken()) return null;
        return $this->y + self::PROFILE[$rel];
    }

    /** Sonic stands on the button (the middle of the top): drawn pushed down. */
    public function isButtonAt(float $centerX): bool
    {
        $rel = (int)floor($centerX - $this->x);
        return $rel >= 0 && $rel <= 31 && self::PROFILE[$rel] === 3;
    }

    public function press(): void
    {
        if (!$this->isBroken()) $this->setAnimation('pressed');
    }

    public function release(): void
    {
        $this->setAnimation('closed');
    }

    private function isBroken(): bool
    {
        return $this->opened && $this->explosions >= 8;
    }

    public function open(): void
    {
        if ($this->opened) return;
        $this->opened = true;
        $this->frames = 0;
    }

    /** Returns events: ['explosion', x, y] / ['part', BossPart] / ['complete']. */
    public function think(float $deltaTime): array
    {
        $events = [];
        $this->updateSprite($deltaTime);
        if (!$this->opened || $this->finished) return $events;

        $this->clock += $deltaTime;
        while ($this->clock >= self::FRAME) {
            $this->clock -= self::FRAME;
            $this->frames++;
            if ($this->explosions < 8) {
                if ($this->frames % 20 === 0) {
                    $this->explosions++;
                    $events[] = ['explosion', $this->x + mt_rand(0, 28), $this->y + mt_rand(0, 48)];
                    if ($this->explosions === 8) {
                        $events[] = ['music', 'actcomplete'];
                        $this->setAnimation('broken');
                        $this->frames = 0;
                    }
                }
                continue;
            }
            if ($this->animals < 12 && $this->frames % 16 === 0) {
                $this->animals++;
                $bird = mt_rand(0, 1) === 1;
                $animal = (new BossPart($this->sdl, $this->x + 8 + 6, $this->y + 0x1A, $bird ? BossPart::BIRD : BossPart::RABBIT, 0.15))
                    ->withVelocity(mt_rand(-90, 90), -mt_rand(120, 240));
                $animal->harmful = false;
                $animal->gravity = 600;
                $animal->hopper = !$bird;
                $animal->life = 4;
                if ($bird) {
                    $animal->gravity = -120;
                }
                $events[] = ['part', $animal];
            }
            if ($this->frames >= 0xA0 + 60) {
                $this->finished = true;
                $events[] = ['complete'];
            }
        }
        return $events;
    }
}
