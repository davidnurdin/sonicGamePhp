<?php

namespace SonicGame\Renderer;

/**
 * Options > FILTRE CRT: an old TV look drawn over the scaled picture, in one or two copies per frame:
 *  - scanlines: the lower part of every line of the game picture is darkened;
 *  - vignette: the corners get darker, smoothly.
 * Both are RGBA surfaces turned into textures (a surface with an alpha channel gives a blended texture:
 * the SDL extension has no SDL_SetTextureBlendMode / SDL_SetRenderDrawBlendMode), made again only when
 * the size of the picture changes.
 */
class CrtFilter
{
    private const SCANLINE_ALPHA = 105;   // darkness of a scanline (0-255)
    private const VIGNETTE_ALPHA = 150;   // darkness of the corners
    private const VIGNETTE_SIZE = 48;     // the vignette is computed small, then smoothly stretched

    private mixed $scanlines = null;
    private mixed $vignette = null;
    private string $key = '';

    public function __construct(private Renderer $renderer)
    {
    }

    /** Draws the filter over $dst, the picture of $viewHeight game lines scaled on the window. */
    public function draw(\SDL_Rect $dst, int $viewHeight): void
    {
        if ($dst->w <= 0 || $dst->h <= 0 || $viewHeight <= 0) return;
        $key = $dst->h . 'x' . $viewHeight;
        if ($key !== $this->key) {
            $this->free();
            $this->scanlines = $this->makeScanlines($dst->h, $viewHeight);
            $this->vignette ??= $this->makeVignette();
            $this->key = $key;
        }
        $sdlRenderer = $this->renderer->getRenderer();
        if ($this->scanlines) \SDL_RenderCopy($sdlRenderer, $this->scanlines, null, $dst);
        if ($this->vignette) \SDL_RenderCopy($sdlRenderer, $this->vignette, null, $dst);
    }

    public function free(): void
    {
        if ($this->scanlines) \SDL_DestroyTexture($this->scanlines);
        $this->scanlines = null;
        $this->key = '';
    }

    /** 1 pixel wide, as high as the picture on screen: stretched across it. */
    private function makeScanlines(int $height, int $lines): mixed
    {
        $surface = $this->surface(1, $height);
        if (!$surface) return null;
        $scale = $height / $lines;
        $rect = new \SDL_Rect;
        for ($i = 0; $i < $lines; $i++) {
            $top = (int)floor($i * $scale);
            $bottom = (int)floor(($i + 1) * $scale);
            $rows = $bottom - $top;
            if ($rows <= 0) continue;
            if ($rows === 1) {
                // a game line on one screen line (small window): every other line, lighter
                if ($i % 2) $this->fill($surface, $rect, 0, $top, 1, 1, (int)(self::SCANLINE_ALPHA * 0.6));
                continue;
            }
            // the lower ~40% of the line is dark, the first screen line of the dark part a bit less
            $dark = max(1, (int)round($rows * 0.4));
            $this->fill($surface, $rect, 0, $bottom - $dark, 1, $dark, self::SCANLINE_ALPHA);
            if ($dark >= 3) $this->fill($surface, $rect, 0, $bottom - $dark, 1, 1, (int)(self::SCANLINE_ALPHA * 0.6));
        }
        return $this->texture($surface, false);
    }

    /** Darker towards the corners (squared distance from the centre, on an ellipse). */
    private function makeVignette(): mixed
    {
        $n = self::VIGNETTE_SIZE;
        $surface = $this->surface($n, $n);
        if (!$surface) return null;
        $rect = new \SDL_Rect;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $dx = ($x + 0.5) / $n * 2 - 1;
                $dy = ($y + 0.5) / $n * 2 - 1;
                $d = sqrt($dx * $dx * 0.7 + $dy * $dy * 0.7);
                $alpha = (int)round(self::VIGNETTE_ALPHA * max(0, min(1, ($d - 0.55) / 0.6)) ** 2);
                if ($alpha > 0) $this->fill($surface, $rect, $x, $y, 1, 1, $alpha);
            }
        }
        return $this->texture($surface, true);
    }

    /** Transparent RGBA surface. The alpha mask is written -0x1000000 (0xFF000000): it must fit a 32-bit PHP int (wasm). */
    private function surface(int $w, int $h): mixed
    {
        return \SDL_CreateRGBSurface(0, $w, $h, 32, 0x000000FF, 0x0000FF00, 0x00FF0000, -0x1000000) ?: null;
    }

    private function fill($surface, \SDL_Rect $rect, int $x, int $y, int $w, int $h, int $alpha): void
    {
        [$rect->x, $rect->y, $rect->w, $rect->h] = [$x, $y, $w, $h];
        \SDL_FillRect($surface, $rect, \SDL_MapRGBA($surface->format, 0, 0, 0, $alpha));
    }

    private function texture($surface, bool $smooth): mixed
    {
        // the scale quality is read when the texture is created; the game keeps "0" (crisp pixels)
        \SDL_SetHint(\SDL_HINT_RENDER_SCALE_QUALITY, $smooth ? "1" : "0");
        $texture = \SDL_CreateTextureFromSurface($this->renderer->getRenderer(), $surface);
        \SDL_SetHint(\SDL_HINT_RENDER_SCALE_QUALITY, "0");
        \SDL_FreeSurface($surface);
        return $texture ?: null;
    }
}
