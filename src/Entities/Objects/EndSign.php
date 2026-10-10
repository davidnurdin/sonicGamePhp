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

    private const SPIN_TIME = 2.5;

    private string $signState = 'waiting';
    private float $timer = 0;
    private array $finalFace = self::FACE_EGGMAN;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x07, 'End sign', 0, 0, 32, 40);

        // _6157: "?" while waiting and spinning (?, side, edge, side), then the final face ($6174...)
        $spin = [self::FACE_QUESTION, self::TURN_A, self::EDGE, self::TURN_B];
        $this->animations = [
            'idle' => ['flags' => [], 'coords' => self::frames([self::FACE_QUESTION])],
            'spin' => ['flags' => [], 'coords' => self::frames($spin)],
            'done' => ['flags' => [], 'coords' => self::frames([self::FACE_SONIC])],
        ];
        $this->setAnimation('idle');
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
    }

    /** Returns true on the tick the sign is first passed. */
    public function checkPassed(float $playerCenterX): bool
    {
        if ($this->signState !== 'waiting' || $playerCenterX < $this->x + $this->width / 2) {
            return false;
        }
        $this->signState = 'spinning';
        $this->timer = 0;
        $this->frameDuration = 0.04;
        $this->setAnimation('spin');
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

    public function update(float $deltaTime): void
    {
        if (!$this->active) return;
        if ($this->signState === 'spinning') {
            $this->timer += $deltaTime;
            $this->frameDuration = 0.04 + 0.08 * ($this->timer / self::SPIN_TIME);
            if ($this->timer >= self::SPIN_TIME) {
                $this->signState = 'done';
                $this->setAnimation('done');
            }
        }
        $this->updateSprite($deltaTime);
    }
}
