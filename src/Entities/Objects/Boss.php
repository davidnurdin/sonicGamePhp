<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Renderer\Sdl;

/**
 * Act 3 Robotnik, one state machine per zone, ported from the SMS object code
 * (doObjectCode_boss_* in the disassembly). ROM speeds are in px/frame at 60Hz: x60 for px/s, x3600 for px/s².
 * Positions are the original ones: object X = block X * 32, Y = block Y * 32, the camera is locked on the
 * 256x192 arena given to `_7c8c`.
 *
 * think() returns events for the GameObjectManager:
 *   ['part', BossPart]          spawn a projectile / bomb
 *   ['hurt']                    the player touched the boss without attacking
 *   ['hit']                     the player hit the boss (bounce: Y speed reversed, X speed cleared)
 *   ['knockback', vx]           Sky Base: Sonic pushed back from the glass
 *   ['explosion', x, y]
 *   ['carry', x, y]             Scrap Brain lift: Sonic is moved by the lift
 *   ['complete']                act finished without a capsule (Scrap Brain, Sky Base)
 */
class Boss extends GameObject
{
    public const GREEN_HILL = 0x12;
    public const BRIDGE = 0x48;
    public const JUNGLE = 0x2C;
    public const LABYRINTH = 0x49;
    public const SCRAP_BRAIN = 0x22;
    public const SKY_BASE = 0x4A;

    // frames of BossSprites (tools/bosssprites.py), drawn at the object's X / Y like the ROM sprite layouts
    private const PREFIX = [
        self::GREEN_HILL => 'gh', self::JUNGLE => 'jungle', self::BRIDGE => 'bridge',
        self::LABYRINTH => 'lab', self::SCRAP_BRAIN => 'sb', self::SKY_BASE => 'sky',
    ];
    // $D216 / 18: the _7922 layout of the hit egg mobile (+1 facing right), +2 once defeated
    private const HIT_LAYOUT = [self::GREEN_HILL => 1, self::JUNGLE => 5, self::BRIDGE => 9, self::LABYRINTH => 9];
    // _77be: $D2B1 = $18 after a hit, decremented every other frame (_1f06): no contact meanwhile
    private const HIT_FRAMES = 0x30;
    // Robotnik running (_a9b7 / _ba28): frames 3, 4, 5, 4 of _baf9, each shown duration + 1 frames
    private const RUN_FRAMES = [[3, 9], [4, 8], [5, 9], [4, 8]];

    // Green Hill (_724b): command lists ($72A1...), 0 = loop to the index that follows
    private const GH_LISTS = [
        0xA1 => [1, 0, 0], 0xA4 => [2, 0, 0], 0xA7 => [3, 0, 0], 0xAA => [5, 0, 0], 0xAD => [9, 0, 0],
        0xB0 => [7, 7, 7, 7, 4, 4, 4, 4, 4, 8, 0, 0], 0xBC => [11, 11, 11, 11, 6, 6, 6, 6, 6, 8, 0, 0],
    ];
    // _72c8: facing right (layout _730a) for each command
    private const GH_RIGHT = [0, 0, 1, 1, 1, 0, 0, 1, 1, 0, 1, 0];
    // $7261: Y speed (/256 px/frame) bobbing the ship, indexed by the frame counter $13
    private const GH_SINE = [0, 20, 40, 40, 60, 60, 60, 80, 80, 80, 80, 100, 100, 100, 100, 100, 100, 100, 100, 100,
        100, 80, 80, 80, 80, 60, 60, 60, 40, 40, 20, 0, 0, -20, -40, -40, -60, -60, -60, -80, -80, -80, -80, -100,
        -100, -100, -100, -100, -100, -100, -100, -100, -100, -80, -80, -80, -80, -60, -60, -60, -40, -40, -20, 0];

    private const F = 60.0;      // px/frame -> px/s
    private const A = 3600.0;    // px/frame² -> px/s²
    private const FRAME = 1 / 60;

    private const ARENA = [
        self::GREEN_HILL => [0x760, 0xE8],
        self::BRIDGE => [0x3A0, 0x300],
        self::JUNGLE => [0x1F0, 0x48],
        self::LABYRINTH => [0x2D0, 0x290],
        self::SCRAP_BRAIN => [0x530, 0x220],
        self::SKY_BASE => [0x350, 0x120],
    ];

