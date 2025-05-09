<?php

namespace SonicGame\Renderer;

use SonicGame\AssetManager\AssetManager;

class SdlTtf
{

    public function __construct(private AssetManager $assetManager)
    {
        \TTF_Init();
    }

    public function __destruct()
    {
        \TTF_Quit();
    }

    public function loadFont($path,$renderer)
    {


        $path = $this->assetManager->getAssetFolder().'/' . $path ;

        if (!file_exists($path)) {
            throw new \RuntimeException("Font file not found: $path");
        }

        // ,$renderer,$transparentColor = null
        $font = \TTF_OpenFont($path, 29);
        if ($font === null) {
            throw new \RuntimeException('Failed to create font: ' . $path);
        }


        $color = new \SDL_Color(255,0,0,0);

        $chaine = '0123456789ABCDEF' ;
        $fontTab = [] ;
        $chaine = str_split($chaine) ;
        foreach ($chaine as $char)
        {
            $TTF_Font = \TTF_RenderText_Solid($font, $char, $color);
            $surfaceTTF = \SDL_CreateTextureFromSurface($renderer, $TTF_Font);
            $fontTab[$char] = $surfaceTTF;
        }


        return $fontTab ;

    }
}
