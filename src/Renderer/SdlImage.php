<?php

namespace SonicGame\Renderer;

use SonicGame\AssetManager\AssetManager;

class SdlImage
{

    public function __construct(private AssetManager $assetManager)
    {




    }


    public function loadImage($path, $renderer, $transparentColor = null)
    {
        $fullPath = $this->assetManager->getAssetFolder() . '/' . $path;

        if (!file_exists($fullPath)) {
            throw new \RuntimeException('Image not found: ' . $path);
        }

        [$width, $height] = getimagesize($fullPath);
        $maxWidth = floor(4096 / 32) * 32; // = 4064

        $textures = [];

        if ($width <= $maxWidth) {
            // Image acceptable, chargement direct
            $surface = \IMG_Load($fullPath);
            if ($surface === null) {
                throw new \RuntimeException('Failed to load image: ' . $path);
            }

            if ($transparentColor !== null) {
                $transparent = \SDL_MapRGB($surface->format, $transparentColor['r'], $transparentColor['g'], $transparentColor['b']);
                \SDL_SetColorKey($surface, 1, $transparent);
            }

            $texture = \SDL_CreateTextureFromSurface($renderer, $surface);
            $width = $surface->w;
            $height = $surface->h;


            \SDL_FreeSurface($surface);

            if ($texture === null) {
                throw new \RuntimeException('Failed to create texture: ' . \SDL_GetError());
            }

            return [ ['texture' => $texture , 'width' => $width , 'height' => $height] ] ;
        }

        // --- Découpage requis ---
        $baseName = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $folder = dirname($fullPath);
        $numSlices = ceil($width / $maxWidth);

        $slicePaths = [];
        $allSlicesExist = true;

        for ($i = 0; $i < $numSlices; $i++) {
            $sliceFilename = "$baseName-$i.$extension";
            $sliceFullPath = "$folder/$sliceFilename";
            $slicePaths[] = $sliceFullPath;

            if (!file_exists($sliceFullPath)) {
                $allSlicesExist = false;
            }
        }

        if (!$allSlicesExist) {
            // Une ou plusieurs tranches manquantes : découpage GD
            $srcImage = imagecreatefrompng($fullPath);
            if (!$srcImage) {
                throw new \RuntimeException("Failed to open image via GD: $fullPath");
            }

            for ($i = 0; $i < $numSlices; $i++) {
                $sliceWidth = min($maxWidth, $width - $i * $maxWidth);
                $destImage = imagecreatetruecolor($sliceWidth, $height);

                imagealphablending($destImage, false);
                imagesavealpha($destImage, true);

                imagecopy($destImage, $srcImage, 0, 0, $i * $maxWidth, 0, $sliceWidth, $height);

                imagepng($destImage, $slicePaths[$i]);
                imagedestroy($destImage);
            }

            imagedestroy($srcImage);
        }

        // Chargement SDL des tranches (déjà existantes ou générées)
        foreach ($slicePaths as $slicePath) {
            $surface = \IMG_Load($slicePath);
            if ($surface === null) {
                throw new \RuntimeException("Failed to load slice image: $slicePath");
            }

            if ($transparentColor !== null) {
                $transparent = \SDL_MapRGB($surface->format, $transparentColor['r'], $transparentColor['g'], $transparentColor['b']);
                \SDL_SetColorKey($surface, 1, $transparent);
            }

            $texture = \SDL_CreateTextureFromSurface($renderer, $surface);
            $width = $surface->w;
            $height = $surface->h;


            \SDL_FreeSurface($surface);

            if ($texture === null) {
                throw new \RuntimeException("Failed to create texture from slice: $slicePath. SDL_Error: " . \SDL_GetError());
            }


            $textures[] = ['texture' => $texture , 'width' => $width , 'height' => $height];
        }

        return $textures;
    }


}
