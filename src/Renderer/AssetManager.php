<?php

namespace SonicGame\Renderer;

class AssetManager
{

    public function getAssetFolder()
    {
        return realpath(__DIR__.'/../../assets');
    }
}
