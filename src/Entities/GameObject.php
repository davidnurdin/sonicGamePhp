<?php

namespace SonicGame\Entities;

use SonicGame\Level\Level;
use SonicGame\Renderer\Sdl;

class GameObject extends Entity
{
    use Sprite {
        Sprite::update as updateSprite;
    }

    protected bool $active = true;
    protected bool $visible = true;
    protected bool $flipX = false;
    protected int $objectType;
    protected string $typeName;
    protected ?Level $level = null;

    protected array $animations = [
        'idle' => [
            'flags' => [],
            'coords' => [
                ['x' => 0, 'y' => 0, 'w' => 16, 'h' => 16],
            ]
        ],
    ];

    public function __construct(Sdl $sdl, int $objectType, string $typeName, float $x, float $y, int $width = 16, int $height = 16)
    {
        $this->sdl = $sdl;
        $this->objectType = $objectType;
        $this->typeName = $typeName;
        $this->width = $width;
        $this->height = $height;
        parent::__construct($x, $y);
        $this->tilesetName = 'objects';
        $this->setAnimation('idle');
    }

    /** Anchors the object like the original game: horizontally centred on the block, bottom at tileY*32 + $bottomOffset. */
    public function placeOnSpawn(int $tileX, int $tileY, int $bottomOffset = 16): void
    {
        $this->x = $tileX * 32 + 16 - $this->width / 2;
        $this->y = $tileY * 32 + $bottomOffset - $this->height;
    }

    public function setLevel(Level $level): void
    {
        $this->level = $level;
    }

    public function setTileset(string $name): void
    {
        $this->tilesetName = $name;
    }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
    public function isVisible(): bool { return $this->visible; }
    public function getObjectType(): int { return $this->objectType; }
    public function getTypeName(): string { return $this->typeName; }
    public function getPosX(): float { return $this->x; }
    public function getPosY(): float { return $this->y; }
    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }

    public function update(float $deltaTime): void
    {
        if (!$this->active) return;
        $this->updateSprite($deltaTime);
    }

    public function render(float $cameraX, float $cameraY): void
    {
        $frame = $this->getCurrentFrame();
        $dest = new \SDL_Rect;
        $dest->x = (int)round($this->x + ($this->width - $frame['w']) / 2 - $cameraX);
        $dest->y = (int)round($this->y + $this->height - $frame['h'] - $cameraY);
        $dest->w = $frame['w'];
        $dest->h = $frame['h'];
        $this->draw($dest, $this->flipX);
    }

    public function getBoundingBox(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
    }

    public function intersects(array $rect, int $shrink = 0): bool
    {
        return $this->x + $shrink < $rect['x'] + $rect['width']
            && $this->x + $this->width - $shrink > $rect['x']
            && $this->y + $shrink < $rect['y'] + $rect['height']
            && $this->y + $this->height - $shrink > $rect['y'];
    }

    protected static function frames(array $rects): array
    {
        return array_map(fn($r) => ['x' => $r[0], 'y' => $r[1], 'w' => $r[2], 'h' => $r[3]], $rects);
    }
}
