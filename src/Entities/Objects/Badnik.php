<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Renderer\Sdl;

class Badnik extends GameObject
{
    // Sprite rects are [x, y, w, h] in tileset-enemies.png; 'left' frames face left, 'right' frames are optional (else flipped).
    public const DEFS = [
        0x08 => ['mode' => 'crab', 'w' => 24, 'h' => 24, 'speed' => 30, 'fd' => 0.15,
            'left' => [[8, 8, 24, 24], [40, 8, 24, 24], [72, 8, 24, 24], [104, 8, 24, 24]], 'symmetric' => true],
        0x10 => ['mode' => 'walker', 'w' => 16, 'h' => 16, 'speed' => 60, 'fd' => 0.1,
            'left' => [[152, 8, 16, 16], [176, 8, 16, 16]], 'right' => [[200, 8, 16, 16], [224, 8, 16, 16]]],
        0x0E => ['mode' => 'flyer', 'w' => 24, 'h' => 25, 'speed' => 90, 'fd' => 0.05, 'bottom' => 32,
            'left' => [[264, 3, 24, 25], [296, 3, 24, 25]]],
        0x26 => ['mode' => 'jumper', 'w' => 13, 'h' => 16, 'speed' => 0, 'fd' => 0.12, 'bottom' => 4,
            'left' => [[538, 8, 13, 16], [562, 8, 13, 16]]],
        0x11 => ['mode' => 'newtron', 'w' => 14, 'h' => 28, 'speed' => 0, 'fd' => 0.3,
            'left' => [[10, 58, 14, 28], [64, 58, 14, 28]]],
        // Yadrin: separate art for each direction like the original (layouts 0-1 left, 2-3 right), never flipped
        0x2D => ['mode' => 'yadrin', 'w' => 23, 'h' => 30, 'speed' => 45, 'fd' => 0.1, 'symmetric' => true,
            'left' => [[137, 66, 23, 30], [170, 66, 22, 30]], 'right' => [[200, 66, 23, 30], [232, 66, 22, 30]]],
        0x3C => ['mode' => 'swimmer', 'w' => 15, 'h' => 14, 'speed' => 30, 'fd' => 0.2,
            'left' => [[281, 64, 15, 14], [313, 64, 15, 14]], 'right' => [[352, 64, 15, 14], [384, 64, 15, 14]]],
        0x35 => ['mode' => 'unidus', 'w' => 14, 'h' => 15, 'speed' => 0, 'fd' => 0.2,
            'left' => [[425, 64, 14, 15], [449, 64, 14, 15]]],
        0x44 => ['mode' => 'walker', 'w' => 15, 'h' => 30, 'speed' => 40, 'fd' => 0.12,
            'left' => [[505, 66, 15, 30], [529, 66, 15, 30], [553, 66, 15, 30]], 'right' => [[576, 66, 15, 30], [600, 66, 15, 30], [624, 66, 15, 30]]],
        0x1F => ['mode' => 'walker', 'w' => 8, 'h' => 16, 'speed' => 20, 'fd' => 0.25, 'segments' => 3,
            'left' => [[8, 112, 8, 16], [64, 112, 8, 16]]],
        0x1B => ['mode' => 'hopper', 'w' => 12, 'h' => 29, 'speed' => 0, 'fd' => 0.2,
            'left' => [[99, 127, 12, 25], [124, 122, 11, 29], [147, 127, 12, 25]], 'right' => [[169, 127, 12, 25], [193, 122, 11, 29], [217, 127, 12, 25]]],
        0x32 => ['mode' => 'bomb', 'w' => 12, 'h' => 16, 'speed' => 0, 'fd' => 0.15,
            'left' => [[282, 121, 12, 15], [306, 120, 12, 16]], 'right' => [[354, 121, 12, 15], [378, 120, 12, 16]]],
    ];

    private const SEGMENT = ['x' => 24, 'y' => 115, 'w' => 8, 'h' => 13];
    private const GRAVITY = 800.0;

