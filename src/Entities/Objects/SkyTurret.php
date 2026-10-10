<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Level\Level;
use SonicGame\Renderer\Sdl;

/**
 * Sky Base turrets firing bullets (object $0D, _6ac1):
 *  $3A doObjectCode_trap_turretFixed: the cannon is the block under the object ($3C-$3F = up-left, up-right,
 *      down-left, down-right). Every 16 frames during 64 frames out of 128 it fires diagonally (1.5px/frame)
 *      when Sonic is in its quarter (_b4e6).
 *  $37 doObjectCode_trap_turretRotating: turns one of 8 steps every 64 frames (random start), draws its barrel
 *      (_b227) and fires every 26 frames in its direction (_b267).
 */
class SkyTurret extends Mechanism
{
    // _b4e6: x speed, y speed (1/256 px/frame), x / y offset, Sonic's side (high byte of Sonic - turret)
    private const FIXED = [
        [-0x180, -0x180, 0, -8, -1, -1], [0x180, -0x180, 0x18, -8, 0, -1],
        [-0x180, 0x180, 0, 0x10, -1, 0], [0x180, 0x180, 0x18, 0x10, 0, 0],
    ];
    // _b267: x speed, y speed, x / y offset
    private const ROTATING = [
        [0, -0x200, 8, -16], [0x100, -0x100, 24, -8], [0x200, 0, 30, 7], [0x100, 0x100, 22, 22],
        [0, 0x200, 8, 32], [-0x100, 0x100, -8, 24], [-0x200, 0, -14, 7], [-0x100, -0x100, -9, -10],
    ];
    // _b227: barrel sprites [dx, dy, tile]
    private const BARREL = [
        [[8, -8, 0x66]], [[12, -6, 0x70], [20, -6, 0x72]], [[15, 7, 0x4C], [23, 7, 0x4E]], [[13, 12, 0x6C], [21, 12, 0x6E]],
        [[8, 15, 0x64]], [[-4, 12, 0x68], [4, 12, 0x6A]], [[-7, 7, 0x48], [1, 7, 0x4A]], [[-5, -7, 0x50], [3, -7, 0x52]],
    ];

    private ?int $orientation = null;
    private int $direction;
    private int $counter = 0;

    public function __construct(Sdl $sdl, int $type, private int $tileX, private int $tileY)
    {
        parent::__construct($sdl, $type, $type === 0x37 ? 'Rotating cannon' : 'Small cannon', $tileX * 32, $tileY * 32, 16, 16);
        $this->tilesetName = RomSprites::SHEET;
        $this->direction = mt_rand(0, 7);
    }

    public function setLevel(Level $level): void
    {
        parent::setLevel($level);
        if ($this->objectType === 0x3A) {
            $block = (int)$level->getTile($this->tileX, $this->tileY) - 0x3C;
            $this->orientation = $block >= 0 && $block < 4 ? $block : null;
        }
    }

    protected function tick(array $player): void
    {
        if ($this->objectType === 0x3A) {
            if ($this->orientation === null) return;
            $this->counter = ($this->counter + 1) & 0xFF;
            if (($this->counter & 0x40) || ($this->counter & 0x0F)) return;
            [$vx, $vy, $ox, $oy, $sideX, $sideY] = self::FIXED[$this->orientation];
            $dx = (int)floor((self::sonicX($player) - $this->x) / 256);
            $dy = (int)floor((self::sonicY($player) - $this->y) / 256);
            if ($dx === $sideX && $dy === $sideY) {
                $this->fire($vx, $vy, $ox, $oy);
            }
            return;
        }
        if (($this->frames & 0x3F) === 0) {
            $this->direction = ($this->direction + 1) & 7;
        }
        if (++$this->counter >= 0x1A) {
            $this->counter = 0;
            [$vx, $vy, $ox, $oy] = self::ROTATING[$this->direction];
            $this->fire($vx, $vy, $ox, $oy);
        }
    }

    /** _b5c2: a bullet $0D at the object + offset (8x16 sprite), SFX $01. */
    private function fire(int $vx, int $vy, int $ox, int $oy): void
    {
        $shot = new Projectile($this->sdl, $this->x + $ox + 4, $this->y + $oy + 8, $vx / 256 * 60, $vy / 256 * 60, false,
            RomSprites::skyTile(0x06), RomSprites::SHEET);
        // _6ac1: not stopped by the scenery, removed once off the screen; tiles $06 / $08 every other frame
        $shot->wallGrace = INF;
        $shot->frames2 = [RomSprites::skyTile(0x06), RomSprites::skyTile(0x08)];
        $this->spawns[] = $shot;
        $this->sounds[] = 0x01;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        if ($this->objectType !== 0x37) return;
        foreach (self::BARREL[$this->direction] as [$dx, $dy, $tile]) {
            $this->blit(RomSprites::skyTile($tile), $this->x + $dx, $this->y + $dy + 1, $cameraX, $cameraY);
        }
    }
}
