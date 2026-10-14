<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Renderer\Sdl;

/**
 * Platforms Sonic can stand on. Each kind follows its ROM routine, one step per original frame (60Hz):
 *  $0B doObjectCode_platform              sinks 16px (1/2 px/frame) while stood on, rises back (1/4 px/frame)
 *  $0C doObjectCode_platform_weight       falls 24 frames after being stood on (gravity 1/4 px/frame²)
 *  $0F doObjectCode_platform_leftRight    1px/frame to the right for 160 frames, then back
 *  $27/$28 doObjectCode_platform_fall*    Jungle logs going down the waterfall (1/2 px/frame), back on top every 768/792 frames
 *  $29 doObjectCode_platform_roll         Jungle floating log: bobs, rolls with Sonic at half his speed
 *  $38 doObjectCode_platform_flyingRight  Sky Base: follows the wave, flies right (1/2 px/frame) while stood on
 *  $3B doObjectCode_platform_flyingUpDown up and down, ±1/16 px/frame² up to 2px/frame, 160 frames period
 *  $45 doObjectCode_platform_float        Labyrinth: rises once stood on (see updateFloat)
 */
class Platform extends GameObject
{
    public const SPRITE_GREEN_HILL = [8, 8, 32, 16];
    public const SPRITE_LOG = [104, 32, 32, 16];
    public const SPRITE_SKY = [176, 56, 32, 16];

    private const FRAME = 1 / 60;

    protected float $startX;
    protected float $startY;
    protected string $kind;
    protected bool $falling = false;
    protected float $deltaX = 0;
    protected float $carryX = 0;
    protected float $deltaY = 0;
    // stood on since the last frame (set by touch())
    protected bool $touched = false;
    protected bool $stood = false;
    protected float $speedY = 0;
    protected int $counter = 0;
    protected int $direction = 1;
    protected float $clock = 0;
    protected int $frames = 0;
    // $29: Sonic's speed (px/s), the distance rolled, the X Sonic is held at
    protected float $playerSpeed = 0;
    protected float $rolled = 0;
    protected ?float $pinX = null;
    // $38: camera wave offset ($D2A1)
    protected float $wave = 0;

    public function __construct(Sdl $sdl, int $objectType, string $typeName, int $tileX, int $tileY, array $sprite, ?string $sheet = null)
    {
        parent::__construct($sdl, $objectType, $typeName, 0, 0, $sprite[2], $sprite[3]);
        $this->kind = match ($objectType) {
            0x0B => 'sink',
            0x0C => 'weight',
            0x0F => 'leftRight',
            0x27, 0x28 => 'fallLog',
            0x29 => 'roll',
            0x38 => 'flyRight',
            0x3B => 'upDown',
            0x45 => 'float',
            default => 'none',
        };
        if ($sheet !== null) {
            $this->tilesetName = $sheet;
        }
        $frames = $this->kind === 'roll' ? RomSprites::LOG_ROLL : [$sprite];
        foreach ($frames as $i => $frame) {
            $this->animations["f$i"] = ['flags' => [], 'coords' => self::frames([$frame])];
        }
        $this->setAnimation('f0');
        // The ROM layouts start with an empty row: the sprite is 16px under the object, +1 line on the SMS.
        $this->x = $tileX * 32;
        $this->y = $tileY * 32 + 17;
        if ($this->kind === 'float') {
            $this->y = $tileY * 32 + 15;   // Y - 1, sprite on the second row
        } elseif ($this->kind === 'roll') {
            $this->y -= 24;                // Y - 24 on the first frame
        }
        $this->startX = $this->x;
        $this->startY = $this->y;
    }

    public function update(float $deltaTime): void
    {
        if (!$this->active) return;
        $oldX = $this->x;
        $oldY = $this->y;
        $this->pinX = null;

        $this->clock += $deltaTime;
        while ($this->clock >= self::FRAME) {
            $this->clock -= self::FRAME;
            $this->stood = $this->touched;
            $this->touched = false;
            $this->tick();
            $this->frames++;
        }

        $this->deltaX = $this->x - $oldX;
        $this->carryX = $this->deltaX;
        $this->deltaY = $this->y - $oldY;
        if ($this->kind === 'roll' && $this->stood) {
            // _7ee6: Sonic is held on the log (X = log X - 4 for his 24px wide object)
            $this->pinX = $this->x + $this->width / 2;
        }
    }