    private array $def;
    private string $mode;
    private float $speed;
    private float $startX;
    private float $startY;
    private float $timer = 0;
    private bool $paused = false;
    private bool $appeared = true;
    private bool $airborne = false;
    private array $pendingShots = [];
    // Unidus ($35, doObjectCode_badnick_unidos): object position, angles of the 4 balls ($11-$14, -1 = thrown),
    // throw counter ($15), 60Hz clock
    private float $romX = 0;
    private float $romY = 0;
    private array $ballAngles = [0x00, 0x2A, 0x52, 0x7C];
    private int $throwCounter = 0;
    private int $frames = 0;
    private float $clock = 0;
    // _b031: ball offsets (x, y) for the angles 0-$A3 (by 2)
    private const UNIDUS_ORBIT = [
        [0x0C, 0x03], [0x0D, 0x03], [0x0E, 0x03], [0x0E, 0x04], [0x0F, 0x04], [0x10, 0x04], [0x10, 0x05], [0x11, 0x05],
        [0x11, 0x06], [0x12, 0x06], [0x12, 0x07], [0x13, 0x07], [0x13, 0x08], [0x13, 0x09], [0x14, 0x09], [0x14, 0x0A],
        [0x14, 0x0B], [0x15, 0x0B], [0x15, 0x0C], [0x15, 0x0D], [0x15, 0x0E], [0x15, 0x0F], [0x15, 0x10], [0x15, 0x11],
        [0x14, 0x11], [0x14, 0x12], [0x14, 0x13], [0x13, 0x13], [0x13, 0x14], [0x13, 0x15], [0x12, 0x15], [0x12, 0x16],
        [0x11, 0x16], [0x11, 0x17], [0x10, 0x17], [0x10, 0x18], [0x0F, 0x18], [0x0E, 0x18], [0x0E, 0x19], [0x0D, 0x19],
        [0x0C, 0x19], [0x0B, 0x19], [0x0A, 0x19], [0x09, 0x19], [0x09, 0x18], [0x08, 0x18], [0x07, 0x18], [0x07, 0x17],
        [0x06, 0x17], [0x06, 0x16], [0x05, 0x16], [0x05, 0x15], [0x04, 0x15], [0x04, 0x14], [0x04, 0x13], [0x03, 0x13],
        [0x03, 0x12], [0x03, 0x11], [0x02, 0x11], [0x02, 0x10], [0x02, 0x0F], [0x02, 0x0E], [0x02, 0x0D], [0x02, 0x0C],
        [0x02, 0x0B], [0x03, 0x0B], [0x03, 0x0A], [0x03, 0x09], [0x04, 0x09], [0x04, 0x08], [0x04, 0x07], [0x05, 0x07],
        [0x05, 0x06], [0x06, 0x06], [0x06, 0x05], [0x07, 0x05], [0x07, 0x04], [0x08, 0x04], [0x09, 0x04], [0x09, 0x03],
        [0x0A, 0x03], [0x0B, 0x03],
    ];

    public function __construct(Sdl $sdl, int $type, string $name, int $tileX, int $tileY)
    {
        $def = self::DEFS[$type];
        parent::__construct($sdl, $type, $name, 0, 0, $def['w'], $def['h']);
        $this->def = $def;
        $this->mode = $def['mode'];
        $this->speed = $def['speed'];
        $this->tilesetName = 'enemies';
        $this->frameDuration = $def['fd'];

        $left = self::frames($def['left']);
        $this->animations = [
            'idle' => ['flags' => [], 'coords' => $left],
            'left' => ['flags' => [], 'coords' => $left],
            'right' => isset($def['right'])
                ? ['flags' => [], 'coords' => self::frames($def['right'])]
                : ['flags' => [], 'coords' => $left],
        ];

        $this->placeOnSpawn($tileX, $tileY, $def['bottom'] ?? 16);
        $this->startX = $this->x;
        $this->startY = $this->y;
        $this->facing = 'left';
        $this->vx = -$this->speed;
        if ($this->mode === 'newtron') {
            $this->appeared = false;
            $this->visible = false;
        }
        $this->setAnimation('left');
        if ($this->mode === 'bomb') {
            $this->romX = $tileX * 32;
            $this->x = $this->romX + 2;
        }
        if ($this->mode === 'unidus') {
            // body sprite at X+8, Y+16 of the object (+1 line on the SMS)
            $this->romX = $tileX * 32;
            $this->romY = $tileY * 32;
            $this->x = $this->romX + 9;
            $this->y = $this->romY + 17;
        }
    }

