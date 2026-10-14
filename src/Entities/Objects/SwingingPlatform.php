<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * doObjectCode_platform_swinging ($09): the platform follows the table _682f (X / Y offsets from its load
 * position, a half circle of 77px from the left horizontal to the right one), counter $11 starting at $E0,
 * 2 bytes = one entry per frame, back and forth. Sonic standing on it is moved by its X move (RAM_TEMP1)
 * and put on top (_LABEL_7CC1_12).
 */
class SwingingPlatform extends Platform
{
    private const CHAIN_LINK = ['x' => 272, 'y' => 8, 'w' => 8, 'h' => 8];
    private const FRAME = 1 / 60;
    /** _682f */
    private const POSITIONS = [
        [-77, 0], [-77, 1], [-77, 2], [-77, 2], [-77, 3], [-77, 4], [-77, 5], [-77, 6], [-76, 7], [-76, 8],
        [-76, 9], [-76, 11], [-76, 12], [-76, 13], [-75, 14], [-75, 15], [-75, 17], [-75, 18], [-74, 19], [-74, 21],
        [-73, 22], [-73, 24], [-72, 25], [-72, 27], [-71, 29], [-71, 30], [-70, 32], [-69, 34], [-68, 35], [-67, 37],
        [-66, 39], [-65, 41], [-64, 43], [-62, 45], [-61, 47], [-60, 49], [-58, 50], [-56, 52], [-54, 54], [-52, 56],
        [-50, 58], [-48, 60], [-46, 62], [-44, 63], [-41, 65], [-38, 67], [-36, 68], [-33, 69], [-30, 71], [-27, 72],
        [-24, 73], [-20, 74], [-17, 75], [-14, 76], [-10, 76], [-7, 76], [-4, 77], [0, 77], [3, 77], [7, 76],
        [10, 76], [14, 76], [17, 75], [20, 74], [24, 73], [27, 72], [30, 71], [33, 69], [36, 68], [39, 66],
        [41, 65], [44, 63], [46, 61], [49, 59], [51, 58], [53, 56], [55, 54], [57, 52], [58, 50], [60, 48],
        [62, 46], [63, 44], [64, 42], [65, 40], [67, 38], [68, 36], [69, 35], [69, 33], [70, 31], [71, 29],
        [72, 28], [72, 26], [73, 24], [73, 23], [74, 21], [74, 20], [75, 18], [75, 17], [75, 15], [75, 14],
        [76, 13], [76, 12], [76, 10], [76, 9], [76, 8], [76, 7], [77, 6], [77, 5], [77, 4], [77, 3],
        [77, 2], [77, 1], [77, 0],
    ];

    private float $homeX;
    private float $homeY;
    private int $index = 0x70;     // $11 = $E0 (bytes) on the first frame, going down
    private int $step = -1;
    private float $swingClock = 0;

    public function __construct(Sdl $sdl, int $tileX, int $tileY, array $sprite)
    {
        parent::__construct($sdl, 0x09, 'Swinging platform', $tileX, $tileY, $sprite);
        $this->homeX = $tileX * 32;
        $this->homeY = $tileY * 32;
        $this->place();
    }

    private function place(): void
    {
        [$dx, $dy] = self::POSITIONS[$this->index];
        $this->x = $this->homeX + $dx;
        // the sprite layout starts with an empty row, like the other platforms (+16, +1 line on the SMS)
        $this->y = $this->homeY + $dy + 17;
    }

    public function update(float $deltaTime): void
    {
        if (!$this->active) return;
        $oldX = $this->x;
        $oldY = $this->y;
        $this->swingClock += $deltaTime;
        while ($this->swingClock >= self::FRAME) {
            $this->swingClock -= self::FRAME;
            $this->index += $this->step;
            if ($this->index <= 0) {
                $this->index = 0;
                $this->step = 1;
            } elseif ($this->index >= 0x70) {
                $this->index = 0x70;
                $this->step = -1;
            }
        }
        $this->place();
        $this->deltaX = $this->x - $oldX;
        $this->deltaY = $this->y - $oldY;
        $this->carryX = $this->deltaX;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        // chain from the pivot (the ball of the level block) to the platform
        $texture = $this->getTexture()['texture'];
        $src = new \SDL_Rect;
        $src->x = self::CHAIN_LINK['x'];
        $src->y = self::CHAIN_LINK['y'];
        $src->w = self::CHAIN_LINK['w'];
        $src->h = self::CHAIN_LINK['h'];
        $pivotX = $this->homeX + 16;
        $pivotY = $this->homeY + 16;
        $endX = $this->x + $this->width / 2;
        $endY = $this->y;
        for ($i = 1; $i <= 4; $i++) {
            $dst = new \SDL_Rect;
            $dst->x = (int)round($pivotX + ($endX - $pivotX) * $i / 5 - 4 - $cameraX);
            $dst->y = (int)round($pivotY + ($endY - $pivotY) * $i / 5 - 4 - $cameraY);
            $dst->w = 8;
            $dst->h = 8;
            \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $texture, $src, $dst);
        }
        parent::render($cameraX, $cameraY);
    }
}
