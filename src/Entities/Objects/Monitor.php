<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Level\Level;
use SonicGame\Renderer\Sdl;

class Monitor extends GameObject
{
    private const STATIC_SCREEN = [461, 8, 22, 24];

    private const ITEMS = [
        0x01 => ['ring', [493, 8, 22, 24]],
        0x02 => ['speed', [525, 8, 22, 24]],
        0x03 => ['life', [557, 8, 22, 24]],
        0x04 => ['shield', [461, 40, 22, 24]],
        0x05 => ['invincibility', [493, 40, 22, 24]],
        0x51 => ['checkpoint', [525, 40, 22, 24]],
        0x52 => ['continue', [557, 40, 22, 24]],
    ];

    private string $itemType;
    private int $tileX;
    private int $tileY;

    public function __construct(Sdl $sdl, int $objectType, string $typeName, int $tileX, int $tileY)
    {
        parent::__construct($sdl, $objectType, $typeName, 0, 0, 22, 24);
        [$this->itemType, $icon] = self::ITEMS[$objectType] ?? self::ITEMS[0x01];

        // The screen shows the item, with a short burst of static now and then.
        $frames = array_merge(array_fill(0, 7, $icon), [self::STATIC_SCREEN]);
        $this->animations = ['idle' => ['flags' => [], 'coords' => self::frames($frames)]];
        $this->frameDuration = 0.08;
        $this->setAnimation('idle');
        $this->placeOnSpawn($tileX, $tileY, 16);
        $this->tileX = $tileX;
        $this->tileY = $tileY;
    }

    /**
     * _5da8: in Green Hill (solidity 0) a monitor spawned on block $AB (palm tree top) is moved $16-4 px right
     * and $12 px down, into the leaves that are drawn in front of it.
     */
    public function setLevel(Level $level): void
    {
        parent::setLevel($level);
        if ($level->getMeta('SP') === 0 && $level->getTile($this->tileX, $this->tileY) === 0xAB) {
            $this->x += 0x16 - 4;
            $this->y += 0x12;
        }
    }

    public static function supports(int $type): bool
    {
        return isset(self::ITEMS[$type]);
    }

    public function getItemType(): string
    {
        return $this->itemType;
    }

    // _5deb: hit from below by Sonic rolling upwards, the monitor jumps (Y speed -$180/256 px/frame) instead of
    // breaking; from then on it falls ($40/256 px/frame²) onto the floor (_32e2)
    private bool $bumped = false;
    private float $bumpVy = 0;

    public function bump(): void
    {
        $this->bumped = true;
        $this->bumpVy = -0x180 / 256 * 60;
    }

    public function update(float $deltaTime): void
    {
        parent::update($deltaTime);
        if (!$this->bumped || !$this->active) return;
        $this->bumpVy += 0x40 / 256 * 3600 * $deltaTime;
        $this->y += $this->bumpVy * $deltaTime;
        if ($this->bumpVy >= 0 && $this->level) {
            $floor = $this->level->findSurfaceY((int)($this->x + $this->width / 2), (int)($this->y + $this->height - 8), 16);
            if ($floor !== null && $this->y + $this->height >= $floor) {
                $this->y = $floor - $this->height;
                $this->bumpVy = 0;
            }
        }
    }

    public function breakMonitor(): string
    {
        $this->active = false;
        $this->visible = false;
        return $this->itemType;
    }
}