    public static function supports(int $type): bool
    {
        return isset(self::DEFS[$type]);
    }

    /** Called each tick by the manager; returns projectiles to spawn. */
    public function think(float $deltaTime, float $playerX, float $playerY): array
    {
        if (!$this->active) return [];
        $this->pendingShots = [];
        $this->timer += $deltaTime;

        match ($this->mode) {
            'walker' => $this->walk($deltaTime),
            'yadrin' => $this->yadrin($deltaTime),
            'crab' => $this->crab($deltaTime),
            'flyer' => $this->fly($deltaTime, $playerX, $playerY),
            'jumper' => $this->jump($deltaTime),
            'swimmer' => $this->patrolStraight($deltaTime, 96),
            'floater' => $this->y = $this->startY + sin($this->timer * 2) * 12,
            'unidus' => $this->unidus($deltaTime, $playerX, $playerY),
            'bomb' => $this->bomb($deltaTime, $playerX),
            'hopper' => $this->hop($deltaTime, $playerX),
            'newtron' => $this->newtron($playerX, $playerY),
            default => null,
        };

        $anim = $this->facing;
        if ($this->currentAnimation !== $anim) {
            $this->setAnimation($anim);
        }
        $this->flipX = $this->facing === 'right' && !isset($this->def['right']) && empty($this->def['symmetric']);
        if (!$this->paused) {
            $this->updateSprite($deltaTime);
        }

        return $this->pendingShots;
    }

    public function update(float $deltaTime): void
    {
    }

    private function turn(): void
    {
        $this->facing = $this->facing === 'left' ? 'right' : 'left';
        $this->vx = $this->facing === 'left' ? -$this->speed : $this->speed;
    }

    private function walk(float $deltaTime): void
    {
        if ($this->level === null) {
            $this->x += $this->vx * $deltaTime;
            return;
        }

        $centerX = (int)round($this->x + $this->width / 2);
        if ($this->airborne || $this->level->findSurfaceY($centerX, (int)round($this->y + $this->height), 2) === null) {
            $this->fall($deltaTime, $centerX);
            return;
        }
        if ($this->paused) return;

        $dir = $this->vx < 0 ? -1 : 1;
        $nextX = $this->x + $this->vx * $deltaTime;
        $bottom = (int)round($this->y + $this->height);
        $nextCenter = (int)round($nextX + $this->width / 2);
        $frontX = (int)round($nextX + $this->width / 2 + $dir * ($this->width / 2 + 2));

        $groundCenter = $this->level->findSurfaceY($nextCenter, $bottom, 12);
        $groundFront = $this->level->findSurfaceY($frontX, $bottom, 16);
        $tooFar = ($nextX - $this->startX) * $dir > 160;

        if ($groundCenter === null || $groundFront === null || $tooFar) {
            $this->turn();
            return;
        }

        $this->x = $nextX;
        $this->y = $groundCenter - $this->height;
    }

    /**
     * doObjectCode_badnick_yadrin: no edge detection, a timer drives it. A counter goes up every 8 frames:
     * 0-$4F walks left at $C0/256 px/frame, $50-$9F walks right at $40/256 px/frame, then wraps.
     * Gravity like every object; it only stops against walls.
     */
    private function yadrin(float $deltaTime): void
    {
        $cycle = fmod($this->timer * 60, 0xA0 * 8);
        $goingRight = $cycle >= 0x50 * 8;
        $this->facing = $goingRight ? 'right' : 'left';
        $this->vx = $goingRight ? 0x40 / 256 * 60 : -0xC0 / 256 * 60;
        if ($this->level === null) {
            $this->x += $this->vx * $deltaTime;
            return;
        }
        $centerX = (int)round($this->x + $this->width / 2);
        if ($this->airborne || $this->level->findSurfaceY($centerX, (int)round($this->y + $this->height), 2) === null) {
            $this->x += $this->vx * $deltaTime;
            $this->fall($deltaTime, (int)round($this->x + $this->width / 2));
            return;
        }
        $nextX = $this->x + $this->vx * $deltaTime;
        $dir = $goingRight ? 1 : -1;
        $frontX = (int)round($nextX + $this->width / 2 + $dir * ($this->width / 2 + 1));
        $mapRight = $this->level->getMapWidth() * 32 - $this->width;
        if ($this->level->isSolidAt($frontX, (int)round($this->y + $this->height - 10)) || $nextX < 0 || $nextX > $mapRight) {
            return; // against a wall / the level edge
        }
        $this->x = $nextX;
        $nextCenter = (int)round($this->x + $this->width / 2);
        $ground = $this->level->findSurfaceY($nextCenter, (int)round($this->y + $this->height), 12);
        if ($ground !== null) {
            $this->y = $ground - $this->height;
        }
    }

