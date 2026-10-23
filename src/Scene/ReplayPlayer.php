<?php

namespace SonicGame\Scene;

use SonicGame\Entities\Objects\RomSprites;
use SonicGame\Entities\Player;
use SonicGame\Level\Level;
use SonicGame\Multiplayer\Versus;
use SonicGame\Renderer\Sdl;
use SonicGame\Renderer\TextRenderer;
use SonicGame\Save\Options;
use SonicGame\Save\ReplayRecorder;
use SonicGame\Utils\Lang;

/**
 * Plays a replay again (ReplayRecorder): the level as it was, the Sonics where they were, the rings taken when
 * they were. The Sonics are not simulated; the objects of the act are, against a puppet put where the Sonic of
 * the player who recorded it was (puppet()): the boss, the sign, the badniks, the monitors react as they did, and
 * what was done to the objects (by him, by the others online) is done again when it was. A match: its finish
 * line, bases, flag and scores as they were. The camera follows one of the Sonics (left / right: another one),
 * space pauses, escape leaves.
 */
final class ReplayPlayer
{
    /** Seconds the results stay on screen at the end. */
    private const END_TIME = 6.0;
    /** Farther than this between two recorded frames (1/30 s): a jump, not interpolated. */
    private const MAX_STEP = 96;

    private float $clock = 0;
    private int $tileIndex = 0;
    private int $objectIndex = 0;
    private int $hudIndex = -1;
    /** The Sonic of the player who recorded it: the objects react to him. */
    private int $subject;
    /** Race: y of the ground under the finish line (Versus::drawFinishLine). */
    private array $finishGround = [];
    private ?Level $level = null;
    private float $overTime = 0;
    public bool $paused = false;
    public int $follow;

    private function __construct(private array $data)
    {
        $this->follow = max(0, min(count($data['entities']) - 1, (int)($data['follow'] ?? 0)));
        $this->subject = $this->follow;
    }

