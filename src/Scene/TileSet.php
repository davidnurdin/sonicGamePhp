<?php

namespace SonicGame\Scene;

class TileSet
{
    private array $tiles = [];
    private int $tileWidth = 32;
    private int $tileHeight = 32;

    public function __construct()
    {
        // private int $tileWidth = 32,private int $tileHeight = 32,private $texture
    }

    public function generateTiles($texture): void {

        $this->tiles = [];

        $width = $height = 0;
        $format = null ;
        $access = null ;
        \SDL_QueryTexture($texture, $format , $access , $width, $height);

        for ($y = 0; $y < $height; $y += $this->tileHeight) {
            for ($x = 0; $x < $width; $x += $this->tileWidth) {
                $tileRect = new \SDL_Rect();
                $tileRect->x = $x;
                $tileRect->y = $y;
                $tileRect->w = $this->tileWidth;
                $tileRect->h = $this->tileHeight;
                $this->tiles[] = $tileRect;
            }
        }
    }

    public function getTile(int $index): \SDL_Rect {
        return $this->tiles[$index];
    }

    public function getSurface(): \SDL_Surface {
        return $this->tilesetImage;
    }

    public function getTiles()
    {
        return $this->tiles;
    }

    public function getWidth()
    {
        return $this->tileWidth;
    }

    public function getHeight()
    {
        return $this->tileHeight;
    }


}