    private function fall(float $deltaTime, int $centerX): void
    {
        $this->airborne = true;
        $this->vy = min($this->vy + self::GRAVITY * $deltaTime, 600);
        $this->y += $this->vy * $deltaTime;
        $bottom = (int)round($this->y + $this->height);
        if ($this->level->isSolidAt($centerX, $bottom)) {
            $surface = $this->level->findSurfaceY($centerX, $bottom, 32);
            if ($surface !== null) {
                $this->y = $surface - $this->height;
            }
            $this->vy = 0;
            $this->airborne = false;
        } elseif ($this->y > $this->startY + 2048) {
            $this->destroy();
        }
    }

    private function crab(float $deltaTime): void
    {
        // Walks ~2s, stops 1s then fires two arcing shots (left and right).
        if ($this->paused) {
            if ($this->timer >= 1.0) {
                $cx = $this->x + $this->width / 2;
                $this->pendingShots[] = [$cx - 10, $this->y + 4, -90, -300, true];
                $this->pendingShots[] = [$cx + 10, $this->y + 4, 90, -300, true];
                $this->paused = false;
                $this->timer = 0;
            }
            return;
        }
        if ($this->timer >= 2.0) {
            $this->paused = true;
            $this->timer = 0;
            return;
        }
        $this->walk($deltaTime);
    }

    private function fly(float $deltaTime, float $playerX, float $playerY): void
    {
        if ($this->paused) {
            if ($this->timer >= 0.6) {
                $this->paused = false;
                $this->timer = -1.5;
            }
            return;
        }
        $this->patrolStraight($deltaTime, 128);

        $dx = $playerX - ($this->x + $this->width / 2);
        if ($this->timer > 0 && abs($dx) < 96 && $playerY > $this->y && $playerY - $this->y < 200) {
            $dir = $this->facing === 'left' ? -1 : 1;
            $this->pendingShots[] = [$this->x + $this->width / 2 + 6 * $dir, $this->y + $this->height, 120 * $dir, 180, false];
            $this->paused = true;
            $this->timer = 0;
        }
    }

    private function patrolStraight(float $deltaTime, float $range): void
    {
        $this->x += $this->vx * $deltaTime;
        if (($this->x < $this->startX - $range && $this->vx < 0) || ($this->x > $this->startX + $range && $this->vx > 0)) {
            $this->turn();
        }
    }

    private function jump(float $deltaTime): void
    {
        // Chopper (original): rests hidden under the bridge for 30 frames, then leaps at -4 px/frame with
        // gravity 1/16 px/frame² and a fall speed capped at 4 px/frame (about 128 px high).
        if (!$this->airborne) {
            $this->visible = false;
            if ($this->timer >= 0.5) {
                $this->airborne = true;
                $this->visible = true;
                $this->vy = -240;
            }
            return;
        }
        $this->vy = min(240, $this->vy + 225 * $deltaTime);
        $this->y += $this->vy * $deltaTime;
        if ($this->vy > 0 && $this->y >= $this->startY) {
            $this->y = $this->startY;
            $this->vy = 0;
            $this->airborne = false;
            $this->visible = false;
            $this->timer = 0;
        }
    }

    private function hop(float $deltaTime, float $playerX): void
    {
        $this->facing = $playerX < $this->x ? 'left' : 'right';
        if (!$this->airborne) {
            if ($this->timer >= 1.2) {
                $this->airborne = true;
                $this->vy = -260;
                $dir = $this->facing === 'left' ? -1 : 1;
                $this->pendingShots[] = [$this->x + $this->width / 2, $this->y + 8, 70 * $dir, -120, true];
            }
            return;
        }
        $this->vy += self::GRAVITY * $deltaTime;
        $this->y += $this->vy * $deltaTime;
        if ($this->y >= $this->startY) {
            $this->y = $this->startY;
            $this->airborne = false;
            $this->timer = 0;
        }
    }

