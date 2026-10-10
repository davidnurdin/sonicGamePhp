<?php

namespace SonicGame\Scene;

use SonicGame\Entities\GameObjectManager;
use SonicGame\Level\Level;
use SonicGame\Renderer\Sdl;

/**
 * HUD laid out like the Master System original (routine $2E5A), in SMS pixel coordinates:
 * ring icon (20,0) + 2 digits (40,0), time M:SS at (24,16) or (112,56) in bonus stages,
 * lives icon + 1 digit at (16,172), i.e. 20px above the bottom of the screen.
 */
class HUD
{
    private const DIGIT_X = 544;
    private const DIGIT_Y = 107;
    private const COLON = [624, 107, 8, 13];
    private const RING_ICON = [579, 128, 11, 16];
    private const LIVES_ICON = [544, 128, 24, 16];
    private const SMS_HEIGHT = 192;

    public function __construct(private Sdl $sdl)
    {
    }

    public function draw(GameObjectManager $objectManager, Level $level): void
    {
        $winW = $this->sdl->getWindow()->getWidth();
        $winH = $this->sdl->getWindow()->getHeight();

        if ($level->getMeta('SR') & 0x04) {
            $this->blit(self::RING_ICON, 20 + 2, 1);
            $this->drawDigits(sprintf('%02d', $objectManager->getRingCount()), 40, 1);
        }

        if ($level->getMeta('TL') & 0x20) {
            $time = (int)ceil($objectManager->getDisplayTime() - 0.0001);
            if ($objectManager->getTimeLimit() === null) $time = (int)floor($objectManager->getLevelTime());
            $text = min(9, intdiv($time, 60)) . ':' . sprintf('%02d', $time % 60);
            $isBonus = ($level->getMeta('TL') & 0x01) !== 0;   // TL bit 0: time centred (special stages)
            $this->drawDigits($text, $isBonus ? (int)($winW / 2 - 16) : 24, $isBonus ? 56 : 17);
        }

        $livesY = $winH - (self::SMS_HEIGHT - 172);
        $this->blit(self::LIVES_ICON, 16, $livesY);
        $this->drawDigits((string)min(9, $objectManager->getLives()), 40, $livesY);
    }

    private function drawDigits(string $text, int $x, int $y): void
    {
        foreach (str_split($text) as $i => $char) {
            $rect = $char === ':' ? self::COLON : [self::DIGIT_X + 8 * (int)$char, self::DIGIT_Y, 8, 13];
            $this->blit($rect, $x + 8 * $i, $y + 1);
        }
    }

    private function blit(array $rect, int $x, int $y): void
    {
        $src = new \SDL_Rect;
        [$src->x, $src->y, $src->w, $src->h] = $rect;
        $dst = new \SDL_Rect;
        $dst->x = $x;
        $dst->y = $y;
        $dst->w = $rect[2];
        $dst->h = $rect[3];
        \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures('objects')[0]['texture'], $src, $dst);
    }
}
