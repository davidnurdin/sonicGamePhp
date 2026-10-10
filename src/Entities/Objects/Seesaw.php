<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\Player;
use SonicGame\Renderer\Sdl;

/**
 * Bridge balance (object $4E, doObjectCode_platform_balance): a seesaw, left pad at X (X-16 of the block),
 * right pad at X+40 under a weight (X+44). $11 = depth of the left pad (0-28), the right one is 28-depth.
 * Sonic landing on the left pad (unless it is already down) pushes it down at his falling speed, gaining
 * gravity every frame, and the weight is thrown up at that speed. While the weight falls back it lifts the
 * left pad (depth = 28 - weight Y) and, if Sonic rides it, he leaves it at the impact at the weight's speed.
 * Gravity of the weight: $38/256 px/frame².
 */
class Seesaw extends Mechanism
{
    private const MAX = 0x1C;
    private const GRAVITY = 0x38 / 256;

    private float $baseY;
    private int $depth = self::MAX;
    private float $weightY = 0;
    private float $weightSpeed = 0;
    private bool $onLeft = false;
    private bool $wasOnLeft = false;
    private ?float $landing = null;
    /** Sonic's speed (px/frame) while he pushes the left pad down */
    private ?float $push = null;
    private ?float $launch = null;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x4E, 'Balance', $tileX * 32 - 16, $tileY * 32, 64, 48);
        $this->tilesetName = RomSprites::SHEET;
        $this->baseY = $tileY * 32;
    }

    protected function tick(array $player): void
    {
        $riding = $this->onLeft || $this->wasOnLeft;
        $this->weightY += $this->weightSpeed;
        $this->weightSpeed += self::GRAVITY;
        if ($this->push === null && $this->weightSpeed >= 0 && $this->weightY >= 0) {
            if ($this->depth !== 0) {
                $this->depth = self::MAX - (int)floor($this->weightY);
                if ($this->depth <= 0) {
                    // Sonic rides the rising pad at the weight's speed and keeps it at the impact
                    if ($riding) {
                        $this->launch = $this->weightSpeed;
                        $this->sounds[] = 0x04;
                    }
                    $this->rest();
                }
            } else {
                $this->rest();
            }
        }

        if ($this->landing !== null && $this->depth !== self::MAX) {
            $this->push = $this->landing;
        }
        $this->landing = null;
        if ($this->push !== null) {
            // every frame Sonic falls on it: the weight takes his speed, the pad goes down by it
            $this->weightSpeed = -$this->push;
            $this->depth += (int)floor($this->push);
            $this->push += self::GRAVITY;
            if ($this->depth >= self::MAX) {
                $this->depth = self::MAX;
                $this->push = null;
            }
        }
        $this->wasOnLeft = $this->onLeft;
        $this->onLeft = false;
    }

    private function rest(): void
    {
        $this->depth = 0;
        $this->weightSpeed = 0;
        $this->weightY = self::MAX;
    }

    /** Tops of the pads: [x, y, width, pad] for the ground of the GameObjectManager. */
    public function getPads(): array
    {
        return [
            [$this->x, $this->baseY + 16 + $this->depth, 24, 'left'],
            [$this->x + 40, $this->baseY + 16 + self::MAX - $this->depth, 24, 'right'],
        ];
    }

    /** Sonic stands on a pad this frame ($vy: his speed in px/s when he lands). */
    public function stand(string $pad, float $vy): void
    {
        if ($pad !== 'left') return;
        // a landing (not riding the pad): it pushes the pad down unless it is already down
        if (!$this->wasOnLeft && !$this->onLeft && $vy >= 0 && $this->push === null) {
            $this->landing = $vy / Player::V_SCALE;
        }
        $this->onLeft = true;
    }

    /** Speed (px/s, upwards, positive) Sonic is thrown at, once. */
    public function takeLaunch(): ?float
    {
        $launch = $this->launch;
        $this->launch = null;
        return $launch === null ? null : $launch * Player::V_SCALE;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        foreach ($this->getPads() as [$x, $y]) {
            $this->blit(RomSprites::SEESAW_PAD, $x, $y + 1, $cameraX, $cameraY);
        }
        $this->blit(RomSprites::SEESAW_WEIGHT, $this->x + 44, $this->baseY + $this->weightY + 1, $cameraX, $cameraY);
    }
}
