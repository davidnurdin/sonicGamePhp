<?php

namespace SonicGame\Renderer;

/**
 * Text over the game (debug panel, title menu): one cached texture per character, size and colour, so that
 * changing strings (coordinates every frame) never create new textures. Panels are drawn with panel.png
 * (a black pixel at alpha 170) stretched: the SDL binding has no draw blend mode.
 */
class TextRenderer
{
    private const FONT = '/fonts/NiseSegaSonic.TTF';

    /** @var array<int, mixed> TTF fonts by size */
    private array $fonts = [];
    /** @var array<string, array{0: mixed, 1: int, 2: int}> glyph texture (SDL texture resource), width, height */
    private array $glyphs = [];
    private mixed $panel = null;
    /** @var array<string, int> width() by size and text (the multiplayer scores measure the same lines every frame) */
    private array $widths = [];

    public function __construct(private Sdl $sdl, private string $assetFolder)
    {
    }

    /** Draws $text at ($x, $y) in view pixels; returns its width. */
    public function draw(string $text, int $x, int $y, int $size, array $rgb = [255, 255, 255]): int
    {
        $renderer = $this->sdl->getRenderer()->getRenderer();
        $start = $x;
        foreach (str_split($text) as $char) {
            if ($char === ' ') {
                $x += (int)round($size * 0.5);
                continue;
            }
            [$texture, $w, $h] = $this->glyph($char, $size, $rgb);
            if ($texture !== null) {
                $dst = new \SDL_Rect;
                [$dst->x, $dst->y, $dst->w, $dst->h] = [$x, $y, $w, $h];
                \SDL_RenderCopy($renderer, $texture, null, $dst);
            }
            $x += $w;
        }
        return $x - $start;
    }

    /**
     * Text in the style of the game's screens: a dark outline of $outline pixels and a two-tone vertical
     * gradient (upper half $top, lower half $bottom). Returns its width (outline included).
     */
    public function drawStyled(string $text, int $x, int $y, int $size, array $top, array $bottom, int $outline = 1): int
    {
        $renderer = $this->sdl->getRenderer()->getRenderer();
        $start = $x;
        $x += $outline;
        foreach (str_split($text) as $char) {
            if ($char === ' ') {
                $x += (int)round($size * 0.5) + $outline;
                continue;
            }
            [$shadow, $w, $h] = $this->glyph($char, $size, [16, 16, 48]);
            if ($shadow !== null) {
                [$upper] = $this->glyph($char, $size, $top);
                [$lower] = $this->glyph($char, $size, $bottom);
                $dst = new \SDL_Rect;
                for ($dy = -$outline; $dy <= $outline + 1; $dy++) {
                    for ($dx = -$outline; $dx <= $outline + 1; $dx++) {
                        if ($dx === 0 && $dy === 0) continue;
                        [$dst->x, $dst->y, $dst->w, $dst->h] = [$x + $dx, $y + $dy, $w, $h];
                        \SDL_RenderCopy($renderer, $shadow, null, $dst);
                    }
                }
                $half = intdiv($h, 2);
                foreach ([[$upper, 0, $half], [$lower, $half, $h - $half]] as [$texture, $sy, $sh]) {
                    $src = new \SDL_Rect;
                    [$src->x, $src->y, $src->w, $src->h] = [0, $sy, $w, $sh];
                    [$dst->x, $dst->y, $dst->w, $dst->h] = [$x, $y + $sy, $w, $sh];
                    \SDL_RenderCopy($renderer, $texture, $src, $dst);
                }
            }
            $x += $w + $outline;
        }
        return $x - $start + $outline;
    }

    public function styledWidth(string $text, int $size, int $outline = 1): int
    {
        $width = 2 * $outline;
        foreach (str_split($text) as $char) {
            $width += ($char === ' ' ? (int)round($size * 0.5) : $this->glyph($char, $size, [255, 255, 255])[1]) + $outline;
        }
        return $width;
    }

    public function width(string $text, int $size): int
    {
        $key = $size . '|' . $text;
        if (isset($this->widths[$key])) return $this->widths[$key];
        if (count($this->widths) > 512) $this->widths = [];
        $width = 0;
        foreach (str_split($text) as $char) {
            $width += $char === ' ' ? (int)round($size * 0.5) : $this->glyph($char, $size, [255, 255, 255])[1];
        }
        return $this->widths[$key] = $width;
    }

    /** Half transparent black rectangle. */
    public function panel(int $x, int $y, int $w, int $h): void
    {
        if ($this->panel === null) {
            $this->sdl->loadTexture('ui-panel', 'screens/panel.png');
            $this->panel = $this->sdl->getTextures('ui-panel')[0]['texture'];
        }
        $dst = new \SDL_Rect;
        [$dst->x, $dst->y, $dst->w, $dst->h] = [$x, $y, $w, $h];
        \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->panel, null, $dst);
    }

    private function glyph(string $char, int $size, array $rgb): array
    {
        $key = $size . '|' . implode(',', $rgb) . '|' . $char;
        if (isset($this->glyphs[$key])) return $this->glyphs[$key];
        $font = $this->fonts[$size] ??= \TTF_OpenFont($this->assetFolder . self::FONT, $size);
        $surface = $font ? \TTF_RenderText_Solid($font, $char, new \SDL_Color($rgb[0], $rgb[1], $rgb[2], 255)) : null;
        if (!$surface) {
            return $this->glyphs[$key] = [null, (int)round($size * 0.5), $size];
        }
        $texture = \SDL_CreateTextureFromSurface($this->sdl->getRenderer()->getRenderer(), $surface);
        \SDL_FreeSurface($surface);
        $w = $h = 0;
        $format = $access = 0;
        \SDL_QueryTexture($texture, $format, $access, $w, $h);
        return $this->glyphs[$key] = [$texture, $w, $h];
    }
}
