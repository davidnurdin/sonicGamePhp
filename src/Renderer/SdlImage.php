<?php

namespace SonicGame\Renderer;

use Symfony\Component\Console\Application;

class SdlImage
{

    public function __construct(private AssetManager $assetManager)
    {




    }

    public function loadImage($path,$renderer,$transparentColor = null)
    {
        $surface = \IMG_Load($this->assetManager->getAssetFolder().$path );
        if ($surface === null) {
            throw new \RuntimeException('Failed to load image: ' .$path);
        }

        // rewrite with original API SDL
        if ($transparentColor !== null) {
            $transparent = \SDL_MapRGB($surface->format, $transparentColor['r'], $transparentColor['g'], $transparentColor['b']);
            \SDL_SetColorKey($surface, 1, $transparent);
        }
        $texture = \SDL_CreateTextureFromSurface($renderer, $surface);
        \SDL_FreeSurface($surface);

        if ($texture === null) {
            throw new \RuntimeException('Failed to create texture: ' . \SDL_GetError());
        }

        return $texture;
    }
}
