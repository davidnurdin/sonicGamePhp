<?php

namespace SonicGame\Scene;

use SonicGame\Entities\Player;
use SonicGame\Level\Level;
use SonicGame\Renderer\Sdl;
use SonicGame\Renderer\TextRenderer;
use SonicGame\Save\Options;
use SonicGame\Save\ReplayRecorder;
use SonicGame\Utils\Lang;

/**
 * Plays a replay again (ReplayRecorder): the level as it was, the Sonics where they were, the rings taken when
 * they were. Nothing is simulated: the camera follows one of the Sonics (left / right: another one), space
 * pauses, escape leaves.
 */
final class ReplayPlayer
{
    /** Seconds the results stay on screen at the end. */
    private const END_TIME = 6.0;
    /** Farther than this between two recorded frames (1/30 s): a jump, not interpolated. */
    private const MAX_STEP = 96;

    private float $clock = 0;
    private int $tileIndex = 0;
    private float $overTime = 0;
    public bool $paused = false;
    public int $follow;

    private function __construct(private array $data)
    {
        $this->follow = max(0, min(count($data['entities']) - 1, (int)($data['follow'] ?? 0)));
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

    public function isOver(): bool
    {
        return $this->index() >= count($this->data['frames']) - 1;
    }

    /** The results have been seen: leave. */
    public function isFinished(): bool
    {
        return $this->isOver() && $this->overTime >= self::END_TIME;
    }

    /** Every frame: the time goes on, the blocks change, $camera (Sonic, not drawn) is where the camera looks. */
    public function update(float $deltaTime, Level $level, Player $camera): void
    {
        if (!$this->paused) $this->clock += $deltaTime;
        if ($this->isOver()) $this->overTime += $deltaTime;
        $index = $this->index();
        $tiles = $this->data['tiles'] ?? [];
        while ($this->tileIndex < count($tiles) && $tiles[$this->tileIndex][0] <= $index) {
            [, $x, $y, $value] = $tiles[$this->tileIndex++];
            $level->setTile((int)$x, (int)$y, (int)$value);
        }
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

    /** In the game view (Scene::worldOverlay): every Sonic. */
    public function drawWorld(Sdl $sdl, float $cameraX, float $cameraY): void
    {
        $renderer = $sdl->getRenderer()->getRenderer();
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
            \SDL_RenderCopyEx($renderer, $sdl->getTextures($texture)[0]['texture'], $src, $dst, 0, null,
                ($flags & 1) ? \SDL_FLIP_HORIZONTAL : \SDL_FLIP_NONE);
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
