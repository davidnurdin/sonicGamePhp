<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Level\Level;
use SonicGame\Renderer\Sdl;

class EndSign extends GameObject
{
    public const FACE_EGGMAN = [240, 104, 32, 40];
    public const FACE_SONIC = [280, 104, 32, 40];
    public const FACE_QUESTION = [320, 104, 32, 40];
    public const FACE_RING = [360, 104, 32, 40];
    public const FACE_EXCLAMATION = [400, 104, 32, 40];
    private const TURN_A = [444, 104, 24, 40];
    private const EDGE = [485, 104, 6, 40];
    private const TURN_B = [508, 104, 24, 40];

    /** The spin sequence _6157: 6 entries per face (?, side, edge, side), 24 in all. */
    private const SPIN = [self::FACE_QUESTION, self::TURN_A, self::EDGE, self::TURN_B];
    private const FRAME = 1 / 60;

    private string $signState = 'waiting';
    private array $finalFace = self::FACE_EGGMAN;
    private float $clock = 0;
    // doObjectCode_boss_endSign, in ROM units (1/256 px per frame): Y speed, spin speed ($14), spin index ($12 + $16)
    private int $signVy = 0;
    private int $spinSpeed = 0;
    private int $spinIndex = 0;
    private bool $onFloor = true;
    private ?float $floorY = null;
    private bool $sonicWasGrounded = true;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x07, 'End sign', 0, 0, 32, 40);
        $this->animations = ['done' => ['flags' => [], 'coords' => self::frames([self::FACE_SONIC])]];
        foreach (self::SPIN as $i => $face) {
            $this->animations['spin' . $i] = ['flags' => [], 'coords' => self::frames([$face])];
        }
        $this->setAnimation('spin0');
        $this->placeOnSpawn($tileX, $tileY, 48);
    }

    /** The original sign is a normal object with gravity: it rests on the floor below its spawn point. */
    public function setLevel(Level $level): void
    {
        parent::setLevel($level);
        $surface = $level->findSurfaceY((int)($this->x + $this->width / 2), (int)$this->y, 192);
        if ($surface !== null) {
            $this->y = $surface - $this->height;
        }
        $this->floorY = $this->y;
    }

    /**
     * Returns true on the tick the sign is first passed. The ROM starts the spin at
     * min(|Sonic X speed|, $300) * 2, and not while the sign is going up.
     */
    public function checkPassed(float $playerCenterX, float $playerVx): bool
    {
        if ($this->signState !== 'waiting' || $this->signVy < 0 || $playerCenterX < $this->x + $this->width / 2) {
            return false;
        }
        $this->signState = 'spinning';
        $this->spinSpeed = min((int)(abs($playerVx) / 60 * 256), 0x300) * 2;
        $this->spinIndex = 0;
        return true;
    }

    public function setFinalFace(array $face): void
    {
        $this->finalFace = $face;
        $this->animations['done']['coords'] = self::frames([$face]);
    }

    public function isPassed(): bool
    {
        return $this->signState !== 'waiting';
    }

    public function isFinished(): bool
    {
        return $this->signState === 'done';
    }

    /** Sonic's state for the next frames: he lands within $64 px of the sign -> the sign hops ($D414 bit 7). */
    public function watchSonic(float $sonicX, bool $grounded): void
    {
        if ($grounded && !$this->sonicWasGrounded && $this->onFloor && abs($sonicX - $this->x) < 0x64) {
            $this->signVy = -0x200;
        }
        $this->sonicWasGrounded = $grounded;
    }

    public function update(float $deltaTime): void
    {
        if (!$this->active) return;
        $this->clock += $deltaTime;
        while ($this->clock >= self::FRAME) {
            $this->clock -= self::FRAME;
            $this->tick();
        }
        $face = match ($this->signState) {
            'waiting' => 0,
            'done' => null,
            default => intdiv($this->spinIndex >> 8, 6) % 4,
        };
        $this->setAnimation($face === null ? 'done' : 'spin' . $face);
        $this->updateSprite($deltaTime);
    }

    private function tick(): void
    {
        $this->signVy += 0x1A;
        if ($this->signState === 'spinning') {
            // the spin goes on at its speed; a fast spin lifts the sign (Y speed - speed / 32, not beyond -4px)
            $this->spinIndex = ($this->spinIndex + $this->spinSpeed) % (24 << 8);
            if ($this->signVy > -0x400) {
                $this->signVy -= $this->spinSpeed >> 5;
                $this->spinSpeed -= 0x10;
                if ($this->spinSpeed < 0 || $this->y < 8) {
                    // stopped: still turning slowly (one entry a frame) until it is back on the floor
                    $this->signVy = 0;
                    $this->signState = 'falling';
                    $this->spinIndex = 0;
                }
            }
        } elseif ($this->signState === 'falling') {
            $this->spinIndex = ($this->spinIndex + 0x100) % (24 << 8);
        }
        $this->y += $this->signVy / 256;
        $this->onFloor = false;
        if ($this->floorY !== null && $this->y >= $this->floorY && $this->signVy >= 0) {
            $this->y = $this->floorY;
            $this->signVy = 0;
            $this->onFloor = true;
        }
        if ($this->signState === 'falling' && $this->onFloor) {
            $this->signState = 'done';
        }
    }
}
