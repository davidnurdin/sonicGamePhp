<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;
use SonicGame\SoundManager\SoundManager;

/**
 * Scrap Brain doors (_9ed4 + doObjectCode_door*): $17 opens for Sonic on its left, $18 on its right,
 * $19 both, $1E follows the level switch ($D317 bit, inverted for a door without the $A3 block above it).
 * X + 20 under an $A3 block, else X + 4. The counter goes 0..$30 (+/-1 per frame, 2 for the switch door)
 * and the door slides into the ceiling: wall 4 x (48 - counter) while counter < $28. SFX $19 when it
 * starts to open or close. A door blocking Sonic puts him back at doorX - 20 / doorX + 5 and stops him.
 */
class Door extends Mechanism
{
    private const SPRITES = [0x17 => 96, 0x18 => 112, 0x19 => 80, 0x1E => 128];
    private const MAX = 0x30;

    private float $baseY;
    private int $counter = 0;
    private bool $inverted;
    private bool $switchOn = false;

    public function __construct(Sdl $sdl, int $type, string $typeName, int $tileX, int $tileY, ?int $blockAbove)
    {
        $inverted = $blockAbove !== 0xA3;
        parent::__construct($sdl, $type, $typeName, $tileX * 32 + ($inverted ? 4 : 20), $tileY * 32, 8, 48);
        $this->baseY = $this->y;
        $this->inverted = $inverted;
    }

    /** Switch door created with the level switch already on: shown in its final position (no opening on screen). */
    public function settle(bool $switchOn): static
    {
        if ($this->objectType === 0x1E) {
            $this->switchOn = $switchOn;
            $open = $this->inverted ? !$switchOn : $switchOn;
            $this->counter = $open ? self::MAX : 0;
        }
        return $this;
    }

    public function setSwitchFlag(bool $on): void
    {
        $this->switchOn = $on;
    }

    protected function tick(array $player): void
    {
        if ($this->objectType === 0x1E) {
            $open = $this->inverted ? !$this->switchOn : $this->switchOn;
            $this->counter = $open ? min(self::MAX, $this->counter + 2) : max(0, $this->counter - 2);
            return;
        }

        $sx = self::sonicX($player);
        $sy = self::sonicY($player);
        [$from, $to] = match ($this->objectType) {
            0x17 => [-56, 0],
            0x18 => [-16, 36],
            default => [-56, 36],
        };
        $near = $sx > $this->x + $from && $sx <= $this->x + $to && $sy > $this->baseY - 32 && $sy <= $this->baseY + 80;
        if ($near && $this->counter < self::MAX) {
            if ($this->counter++ === 0) {
                $this->sounds[] = SoundManager::SFX_LIFT;
            }
        } elseif (!$near && $this->counter > 0) {
            if (--$this->counter === self::MAX - 1) {
                $this->sounds[] = SoundManager::SFX_LIFT;
            }
        }
    }

    public function getSolidBox(): ?array
    {
        if ($this->counter >= 0x28) return null;
        return self::box($this->x + 2, $this->baseY, 4, 48 - $this->counter);
    }

    public function render(float $cameraX, float $cameraY): void
    {
        $height = 48 - $this->counter;
        if ($height <= 0) return;
        // the part slid above the frame is hidden in the ceiling
        $this->blit([self::SPRITES[$this->objectType], 80 + $this->counter, 8, $height], $this->x, $this->baseY, $cameraX, $cameraY);
    }
}
