<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;

/**
 * Base of the traps / mechanisms that follow the 60Hz frame logic of their ROM routine:
 * tick() runs once per original frame, getHurtBox() is the area that hurts Sonic, getSolidBox() a wall.
 * Sound effects are queued in $sounds and played by the GameObjectManager.
 */
abstract class Mechanism extends GameObject
{
    protected const FRAME = 1 / 60;

    /** @var int[] */
    public array $sounds = [];
    /** @var GameObject[] objects it fired (bullets...), taken by the GameObjectManager */
    public array $spawns = [];
    protected int $frames = 0;
    private float $clock = 0;

    /** @param array $player collision rect of Sonic */
    public function think(float $deltaTime, array $player): void
    {
        $this->clock += $deltaTime;
        while ($this->clock >= self::FRAME) {
            $this->clock -= self::FRAME;
            $this->tick($player);
            $this->frames++;
        }
    }

    abstract protected function tick(array $player): void;

    public function getHurtBox(): ?array
    {
        return null;
    }

    public function getSolidBox(): ?array
    {
        return null;
    }

    public static function overlaps(?array $a, array $b): bool
    {
        return $a !== null
            && $a['x'] < $b['x'] + $b['width'] && $a['x'] + $a['width'] > $b['x']
            && $a['y'] < $b['y'] + $b['height'] && $a['y'] + $a['height'] > $b['y'];
    }

    protected static function box(float $x, float $y, float $w, float $h): array
    {
        return ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
    }

    /** Draws a part of the objects sheet at a world position. */
    protected function blit(array $src, float $x, float $y, float $cameraX, float $cameraY, bool $flipY = false): void
    {
        $s = new \SDL_Rect;
        [$s->x, $s->y, $s->w, $s->h] = $src;
        $d = new \SDL_Rect;
        $d->x = (int)round($x - $cameraX);
        $d->y = (int)round($y - $cameraY);
        $d->w = $src[2];
        $d->h = $src[3];
        \SDL_RenderCopyEx($this->sdl->getRenderer()->getRenderer(), $this->getTexture()['texture'], $s, $d, 0, null,
            $flipY ? \SDL_FLIP_VERTICAL : \SDL_FLIP_NONE);
    }

    /** Sonic's left / top in the original coordinates (24x32 object around the collision rect). */
    protected static function sonicX(array $player): float
    {
        return $player['x'] + $player['width'] / 2 - 12;
    }

    protected static function sonicY(array $player): float
    {
        return $player['y'] + $player['height'] - 32;
    }
}
