<?php

namespace SonicGame\AssetManager;

class AssetManager
{

    public function getAssetFolder()
    {
        return realpath(__DIR__.'/../../assets');
    }
}