    private function newtron(float $playerX, float $playerY): void
    {
        $dx = $playerX - ($this->x + $this->width / 2);
        if (!$this->appeared) {
            if (abs($dx) < 128 && abs($playerY - $this->y) < 128) {
                $this->appeared = true;
                $this->visible = true;
                $this->timer = 0;
            }
            return;
        }
        $this->facing = $dx < 0 ? 'left' : 'right';
        if ($this->timer >= 1.0) {
            $dir = $dx < 0 ? -1 : 1;
            $this->pendingShots[] = [$this->x + $this->width / 2, $this->y + 10, 150 * $dir, 0, false];
            $this->timer = -2.0;
        }
    }

    /**
     * Drifts towards Sonic (8/256 px/frame) while its 4 balls turn around it (1 step a frame, towards Sonic's
     * side). In Labyrinth (solidity 3), once $15 reached $C8, a ball at the angle $4A is thrown at 1/2 px/frame
     * (object $36) when Sonic is at its height (_af98).
     */
    private function unidus(float $deltaTime, float $playerX, float $playerY): void
    {
        $this->clock += $deltaTime;
        while ($this->clock >= 1 / 60) {
            $this->clock -= 1 / 60;
            $this->frames++;
            $sonicLeft = $this->romX >= $playerX - 12;
            $this->facing = $sonicLeft ? 'left' : 'right';
            $step = $sonicLeft ? 1 : -1;
            $sonicTop = $playerY - 16;
            if ($this->throwCounter >= 0xC8 && $this->level && $this->level->getSolidityIndex() === 3
                && $sonicTop > $this->romY - 0x30 && $sonicTop < $this->romY + 0x2C) {
                foreach ($this->ballAngles as $i => $angle) {
                    if ($angle === 0x4A) {
                        $this->ballAngles[$i] = -1;
                        $this->pendingShots[] = [$this->romX + 0x12 + 4, $this->romY + 0x1E + 6, ($sonicLeft ? -0x80 : 0x80) / 256 * 60, 0, false,
                            RomSprites::UNIDUS_BALL, RomSprites::SHEET];
                    }
                }
            }
            $this->romX += ($sonicLeft ? -8 : 8) / 256;
            $this->x = $this->romX + 9;
            foreach ($this->ballAngles as $i => $angle) {
                if ($angle < 0) continue;
                $angle += $step;
                $this->ballAngles[$i] = $angle === -1 ? 0xA3 : ($angle === 0xA4 ? 0 : $angle);
            }
            if (($this->frames & 7) !== 0 && $this->throwCounter < 0xC8) {
                $this->throwCounter++;
            }
        }
    }

    // Bomb ($32, doObjectCode_badnick_bomb): step counter ($11, +1 every 64 frames), frame of _ad0b shown
    private int $bombStep = 0;
    private int $bombFrame = 0;

    /**
     * Bomb: a step counter goes up every 64 frames (0-$45): it walks left 8/256 px a frame until $1E, waits,
     * walks right from $23 to $41, waits. Sonic within X - $38 .. X + $2C lights it ($64): it stops and
     * flashes ($64-$65), then at $67 bursts into 4 shrapnels ($0D) at (-2, -4), (3, -4), (-2, -2), (3, -2)
     * px a frame (sfx $1B). It can't be destroyed: touching it hurts (_35fd).
     */
    private function bomb(float $deltaTime, float $playerX): void
    {
        $this->clock += $deltaTime;
        while ($this->clock >= 1 / 60 && $this->active) {
            $this->clock -= 1 / 60;
            $this->frames++;
            $sonicX = $playerX - 12;
            if ($this->bombStep < 0x64 && $sonicX >= $this->romX - 0x38 && $sonicX < $this->romX + 0x2C) {
                $this->bombStep = 0x64;
            }
            $step = $this->bombStep;
            $vx = 0;
            if ($step < 0x1E) {
                $vx = -8 / 256;
                $this->bombFrame = intdiv($this->frames, 0x20) % 2;
            } elseif ($step >= 0x64) {
                if ($step < 0x66) {
                    $this->bombFrame = intdiv($this->frames, 2) % 2 ? 4 : 1;
                } else {
                    $this->bombFrame = 4;
                    if ($step === 0x67) {
                        foreach ([[0, 0, -2, -4], [8, 0, 3, -4], [0, 8, -2, -2], [8, 8, 3, -2]] as [$dx, $dy, $sx, $sy]) {
                            $this->pendingShots[] = [$this->romX + $dx + 4, $this->y + $dy + 5, $sx * 60, $sy * 60, true];
                        }
                        $this->pendingSound = 0x1B;
                        $this->destroy();
                        return;
                    }
                }
            } elseif ($step < 0x23) {
                $this->bombFrame = 1;
            } elseif ($step < 0x41) {
                $vx = 8 / 256;
                $this->bombFrame = 2 + intdiv($this->frames, 0x20) % 2;
            } else {
                $this->bombFrame = 3;
            }
            $this->romX += $vx;
            $this->x = $this->romX + 2;
            if ($this->level !== null) {
                $ground = $this->level->findSurfaceY((int)round($this->x + $this->width / 2), (int)round($this->y + $this->height), 8);
                if ($ground !== null) $this->y = $ground - $this->height;
            }
            if ($this->frames % 64 === 0) {
                $this->bombStep++;
                if ($this->bombStep === 0x46) $this->bombStep = 0;
            }
        }
    }