    private int $kind;
    private bool $started = false;
    private int $hits = 0;
    private int $maxHits = 8;
    private float $invulnerable = 0;
    private string $phase = 'idle';
    private int $frames = 0;          // frames in the current phase
    private float $clock = 0;
    private int $step = 0;
    private float $bob = 0;
    private int $chargeDir = -1;
    private int $dipCounter = 0;
    /** Scrap Brain: Robotnik leaves on the lift but the object stays to drive the lift. */
    private bool $robotnikShown = true;
    private float $homeX;
    private float $homeY;
    private bool $gone = false;
    private bool $liftVisible = false;
    private float $liftY = 0;
    /** Top of our real view: the Scrap Brain lift carries Sonic until he has left it (the ROM screen is lower). */
    public float $viewTop = -INF;
    private float $carriedY = 0;
    private array $events = [];
    private array $player = ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0];
    private float $playerVx = 0;
    // position at the previous 60Hz frame: the 120Hz display interpolates between the two
    private float $prevX = 0;
    private float $prevY = 0;
    private bool $facingRight = false;
    private int $tickCount = 0;
    private ?string $layout = null;
    /** _79fa exhaust flame [frame, x offset] / Labyrinth thruster frame */
    private ?array $flame = null;
    private ?string $thrust = null;
    private float $drawDy = 0;
    private string $ghList = 'A1';
    private int $ghIndex = 0;
    private int $ghTimer = 0;
    private int $ghFlags = 0;
    private int $skyPose = 0;

    public function __construct(Sdl $sdl, int $type, string $name, int $tileX, int $tileY)
    {
        parent::__construct($sdl, $type, $name, $tileX * 32, $tileY * 32, 32, 28);
        $this->kind = $type;
        $this->tilesetName = BossSprites::SHEET;
        if ($type === self::BRIDGE) {
            $this->width = 0x1E;
        }
        if ($type === self::GREEN_HILL) {
            $this->y -= 8;
        } elseif ($type === self::SCRAP_BRAIN || $type === self::SKY_BASE) {
            $this->width = 0x1E;
            $this->height = 0x2F;
            if ($type === self::SKY_BASE) {
                $this->x += 8;
                $this->y += 16;
                $this->maxHits = 12;
            }
        }
        $this->homeX = $this->x;
        $this->homeY = $this->y;
        $this->prevX = $this->x;
        $this->prevY = $this->y;
        $this->visible = $type !== self::BRIDGE && $type !== self::LABYRINTH;
        $this->updateLayout();
    }

    public static function supports(int $type): bool
    {
        return isset(self::ARENA[$type]);
    }

    public function isStarted(): bool { return $this->started; }
    public function isGone(): bool { return $this->gone; }
    /** Scrap Brain: Sonic has been carried off the screen by the lift. */
    public function hidesPlayer(): bool { return $this->kind === self::SCRAP_BRAIN && $this->phase === 'done'; }
    public function isDefeated(): bool { return $this->hits >= $this->maxHits; }
    public function getHits(): int { return $this->hits; }
    public function hasFled(): bool { return $this->phase === 'defeated' && $this->frames > 320; }

    /** True while the player's controls are taken away (Scrap Brain lift). */
    public function isCutscene(): bool
    {
        return ($this->kind === self::SCRAP_BRAIN && in_array($this->phase, ['liftUp', 'liftDown', 'carry', 'done'], true))
            || ($this->kind === self::SKY_BASE && $this->phase === 'teleport' && !$this->gone);
    }

    /** SMS camera position (top-left of the 256x192 view) while the boss is fought. */
    public function getCameraLock(): ?array
    {
        // Sky Base: the screen stays locked on the teleporter after Robotnik has gone (_7c8c)
        if ($this->kind === self::SKY_BASE && $this->phase === 'teleport') return [0x550, 0x120];
        if (!$this->started || $this->gone) return null;
        if ($this->kind === self::SCRAP_BRAIN && $this->phase === 'flee') return null;
        // Sky Base: 15 frames after the glass broke the level right becomes $550, Sonic runs after Robotnik
        if ($this->kind === self::SKY_BASE && $this->phase === 'escape' && $this->frames >= 0x0F) {
            return [self::ARENA[self::SKY_BASE][0], self::ARENA[self::SKY_BASE][1], 0x550];
        }
        return self::ARENA[$this->kind];
    }

    /** Horizontal range the player is kept in during the fight. */
    public function getPlayerBounds(): ?array
    {
        $lock = $this->getCameraLock();
        if ($lock === null) {
            return $this->kind === self::SCRAP_BRAIN && $this->started ? [0x340, 0x540 + 256 - 24] : null;
        }
        return [$lock[0], ($lock[2] ?? $lock[0]) + 256 - 24];
    }

    public function think(float $deltaTime, array $playerRect, float $playerVx, bool $attacking): array
    {
        $this->events = [];
        if (!$this->active || $this->gone) return [];
        $this->player = $playerRect;
        $this->playerVx = $playerVx;

        if (!$this->started && $this->shouldStart()) {
            $this->started = true;
            $this->phase = 'start';
            $this->frames = 0;
        }
        if (!$this->started) return [];

        $this->clock += $deltaTime;
        while ($this->clock >= self::FRAME) {
            $this->clock -= self::FRAME;
            $this->prevX = $this->x;
            $this->prevY = $this->y;
            $this->tick($attacking);
            // teleports (Bridge / Labyrinth spots) are not interpolated
            if (abs($this->x - $this->prevX) > 16 || abs($this->y - $this->prevY) > 16) {
                $this->prevX = $this->x;
                $this->prevY = $this->y;
            }
        }

        $this->updateLayout();

        return $this->events;
    }

    private function shouldStart(): bool
    {
        $px = $this->player['x'];
        $py = $this->player['y'];
        return match ($this->kind) {
            // Jungle: Sonic has climbed to the top of the waterfall
            self::JUNGLE => $py < 0xE0 && $px > 0x1F0 - 64,
            self::SCRAP_BRAIN => $px > 0x340,
            default => $px >= self::ARENA[$this->kind][0] - 32 && $px <= self::ARENA[$this->kind][0] + 256
                && $py >= self::ARENA[$this->kind][1] - 64 && $py <= self::ARENA[$this->kind][1] + 192,
        };
    }

    /** One 60Hz frame. */
    private function tick(bool $attacking): void
    {
        $this->frames++;
        $this->tickCount++;
        if ($this->invulnerable > 0) $this->invulnerable -= self::FRAME;

        if ($this->isDefeated() && $this->kind !== self::SKY_BASE && $this->kind !== self::SCRAP_BRAIN) {
            $this->defeatSequence();
            return;
        }

        match ($this->kind) {
            self::GREEN_HILL => $this->greenHill(),
            self::JUNGLE => $this->jungle(),
            self::BRIDGE => $this->bridge(),
            self::LABYRINTH => $this->labyrinth(),
            self::SCRAP_BRAIN => $this->scrapBrain(),
            self::SKY_BASE => $this->skyBase($attacking),
        };

        $this->x += $this->vx / self::F;
        $this->y += $this->vy / self::F;

        if ($this->kind !== self::SCRAP_BRAIN && $this->kind !== self::SKY_BASE && $this->visible) {
            $this->checkContact($attacking);
        }
    }

    /**
     * `_77be`: box X+8..X+width, Y+$0C..Y+height. Touching it hurts Sonic unless he is jumping / rolling;
     * no contact for 48 frames after a hit (the hit layout is shown meanwhile).
     */
    private function checkContact(bool $attacking): void
    {
        $box = ['x' => $this->x + 8, 'y' => $this->y + 0x0C, 'width' => $this->width - 8, 'height' => $this->height - 0x0C];
        if ($this->invulnerable > 0 || !Mechanism::overlaps($box, $this->player)) return;
        if (!$attacking) {
            $this->events[] = ['hurt'];
            return;
        }
        $this->hits++;
        $this->invulnerable = self::HIT_FRAMES * self::FRAME;
        $this->events[] = ['hit'];
    }

    /** After the last hit: 10 explosions 24 frames apart, sinks for 48 frames, then flies off to the right. */
    private function defeatSequence(): void
    {
        if ($this->phase !== 'defeated') {
            $this->phase = 'defeated';
            $this->frames = 0;
            $this->vx = $this->vy = 0;
        }
        $f = $this->frames;
        if ($f <= 240) {
            $this->vx = $this->vy = 0;
            if ($f % 24 === 0) {
                $this->events[] = ['explosion', $this->x + mt_rand(0, 32), $this->y + mt_rand(0, 28)];
            }
        } elseif ($f <= 288) {
            $this->vy = 0x20 / 256 * self::F;
        } elseif ($f <= 320) {
            $this->vy = 0;
        } else {
            if ($f === 321) {
                $this->events[] = ['levelMusic'];   // $D2EE = $5A: the level music comes back
            }
            $this->vx = 3 * self::F;
            $this->vy = -0xA0 / 256 * self::F;
            // _77be: removed once off the right of the screen (X >= camera X + 256), the screen is unlocked
            if ($this->x >= self::ARENA[$this->kind][0] + 256) {
                $this->gone = true;
                $this->visible = false;
                $this->active = false;
            }
        }
        $this->x += $this->vx / self::F;
        $this->y += $this->vy / self::F;
    }

    // ------------------------------------------------------------------ Green Hill
    // doObjectCode_boss_greenHill: a list of commands ($14/$15), the next one every 16 frames ($12, $13).
    // 1 / 2: fly left / right at 1 px/frame bobbing (sine $7261) to the side of the arena, then the next list
    // (left, right, left, then down); 3 / 5: down at $60/256 to the ground; 7 / 11: wait; 4 / 6: charge at
    // 4 px/frame for 80 frames; 8 / 9: back up to the top at 1 px/frame.
    private function greenHill(): void
    {
        [$left, $top] = self::ARENA[self::GREEN_HILL];
        $vy = self::GH_SINE[$this->ghTimer & 0x3F] / 256;
        $list = self::GH_LISTS[hexdec($this->ghList)];
        while (($cmd = $list[$this->ghIndex] ?? 0) === 0) {
            $this->ghIndex = $list[$this->ghIndex + 1] ?? 0;
        }
        $vx = 0;
        switch ($cmd) {
            case 1:
                $vx = -1;
                if ($this->x <= $left + 6) {
                    $this->ghIndex = 0;
                    $this->ghList = ($this->ghFlags & 2) ? 'A7' : 'A4';
                    $this->ghFlags ^= 2;
                }
                break;
            case 2:
                $vx = 1;
                if ($this->x > $left + 0xE0) {
                    $this->ghIndex = 0;
                    $this->ghList = ($this->ghFlags & 4) ? 'AA' : 'A1';
                    $this->ghFlags ^= 4;
                }
                break;
            case 3:
            case 5:
                $vy = 0x60 / 256;
                if ($this->y > $top + 0x74) {
                    $this->ghIndex = 0;
                    $this->ghList = $cmd === 3 ? 'B0' : 'BC';
                }
                break;
            case 4:
                $vx = 4;
                break;
            case 6:
                $vx = -4;
                break;
            case 8:
                $this->ghList = 'AD';
                $this->ghIndex = 0;
                $this->ghTimer = 0;
                break;
            case 9:
                $vy = -1;
                if ($this->y <= $top + 0x1A) {
                    $this->ghIndex = 0;
                    $this->ghList = 'A1';
                }
                break;
        }
        $this->facingRight = (bool)self::GH_RIGHT[$cmd];
        $this->vx = $vx * self::F;
        $this->vy = $vy * self::F;
        if ((++$this->ghTimer & 0x0F) === 0) {
            $this->ghIndex++;
        }
    }

    private function nextStep(): void
    {
        $this->step++;
        $this->frames = 0;
    }

    // ------------------------------------------------------------------ Jungle
    // Comes down to y=$58, flies 1px/frame between x=$21C and x=$2AA; at each end dips 39px and drops a bomb.
    private function jungle(): void
    {
        if ($this->phase === 'start') {
            $this->vy = 0x80 / 256 * self::F;
            if ($this->y >= 0x58) {
                $this->vy = 0;
                $this->phase = 'left';
            }
            return;
        }
        if ($this->phase === 'left' || $this->phase === 'right') {
            $this->vy = 0;
            $this->vx = $this->phase === 'left' ? -self::F : self::F;
            $this->facingRight = $this->phase === 'right';
            if (($this->phase === 'left' && $this->x < 0x21C) || ($this->phase === 'right' && $this->x >= 0x2AA)) {
                $this->step = $this->phase === 'left' ? 1 : 0;
                $this->phase = 'dip';
                $this->dipCounter = 0x67;
            }
            return;
        }
        // dip: counter $67 -> 0
        $this->vx = 0;
        $c = --$this->dipCounter;
        if ($c <= 0) {
            $this->vy = 0;
            $this->phase = $this->step ? 'right' : 'left';
        } elseif ($c >= 0x40) {
            $this->vy = self::F;
        } elseif ($c < 0x28) {
            $this->vy = -self::F;
        } else {
            $this->vy = 0;
            if ($c === 0x34) {
                // object $2B at (X+$0B, Y+$30)
                $bomb = new BossPart($this->sdl, $this->x + 0x0B + 8, $this->y + 0x30 + 8, BossPart::frames2('jungle_bomb', 2), 5 / 60);
                $bomb->explodeFrames = BossPart::frames2('jungle_boom', 3, 1);
                $bomb->setTileset(BossSprites::SHEET);
                $bomb->gravity = 0x20 / 256 * self::A;
                $bomb->maxFall = 3 * self::F;
                $bomb->landOnFloor = true;
                $bomb->friction = 2 / 256 * self::A;
                $bomb->fuse = (0x64 + mt_rand(0, 0x3F)) * 2 / 60;
                $this->events[] = ['part', $bomb];
            }
        }
    }

    // ------------------------------------------------------------------ Bridge
    // Rises out of the water at one of two places, waits 100 frames, fires 3 cannonballs toward Sonic, sinks.
    private function bridge(): void
    {
        switch ($this->phase) {
            case 'start':
            case 'sunk':
                [$this->x, $this->y] = mt_rand(0, 1) ? [0x3D4, 0x3C0] : [0x444, 0x3C0];
                $this->visible = true;
                $this->phase = 'rise';
                break;
            case 'rise':
                $this->vy = -0x80 / 256 * self::F;
                if ($this->y <= 0x380) {
                    $this->vy = 0;
                    $this->phase = 'wait';
                    $this->frames = 0;
                }
                break;
            case 'wait':
                $this->vy = 0;
                if ($this->frames >= 0x64) {
                    $this->fireBridge();
                    $this->phase = 'sink';
                }
                break;
            case 'sink':
                $this->vy = 0x80 / 256 * self::F;
                if ($this->y > 0x3C0) {
                    $this->vy = 0;
                    $this->phase = 'sunk';
                }
                break;
        }
    }

    private function fireBridge(): void
    {
        $left = $this->player['x'] < $this->x;
        $offsetX = $left ? 0 : 0x20;
        $speeds = [[0x140, -0x400], [0x1A0, -0x280], [0x240, -0x100]];
        foreach ($speeds as [$sx, $sy]) {
            // bullet $0D: 8x16 sprite at its X / Y, gravity $10/256 px/frame²
            $ball = (new BossPart($this->sdl, $this->x + $offsetX + 4, $this->y - 10 + 8, BossPart::frames2('bridge_bullet', 2), 1 / 60))
                ->withVelocity(($left ? -$sx : $sx) / 256 * self::F, $sy / 256 * self::F);
            $ball->gravity = 0x10 / 256 * self::A;
            $ball->setTileset(BossSprites::SHEET);
            $ball->life = 4;
            $this->events[] = ['part', $ball];
        }
        $this->events[] = ['sfx', 0x01];
    }

    // ------------------------------------------------------------------ Labyrinth
    // Three spots in turn: from below (fires two shots) or from above (drops a homing mine).
    private function labyrinth(): void
    {
        $spots = [[0x33C, 0x360, 0x328, 0x360], [0x2EC, 0x260, 0x2B0, 0x260], [0x38C, 0x260, 0x2B0, 0x260]];
        [$sx, $sy, $stopY, $backY] = $spots[$this->step];
        switch ($this->phase) {
            case 'start':
                [$this->x, $this->y] = [$sx, $sy];
                $this->visible = true;
                $this->phase = 'in';
                break;
            case 'in':
                $this->vy = ($this->step === 0 ? -0x80 : 0x80) / 256 * self::F;
                if (($this->step === 0 && $this->y <= $stopY) || ($this->step !== 0 && $this->y >= $stopY)) {
                    $this->y = $stopY;
                    $this->vy = 0;
                    $this->phase = 'wait';
                    $this->frames = 0;
                }
                break;
            case 'wait':
                $this->vy = 0;
                if ($this->frames >= 0x64) {
                    if ($this->step === 0) {
                        foreach ([[4, -1], [0x20, 1]] as [$ox, $dir]) {
                            // bullets $0D, gravity 4/256 px/frame²
                            $shot = (new BossPart($this->sdl, $this->x + $ox + 4, $this->y - 6 + 8, BossPart::frames2('lab_bullet', 2), 1 / 60))
                                ->withVelocity($dir * self::F, -self::F);
                            $shot->gravity = 4 / 256 * self::A;
                            $shot->setTileset(BossSprites::SHEET);
                            $shot->life = 4;
                            $this->events[] = ['part', $shot];
                        }
                        $this->events[] = ['sfx', 0x01];
                    } else {
                        // homing torpedo $2F at (X+$0F, Y+$22)
                        $mine = new BossPart($this->sdl, $this->x + 0x0F + 8, $this->y + 0x22 + 8, BossPart::frames2('lab_torpedo_l', 2), 4 / 60);
                        $mine->rightFrames = BossPart::frames2('lab_torpedo_r', 2);
                        $mine->setTileset(BossSprites::SHEET);
                        $mine->homing = [2 / 256 * self::A, self::F, $this->player['x'] + 12 < $this->x + 8 ? -1 : 1];
                        $mine->life = 8;
                        $this->events[] = ['part', $mine];
                    }
                    $this->phase = 'out';
                }
                break;
            case 'out':
                $this->vy = ($this->step === 0 ? 0x80 : -0x80) / 256 * self::F;
                if (($this->step === 0 && $this->y >= $backY) || ($this->step !== 0 && $this->y <= $backY)) {
                    $this->vy = 0;
                    $this->step = ($this->step + 1) % 3;
                    $this->phase = 'start';
                }
                break;
        }
    }

    // ------------------------------------------------------------------ Scrap Brain
    // No fight: Robotnik runs ahead of Sonic to the lift at x=$5A0 and escapes; the lift then comes back for Sonic.
    private function scrapBrain(): void
    {
        $sonicX = $this->player['x'];
        switch ($this->phase) {
            case 'start':
                $this->phase = 'flee';
                // no break
            case 'flee':
                $close = $this->x - $sonicX < 0x40 && $this->playerVx >= 0;
                $this->vx = ($close ? $this->playerVx / self::F : -0.5) * self::F + self::F;
                $this->liftVisible = true;
                $this->liftY = 0x2AF;
                if ($this->x >= 0x5A0) {
                    $this->x = 0x5A0;
                    $this->vx = 0;
                    $this->events[] = ['knockback', 0];   // Sonic's X speed cleared, the joypad is ignored
                    $this->phase = 'liftUp';
                    $this->frames = 0;
                }
                break;
            case 'liftUp':
                $this->vx = 0;
                $this->y = 0x280 - $this->frames;
                $this->liftY = 0x2AF - $this->frames;
                if (($this->frames & 0x1F) === 0x0F) $this->events[] = ['sfx', 0x19];
                // $C0 frames: off the 256x192 screen; our taller view: on until he has left it too
                if ($this->frames >= 0xC0 && ($this->y + 0x30 <= $this->viewTop || $this->y <= 0)) {
                    $this->robotnikShown = false;
                    $this->phase = 'liftDown';
                    $this->carriedY = $this->liftY;
                    $this->frames = 0;
                }
                break;
            case 'liftDown':
                $this->liftY = $this->carriedY + $this->frames;
                if (($this->frames & 0x1F) === 0x0F) $this->events[] = ['sfx', 0x19];
                if ($this->liftY >= 0x2AF) {
                    $this->liftY = 0x2AF;
                    $this->phase = 'waitSonic';
                    $this->frames = 0;
                }
                break;
            case 'waitSonic':
                // $596 < Sonic X <= $5C0, Sonic Y >= $28D (down in the lift shaft)
                if ($sonicX > 0x596 && $sonicX <= 0x5C0 && $this->player['y'] >= 0x28D) {
                    $this->phase = 'carry';
                    $this->frames = 0;
                }
                break;
            case 'carry':
                $this->liftY = 0x2AF - $this->frames;
                $this->events[] = ['carry', 0x5A0, $this->liftY];
                if (($this->frames & 0x1F) === 0x0F) $this->events[] = ['sfx', 0x19];
                // $C0 frames take Sonic above the 256x192 screen; our taller view: on until he has left it too
                if ($this->frames >= 0xC0 && ($this->liftY + 0x10 <= $this->viewTop || $this->liftY <= 0)) {
                    $this->events[] = ['music', 'actcomplete'];
                    $this->phase = 'done';
                    $this->carriedY = $this->liftY;
                    $this->events[] = ['complete'];
                }
                break;
            case 'done':
                // Sonic is put off the screen (camera X + 256), the empty lift goes back down
                $this->events[] = ['carry', 0x5A0, $this->carriedY];
                $this->liftY = min(0x2AF, $this->liftY + 1);
                if ($this->liftY < 0x2AF && ((int)$this->liftY & 0x1F) === 0x0F) $this->events[] = ['sfx', 0x19];
                break;
        }
        $this->vy = 0;
        if (in_array($this->phase, ['liftUp', 'liftDown', 'waitSonic', 'carry', 'done'], true)) {
            $this->vx = 0;
        }
    }

    // ------------------------------------------------------------------ Sky Base
    // Robotnik behind the glass: hops and fires every ~6s; 12 hits on the glass (from x>=$410) break it,
    // then he runs off to the right.
    private function skyBase(bool $attacking): void
    {
        if ($this->hits < $this->maxHits) {
            if ($this->invulnerable <= 0 && $attacking && $this->player['x'] >= 0x410) {
                $this->hits++;
                $this->invulnerable = self::HIT_FRAMES * self::FRAME;
                $this->events[] = ['knockback', -3 * self::F];
                $this->events[] = ['sfx', 0x01];
            }
            switch ($this->phase) {
                case 'start':
                case 'idle':
                    $this->phase = 'idle';
                    $this->vx = $this->vy = 0;
                    // every 8 frames: stands / leans (_ba1c pose 0 / 1) in turn, still from the $1C-th step
                    if ($this->tickCount % 8 === 0) {
                        $this->skyPose = intdiv($this->frames, 8) < 0x1C ? 1 - $this->skyPose : 0;
                    }
                    if ($this->frames >= 40 * 8) {
                        $this->phase = 'jump';
                        $this->frames = 0;
                        $this->x = $this->homeX + 4;
                        $this->vy = -0x1C0 / 256 * self::F;
                    }
                    break;
                case 'jump':
                    $this->vy = min(2 * self::F, $this->vy + 0x0E / 256 * self::A / self::F);
                    if ($this->vy > 0 && $this->y >= $this->homeY) {
                        $this->y = $this->homeY;
                        $this->vy = 0;
                        $this->x = $this->homeX;
                        $this->phase = 'fire';
                        $this->frames = 0;
                        // _b9d5: the electric ball $47, one at a time
                        if ($this->skyBall === null || !$this->skyBall->isActive()) {
                            $shot = new BossPart($this->sdl, 0x420 + 8, 0x12F + 8, RomSprites::ELECTRIC_BALL, 1 / 30);
                            $shot->setTileset(RomSprites::SHEET);
                            $shot->electricWait = 0xC8 / 60;
                            $shot->life = 12;
                            $this->skyBall = $shot;
                            $this->events[] = ['part', $shot];
                        }
                    }
                    break;
                case 'fire':
                    if ($this->frames >= 0x12) {
                        $this->phase = 'idle';
                        $this->frames = 0;
                    }
                    break;
            }
            return;
        }

        if ($this->phase === 'teleport') {
            $this->skyBaseTeleport();
            return;
        }
        if ($this->phase !== 'escape') {
            $this->phase = 'escape';
            $this->frames = 0;
            $this->skyBall?->setActive(false);
            $this->vy = 0;
            $this->events[] = ['explosion', 0x420, 0x12F];
            $this->events[] = ['sfx', 0x21];
            $this->events[] = ['music', 'scrapbrain'];
        }
        // runs to the right ahead of Sonic, with gravity, jumping when blocked
        $close = $this->x - $this->player['x'] < 0x40 && $this->playerVx >= 0;
        $this->vx = $this->x >= 0x5E0 ? 0 : (($close ? $this->playerVx / self::F : -0.5) + 1) * self::F;
        $this->vy = min(2 * self::F, $this->vy + 0x0E / 256 * self::A / self::F);
        if ($this->level) {
            $feetX = (int)($this->x + $this->width / 2);
            $floor = $this->level->findSurfaceY($feetX, (int)($this->y + $this->height - 4), 12);
            if ($floor !== null && $this->vy >= 0 && $this->y + $this->height >= $floor - 1) {
                $this->y = $floor - $this->height;
                $this->vy = 0;
                if ($this->level->isSolidAt((int)($this->x + $this->width + 4), (int)($this->y + $this->height - 8))) {
                    $this->vy = -0x280 / 256 * self::F;
                }
            }
        }
        // on the teleporter block ($49) at $5E0: he stops there, the screen is locked on ($550, $120)
        if ($this->x >= 0x5E0 && $this->vy == 0) {
            $this->phase = 'teleport';
            $this->frames = 0;
            $this->x = 0x5E0;
            $this->y = 0x160;
            $this->vx = 0;
        }
    }

    /**
     * _b821 bit 3: the joypad is ignored; every 8 frames he swaps between pose 0 and pose 1 (2px lower, _b99f),
     * from step $2C the teleport frame _bb77, at step $30 he has gone and 64 frames later the object is
     * removed: Sonic can then take the teleporter (block behaviour 11, $FF = to the end of the game).
     */
    private function skyBaseTeleport(): void
    {
        $this->vx = $this->vy = 0;
        $step = intdiv($this->frames, 8);
        if ($step >= 0x30) {
            $this->robotnikShown = false;
            if ($this->frames >= 0x30 * 8 + 0x40) {
                $this->gone = true;
                $this->visible = false;
            }
        }
    }

    // _b821: the broken glass, 15 patches of 2x2 tiles ($BA45, tools/skybaseglass.py), one a frame
    private const GLASS_X = [0x420, 0x430, 0x440];
    private const GLASS_Y = [0x160, 0x170, 0x180, 0x190, 0x1A0];
    private ?BossPart $skyBall = null;

    private function renderSkyBase(float $cameraX, float $cameraY): void
    {
        $renderer = $this->sdl->getRenderer()->getRenderer();
        $broken = $this->hits >= $this->maxHits;
        if ($broken) {
            $texture = $this->sdl->getTextures('skyglass')[0]['texture'];
            $count = $this->phase === 'escape' ? min(15, $this->frames) : 0;
            for ($i = 0; $i < $count; $i++) {
                $src = new \SDL_Rect;
                [$src->x, $src->y, $src->w, $src->h] = [$i * 16, 0, 16, 16];
                $dst = new \SDL_Rect;
                [$dst->x, $dst->y, $dst->w, $dst->h] = [(int)round(self::GLASS_X[$i % 3] - $cameraX), (int)round(self::GLASS_Y[intdiv($i, 3)] - $cameraY), 16, 16];
                \SDL_RenderCopy($renderer, $texture, $src, $dst);
            }
            return;
        }
        // _ba31 / _ba3b: Robotnik's weapon at ($430, $1A0), raised while he fires
        $frame = RomSprites::SKY_WEAPON[$this->phase === 'fire' ? 1 : 0];
        $src = new \SDL_Rect;
        [$src->x, $src->y, $src->w, $src->h] = $frame;
        $dst = new \SDL_Rect;
        [$dst->x, $dst->y, $dst->w, $dst->h] = [(int)round(0x430 - $cameraX), (int)round(0x1A0 + 1 - $cameraY), 16, 16];
        \SDL_RenderCopy($renderer, $this->sdl->getTextures(RomSprites::SHEET)[0]['texture'], $src, $dst);
    }

    private function runFrame(): int
    {
        $t = $this->tickCount % 34;
        foreach (self::RUN_FRAMES as [$frame, $duration]) {
            if ($t < $duration) return $frame;
            $t -= $duration;
        }
        return 3;
    }

    /** Sprite layout of this frame (null: nothing drawn), with the flame of _79fa / the Labyrinth thruster. */
    private function updateLayout(): void
    {
        $p = self::PREFIX[$this->kind];
        $this->flame = null;
        $this->thrust = null;
        $this->drawDy = 0;
        if ($this->kind === self::SCRAP_BRAIN) {
            $this->layout = $this->phase === 'flee' ? $p . '_f' . $this->runFrame() : $p . '_f0';
            return;
        }
        if ($this->kind === self::SKY_BASE) {
            // _b99f: pose 0 = frame 0, pose 1 = frame 1 2px lower; firing: frame 1 7px lower; jumping: frame 2
            $this->layout = match ($this->phase) {
                'jump' => $p . '_f2',
                'fire' => $p . '_f1',
                'escape' => $this->vx != 0 ? $p . '_f' . $this->runFrame() : $p . '_f' . (intdiv($this->tickCount, 8) % 2),
                'teleport' => intdiv($this->frames, 8) >= 0x2C ? $p . '_end' : $p . '_f' . (intdiv($this->frames, 8) % 2),
                default => $p . '_f' . $this->skyPose,
            };
            $this->drawDy = match ($this->phase) {
                'fire' => 7, 'jump', 'escape' => 0,
                'teleport' => intdiv($this->frames, 8) % 2 ? 2 : 0,
                default => $this->skyPose ? 2 : 0,
            };
            return;
        }
        $base = self::HIT_LAYOUT[$this->kind];
        $right = $this->facingRight ? 1 : 0;
        if ($this->phase === 'defeated') {
            // flies off with the normal ship facing right (_7922)
            $this->layout = $p . '_egg' . ($this->frames > 320 ? 0 : $base + 2 + $right);
        } elseif ($this->invulnerable > 0) {
            $this->layout = $p . '_egg' . ($base + $right);
        } elseif ($this->kind === self::GREEN_HILL || $this->kind === self::JUNGLE) {
            $this->layout = $p . ($right ? '_right' : '_left');
        } else {
            $this->layout = $p . '_body';
        }
        // _79fa: on even frames while moving, tile 0/2 (4/6 going right) at X+$28 (X-8 going right), Y+$10
        if (($this->kind === self::GREEN_HILL || $this->kind === self::JUNGLE) && $this->vx != 0 && $this->tickCount % 2 === 0) {
            $k = ($this->tickCount & 2) ? 1 : 0;
            $this->flame = $this->vx > 0 ? [$p . '_flame' . ($k + 2), -8] : [$p . '_flame' . $k, 0x28];
        }
        if ($this->kind === self::LABYRINTH && $this->vy < 0 && !$this->isDefeated()) {
            $this->thrust = $p . '_thrust' . (($this->tickCount & 2) ? 1 : 0);
        }
    }

    private function blitFrame(string $name, float $x, float $y, float $cameraX, float $cameraY, bool $flash = false): void
    {
        $rect = BossSprites::FRAMES[$name] ?? null;
        if ($rect === null) return;
        $src = new \SDL_Rect;
        [$src->x, $src->y, $src->w, $src->h] = $rect;
        $dst = new \SDL_Rect;
        // the VDP draws the sprites one line lower
        [$dst->x, $dst->y, $dst->w, $dst->h] = [(int)round($x - $cameraX), (int)round($y + 1 - $cameraY), $rect[2], $rect[3]];
        $texture = $this->sdl->getTextures(BossSprites::SHEET . ($flash ? '-flash' : ''))[0]['texture'];
        \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $texture, $src, $dst);
    }

    public function render(float $cameraX, float $cameraY): void
    {
        if ($this->objectType === self::SKY_BASE) {
            $this->renderSkyBase($cameraX, $cameraY);
        }
        if ($this->liftVisible) {
            // _a9c0 at ($5A0, $2AF - $11)
            $this->blitFrame('sb_lift', 0x5A0, $this->liftY, $cameraX, $cameraY);
        }
        if (!$this->visible || !$this->robotnikShown || $this->layout === null) return;

        $alpha = min(1, $this->clock / self::FRAME);
        $x = $this->prevX + ($this->x - $this->prevX) * $alpha;
        $y = $this->prevY + ($this->y - $this->prevY) * $alpha;
        // _1f06: after a hit sprite colour 15 (the outlines) is white while $D2B1 is odd (not in Sky Base,
        // which flashes a tile colour, nor under the water of Labyrinth)
        $d2b1 = (int)ceil($this->invulnerable / self::FRAME / 2);
        $flash = $d2b1 % 2 === 1 && $this->kind !== self::SKY_BASE && $this->kind !== self::LABYRINTH;
        $this->blitFrame($this->layout, $x, $y + $this->drawDy, $cameraX, $cameraY, $flash);
        if ($this->flame !== null) {
            $this->blitFrame($this->flame[0], $x + $this->flame[1], $y + 0x10, $cameraX, $cameraY);
        }
        if ($this->thrust !== null) {
            $this->blitFrame($this->thrust, $x + 0x10, $y + 0x30, $cameraX, $cameraY);
        }
    }
}