    public static function load(string $file): ?self
    {
        $raw = is_file($file) ? @gzdecode((string)file_get_contents($file)) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || ($data['v'] ?? 0) !== ReplayRecorder::VERSION || empty($data['frames']) || empty($data['entities'])) {
            return null;
        }
        return new self($data);
    }

    public function level(): int
    {
        return (int)$this->data['level'];
    }

    /** The level of the site it was played on (LevelManager::CUSTOM): {id, lt, name}, or null. */
    public function site(): ?array
    {
        return Options::validSite($this->data['site'] ?? null);
    }

    /** Seconds played since its start. */
    public function time(): float
    {
        return $this->clock;
    }

    public function mode(): string
    {
        return (string)($this->data['mode'] ?? 'race');
    }

    public function isMatch(): bool
    {
        return ($this->data['kind'] ?? 'solo') !== 'solo';
    }

    private function index(): int
    {
        return min(count($this->data['frames']) - 1, (int)floor($this->clock * ($this->data['fps'] ?? ReplayRecorder::FPS)));
    }

    /**
     * Where entity $i is now: between the recorded frame and the next one (30 a second, drawn at 60), so that it
     * moves smoothly. Not across a jump (a respawn, a teleport) nor to a Sonic not drawn. Null: not drawn.
     *
     * @return array{0: float, 1: float, 2: array}|null [x, y, the recorded state]
     */
    private function position(int $i): ?array
    {
        $index = $this->index();
        $state = $this->data['frames'][$index][$i] ?? 0;
        if (!is_array($state)) return null;
        [$x, $y] = $state;
        $next = $this->data['frames'][$index + 1][$i] ?? 0;
        if (is_array($next) && abs($next[0] - $x) < self::MAX_STEP && abs($next[1] - $y) < self::MAX_STEP) {
            $fps = $this->data['fps'] ?? ReplayRecorder::FPS;
            $t = max(0.0, min(1.0, $this->clock * $fps - $index));
            $x += ($next[0] - $x) * $t;
            $y += ($next[1] - $y) * $t;
        }
        return [$x, $y, $state];
    }

    /** The flags of a recorded Sonic (ReplayRecorder::BALL...); older replays: in a ball from its picture. */
    private function flagsOf(array $state): int
    {
        $flags = (int)($state[6] ?? 0);
        if (!empty($this->data['state'])) return $flags;
        // the jump / roll frames of Player (its sheet, row 3 + 35, the first four columns)
        $ball = !($flags & 2) && (int)$state[3] === 3 + 35 && (int)$state[2] <= 3 + 27 * 3;
        return $flags | ($ball ? ReplayRecorder::BALL : ReplayRecorder::GROUNDED);
    }

    /**
     * The Sonic the objects of the act see: where the recorded one of the player was, his speed, in a ball or not,
     * on the ground or not, facing left or not; null: not drawn then (dead, blinking).
     *
     * @return array{x: float, y: float, vx: float, vy: float, ball: bool, grounded: bool, left: bool}|null
     */
    public function puppet(): ?array
    {
        $position = $this->position($this->subject);
        if ($position === null) return null;
        [$x, $y, $state] = $position;
        $index = $this->index();
        $fps = $this->data['fps'] ?? ReplayRecorder::FPS;
        $next = $this->data['frames'][$index + 1][$this->subject] ?? 0;
        $vx = $vy = 0.0;
        if (is_array($next) && abs($next[0] - $state[0]) < self::MAX_STEP && abs($next[1] - $state[1]) < self::MAX_STEP) {
            $vx = ($next[0] - $state[0]) * $fps;
            $vy = ($next[1] - $state[1]) * $fps;
        }
        $flags = $this->flagsOf($state);
        return ['x' => $x, 'y' => $y, 'vx' => $vx, 'vy' => $vy, 'ball' => ($flags & ReplayRecorder::BALL) !== 0,
            'grounded' => ($flags & ReplayRecorder::GROUNDED) !== 0, 'left' => ($state[6] & 1) !== 0];
    }

    /** Recorded since state 1: the puppet is hurt only when the Sonic was, the HUD shows the recorded counters. */
    public function hasState(): bool
    {
        return !empty($this->data['state']);
    }

    /** An act alone: [rings, score, lives] of the HUD now, or null (older replays, matches). */
    public function counters(): ?array
    {
        $counters = $this->hud()['counters'] ?? null;
        return is_array($counters) ? array_map('intval', $counters) : null;
    }

    /** The camera follows the Sonic the objects see (it locks on the boss arena, the sign, like in the game). */
    public function followsPuppet(): bool
    {
        return $this->follow === $this->subject;
    }

    public function isOver(): bool
    {
        return $this->index() >= count($this->data['frames']) - 1;
    }

    /** The results have been seen: leave. */
    public function isFinished(): bool
    {
        return $this->isOver() && $this->overTime >= self::END_TIME;
    }

    /**
     * Every frame: the time goes on, the blocks change, what was done to the objects is done again
     * ($applyObject(id, what): GameObjectManager::applyObject), $camera (Sonic, not drawn) is where the camera looks.
     */
    public function update(float $deltaTime, Level $level, Player $camera, ?\Closure $applyObject = null): void
    {
        $this->level = $level;
        if (!$this->paused) $this->clock += $deltaTime;
        if ($this->isOver()) $this->overTime += $deltaTime;
        $index = $this->index();
        $tiles = $this->data['tiles'] ?? [];
        while ($this->tileIndex < count($tiles) && $tiles[$this->tileIndex][0] <= $index) {
            [, $x, $y, $value] = $tiles[$this->tileIndex++];
            $level->setTile((int)$x, (int)$y, (int)$value);
        }
        $objects = $this->data['objects'] ?? [];
        while ($this->objectIndex < count($objects) && $objects[$this->objectIndex][0] <= $index) {
            [, $id, $what] = $objects[$this->objectIndex++];
            if ($applyObject !== null) $applyObject((int)$id, (string)$what);
        }
        $hud = $this->data['hud'] ?? [];
        while ($this->hudIndex + 1 < count($hud) && $hud[$this->hudIndex + 1][0] <= $index) $this->hudIndex++;
        $position = $this->position($this->follow);
        if ($position !== null) {
            $camera->setXY($position[0], $position[1]);
            $camera->setVelocity(0, 0);
        }
    }

    /** Left / right: the camera follows another Sonic. */
    public function next(int $step): void
    {
        $n = count($this->data['entities']);
        $this->follow = (($this->follow + $step) % $n + $n) % $n;
    }

    /** The screen of the match recorded now (ReplayRecorder::hud), or null. */
    private function hud(): ?array
    {
        return $this->data['hud'][$this->hudIndex][1] ?? null;
    }

    /** In the game view (Scene::worldOverlay): a match's finish line, bases and flag, then every Sonic. */
    public function drawWorld(Sdl $sdl, float $cameraX, float $cameraY): void
    {
        $renderer = $sdl->getRenderer()->getRenderer();
        $world = $this->data['world'] ?? [];
        foreach ($world['bases'] ?? [] as [$bx, $by, $rgb]) {
            Versus::drawBase($sdl, (int)round($bx - $cameraX), (int)round($by - $cameraY), $rgb);
        }
        $hud = $this->hud();
        if (isset($hud['flag'])) Versus::drawFlag($sdl, (int)round($hud['flag'][0] - $cameraX), (int)round($hud['flag'][1] - $cameraY), 40);
        if (isset($world['goal'])) {
            Versus::drawFinishLine($sdl, $this->level, (float)$world['goal'], (int)round($world['goal'] - $cameraX), $cameraY, $this->finishGround);
        }
        foreach (array_keys($this->data['frames'][$this->index()] ?? []) as $i) {
            $position = $this->position($i);
            if ($position === null) continue;
            [$x, $y, [, , $sx, $sy, $sw, $sh, $flags]] = $position;
            $texture = (string)($this->data['entities'][$i]['tex'] ?? 'sonic');
            $rom = ($flags & 2) !== 0;
            if ($rom) $texture = Player::romSheetOf($texture);
            if (!$sdl->hasTexture($texture)) continue;
            $src = new \SDL_Rect;
            [$src->x, $src->y, $src->w, $src->h] = [$sx, $sy, $sw, $sh];
            $dst = new \SDL_Rect;
            // like Scene::drawPlayer: 32x32, 4px left of the 24px box; the ROM art 24px wide
            [$dst->x, $dst->y, $dst->w, $dst->h] = [(int)round($x - 4 - $cameraX), (int)round($y - $cameraY), 32, 32];
            if ($rom) [$dst->x, $dst->w] = [$dst->x + 4, 24];
            $state = $this->flagsOf($position[2]);
            if (($state & ReplayRecorder::SHIELD) && (int)floor($this->clock * 60) % 2 === 0) {
                // a shield, like Scene::drawPlayer: every other frame the ring instead of the Sonic
                [$src->x, $src->y, $src->w, $src->h] = RomSprites::SHIELD;
                [$dst->x, $dst->w] = [(int)round($x - $cameraX), 24];
                \SDL_RenderCopy($renderer, $sdl->getTextures(RomSprites::SHEET)[0]['texture'], $src, $dst);
            } else {
                \SDL_RenderCopyEx($renderer, $sdl->getTextures($texture)[0]['texture'], $src, $dst, 0, null,
                    ($flags & 1) ? \SDL_FLIP_HORIZONTAL : \SDL_FLIP_NONE);
            }
            if ($state & ReplayRecorder::INVINCIBLE) {
                Scene::drawInvincibilityStars($sdl, (int)round($x - 4 - $cameraX) + 16, (int)round($y - $cameraY) + 16);
            }
            // the flag over the head of its carrier
            if (($hud['carrier'] ?? null) === $i) Versus::drawFlag($sdl, (int)round($x + 12 - $cameraX), (int)round($y - 2 - $cameraY), 22);
        }
    }

    /** Over the picture (window pixels): the names, REPLAY and the time, the results at the end. */
    public function drawOverlay(Sdl $sdl, TextRenderer $text, \SDL_Rect $dst, float $scale, int $windowHeight, float $cameraX, float $cameraY): void
    {
        $size = max(12, (int)round($windowHeight / 34));
        $entities = $this->data['entities'];
        foreach (array_keys($this->data['frames'][$this->index()] ?? []) as $i) {
            $position = count($entities) < 2 ? null : $this->position($i);
            if ($position === null) continue;
            $wx = $dst->x + ($position[0] + 12 - $cameraX) * $scale;
            $wy = $dst->y + ($position[1] - $cameraY) * $scale;
            $label = (string)$entities[$i]['name'];
            $text->draw($label, (int)($wx - $text->width($label, $size) / 2), (int)($wy - $size * 1.3), $size, $entities[$i]['rgb'] ?? [255, 255, 255]);
        }
        $fps = $this->data['fps'] ?? ReplayRecorder::FPS;
        $time = fn(float $s) => sprintf('%d:%02d', (int)($s / 60), (int)$s % 60);
        $lines = [[Lang::t('REPLAY') . '  ' . $time(min($this->clock, count($this->data['frames']) / $fps)) . ' / '
            . $time(count($this->data['frames']) / $fps) . ($this->paused ? '  ' . Lang::t('PAUSE') : ''), [255, 255, 85]]];
        if (count($entities) > 1) {
            $lines[] = ['< ' . $entities[$this->follow]['name'] . ' >', $entities[$this->follow]['rgb'] ?? [255, 255, 255]];
        }
        if ($this->isOver()) {
            foreach ($this->data['result'] ?? [] as $rank => [$name, $score]) {
                $lines[] = [Lang::rank($rank + 1) . '  ' . $name . '  ' . Lang::t((string)$score), [255, 255, 255]];
            }
        }
        $hud = $this->hud();
        if ($hud !== null) {
            // a match: its scores top right, its countdown, GO, its messages, as they were
            Versus::drawScoreLines($text, $dst, $size, $hud['score'] ?? []);
            $big = $size * 4;
            $middle = (int)($dst->y + $dst->h / 2);
            if (isset($hud['countdown'])) {
                $text->draw($hud['countdown'], (int)($dst->x + ($dst->w - $text->width($hud['countdown'], $big)) / 2), $middle - $big, $big, [255, 255, 85]);
                $mode = (string)($hud['mode'] ?? '');
                $text->draw($mode, (int)($dst->x + ($dst->w - $text->width($mode, $size * 2)) / 2), $middle + $size, $size * 2, [255, 255, 255]);
            } elseif (!empty($hud['go'])) {
                $text->draw('GO', (int)($dst->x + ($dst->w - $text->width('GO', $big)) / 2), $middle - $big, $big, [85, 255, 85]);
            } elseif (isset($hud['banner']) && !$this->isOver()) {
                [$label, $rgb] = $hud['banner'];
                $px = $text->width($label, $size * 2) > $dst->w - $size * 2 ? $size : $size * 2;
                $text->draw($label, (int)($dst->x + ($dst->w - $text->width($label, $px)) / 2), (int)($dst->y + $dst->h / 3), $px, $rgb);
            }
        }
        // one dark box at the top, like the scoreboard of the matches
        $width = max(array_map(fn(array $line) => $text->width($line[0], $size), $lines)) + $size * 2;
        $box = new \SDL_Rect;
        [$box->x, $box->y, $box->w, $box->h] = [(int)($dst->x + ($dst->w - $width) / 2), $dst->y + (int)($size * 0.5),
            (int)$width, (int)($size * (1.3 * count($lines) + 0.7))];
        $sdl->getRenderer()->setColor(12, 24, 70, 255);
        \SDL_RenderFillRect($sdl->getRenderer()->getRenderer(), $box);
        foreach ($lines as $i => [$line, $rgb]) {
            $text->draw($line, (int)($dst->x + ($dst->w - $text->width($line, $size)) / 2), (int)($box->y + $size * (0.4 + 1.3 * $i)), $size, $rgb);
        }
    }
}