    /** Sound the badnik made this frame (bomb burst), taken by the manager. */
    public ?int $pendingSound = null;

    /** The bomb can't be destroyed: touching it hurts. */
    public function isInvulnerable(): bool
    {
        return $this->mode === 'bomb';
    }

    /** Areas that hurt Sonic whatever he does (the Unidus balls, _35fd). */
    public function getHazards(): array
    {
        if ($this->mode !== 'unidus' || !$this->active) return [];
        $boxes = [];
        foreach ($this->ballAngles as $angle) {
            if ($angle < 0) continue;
            [$dx, $dy] = self::UNIDUS_ORBIT[$angle >> 1];
            $boxes[] = ['x' => $this->romX + $dx + 1, 'y' => $this->romY + $dy + 2, 'width' => 6, 'height' => 6];
        }
        return $boxes;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        if ($this->mode === 'bomb') {
            $src = new \SDL_Rect;
            [$src->x, $src->y, $src->w, $src->h] = RomSprites::BOMB[$this->bombFrame];
            $dst = new \SDL_Rect;
            [$dst->x, $dst->y, $dst->w, $dst->h] = [(int)round($this->romX - $cameraX), (int)round($this->y + 1 - $cameraY), 16, 16];
            \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures(RomSprites::SHEET)[0]['texture'], $src, $dst);
            return;
        }
        if ($this->mode === 'unidus') {
            $texture = $this->sdl->getTextures(RomSprites::SHEET)[0]['texture'];
            $src = new \SDL_Rect;
            [$src->x, $src->y, $src->w, $src->h] = RomSprites::UNIDUS_BALL;
            foreach ($this->getHazards() as $box) {
                $dst = new \SDL_Rect;
                $dst->x = (int)round($box['x'] - 1 - $cameraX);
                $dst->y = (int)round($box['y'] - 2 + 1 - $cameraY);
                $dst->w = 8;
                $dst->h = 16;
                \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $texture, $src, $dst);
            }
        }
        if (!empty($this->def['segments'])) {
            $texture = $this->getTexture()['texture'];
            $src = new \SDL_Rect;
            $src->x = self::SEGMENT['x'];
            $src->y = self::SEGMENT['y'];
            $src->w = self::SEGMENT['w'];
            $src->h = self::SEGMENT['h'];
            $dir = $this->facing === 'left' ? 1 : -1;
            for ($i = $this->def['segments']; $i >= 1; $i--) {
                $dst = new \SDL_Rect;
                $wave = (int)round(sin($this->timer * 6 + $i) * 2);
                $dst->x = (int)round($this->x + $dir * $i * 7 - $cameraX);
                $dst->y = (int)round($this->y + $this->height - self::SEGMENT['h'] + $wave - $cameraY);
                $dst->w = self::SEGMENT['w'];
                $dst->h = self::SEGMENT['h'];
                \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $texture, $src, $dst);
            }
        }
        parent::render($cameraX, $cameraY);
    }

    public function destroy(): void
    {
        $this->active = false;
        $this->visible = false;
    }
}