    private function tick(): void
    {
        switch ($this->kind) {
            case 'sink':
                $offset = $this->y - $this->startY;
                if ($this->stood) {
                    $this->y = $offset < 16 ? min($this->startY + 16, $this->y + 0.5) : $this->y;
                } elseif ($offset > 0) {
                    $this->y = max($this->startY, $this->y - 0.25);
                }
                break;

            case 'weight':
                if ($this->stood) $this->falling = true;
                if ($this->falling && ++$this->counter >= 0x18) {
                    $this->speedY += 0x40 / 256;
                }
                $this->y += $this->speedY;
                // removed once below the screen
                if ($this->y > $this->startY + 480) {
                    $this->active = false;
                    $this->visible = false;
                }
                break;

            case 'leftRight':
                if (++$this->counter >= 0xA0) {
                    $this->counter = 0;
                    $this->direction = -$this->direction;
                }
                $this->x += $this->direction;
                break;

            case 'fallLog':
                $this->y += 0.5;
                if (($this->frames & 3) === 0 && ++$this->counter >= ($this->objectType === 0x27 ? 0xC0 : 0xC6)) {
                    $this->counter = 0;
                    $this->y = $this->startY;
                }
                break;

            case 'roll':
                $this->y += $this->counter < 0x14 ? 0.25 : -0.25;
                $this->counter = ($this->counter + 1) % 0x28;
                $frame = 0;
                if ($this->stood && $this->playerSpeed != 0) {
                    $speed = $this->playerSpeed / 60;   // px/frame
                    if (!$this->blockedAhead($speed > 0)) {
                        $this->x += $speed / 2;
                    }
                    $this->rolled = fmod($this->rolled + abs($speed), 9);
                    $frame = intdiv((int)$this->rolled, 3);
                }
                $this->setAnimation("f$frame");
                break;

            case 'flyRight':
                if ($this->stood) $this->startX += 0.5;
                $this->x = $this->startX;
                $this->y = $this->startY + $this->wave;
                break;

            case 'upDown':
                $this->speedY += $this->counter < 0x50 ? 1 / 16 : -1 / 16;
                $this->speedY = max(-2, min(2, $this->speedY));
                $this->y += $this->speedY;
                $this->counter = ($this->counter + 1) % 0xA0;
                break;

            case 'float':
                $this->tickFloat();
                break;
        }
    }

    /** $29: the log stops when the block in front of it (X+18 or X-2, at the object Y) is solid. */
    private function blockedAhead(bool $right): bool
    {
        if (!$this->level) return false;
        $x = $right ? $this->x + 0x12 : $this->x - 2;
        $block = $this->level->getTile((int)floor($x / 32), (int)floor(($this->y - 17) / 32));
        $solidity = $block === null ? null : ($this->level->getSolidity()[$block] ?? null);
        return $solidity !== null && (ord($solidity) & 0x3F) !== 0;
    }

    /**
     * doObjectCode_platform_float: once stood on, accelerates upwards by 8/256 px/frame² up to 1px/frame and
     * stops when the block under it has the solidity $1E. Goes back home when Sonic is far away (off-screen).
     */
    private function tickFloat(): void
    {
        if ($this->stood) $this->falling = true;   // "touched" once
        if (!$this->falling || !$this->level) return;
        $block = $this->level->getTile((int)floor(($this->x + 16) / 32), (int)floor(($this->y + 16) / 32));
        $solidity = $this->level->getSolidity()[$block] ?? null;
        if ($solidity !== null && (ord($solidity) & 0x3F) === 0x1E) {
            $this->speedY = 0;
            return;
        }
        $this->speedY = max(-1, $this->speedY - 8 / 256);
        $this->y += $this->speedY;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        parent::render($cameraX, $cameraY);
        if ($this->kind === 'flyRight') {
            // _b388: the propeller on the left side, a new frame every frame
            $frame = RomSprites::PROPELLER[$this->frames & 3];
            $src = new \SDL_Rect;
            [$src->x, $src->y, $src->w, $src->h] = $frame;
            $dst = new \SDL_Rect;
            $dst->x = (int)round($this->x - 8 - $cameraX);
            $dst->y = (int)round($this->y - 8 + ($frame[3] === 16 ? 4 : 0) - $cameraY);
            $dst->w = $frame[2];
            $dst->h = $frame[3];
            \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->getTexture()['texture'], $src, $dst);
        }
    }

    /** Called by the GameObjectManager while Sonic stands on the platform. */
    public function touch(): void
    {
        $this->touched = true;
    }

    public function setPlayerSpeed(float $vx): void
    {
        $this->playerSpeed = $vx;
    }

    public function setWave(float $wave): void
    {
        $this->wave = $wave;
    }

    /** Centre X Sonic is held at ($29 floating log), null when he moves freely. */
    public function getPinX(): ?float
    {
        return $this->pinX;
    }

    public function isFloating(): bool
    {
        return $this->kind === 'float';
    }

    /** Back to the spawn point (the original does it when the platform leaves the screen). */
    public function resetPosition(): void
    {
        $this->x = $this->startX;
        $this->y = $this->startY;
        $this->falling = false;
        $this->speedY = 0;
    }

    public function isPlayerOnTop(array $playerRect, float $playerVy): bool
    {
        if (!$this->active || $playerVy < 0) return false;
        $feet = $playerRect['y'] + $playerRect['height'];
        return $feet >= $this->y - 4 - max(0, $this->deltaY)
            && $feet <= $this->y + 10 + max(0, $this->deltaY)
            && $playerRect['x'] + $playerRect['width'] > $this->x + 2
            && $playerRect['x'] < $this->x + $this->width - 2;
    }

    public function getDeltaX(): float { return $this->deltaX; }

    /**
     * Horizontal move of the last update, handed out once: Sonic's collision runs several steps per frame,
     * he must be carried by the platform once, not once per step (he slid along it).
     */
    public function takeDeltaX(): float
    {
        $dx = $this->carryX;
        $this->carryX = 0;
        return $dx;
    }
    public function getDeltaY(): float { return $this->deltaY; }
}
