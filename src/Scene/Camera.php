<?php

namespace SonicGame\Scene;

use SonicGame\Entities\Entity;
use SonicGame\Loop\GameLoop;
use SonicGame\Renderer\Window;
use SonicGame\Utils\Vector;

class Camera
{
    use Vector ;

	public bool $noSmooth = false ;
	public ?\Closure $cameraCallback = null;

    private ?Entity $stickedEntity = null;
    private ?Scene $scene = null;

    public bool $disableStick = false ;

    /** Boss arena: SMS camera position [x, y] (top-left of the original 256x192 view), centred in our wider view. */
    public ?array $lock = null;

    /** Part of the current camera Y that comes from Sonic looking up / down. */
    private float $appliedLook = 0.0;

    // Original screen size: the level header limits are camera positions for a 256x192 view.
    public const SMS_WIDTH = 256;
    public const SMS_HEIGHT = 192;

    /** Camera limits of the level header (RAM_LEVEL_LEFT/RIGHT/TOP/BOTTOM), changed by auto-scrolling. */
    private array $limits = [0, PHP_INT_MAX, 0, PHP_INT_MAX];
    private int $headerRight = PHP_INT_MAX;
    private int $headerTop = 0;
    /** Header limits as loaded, before the auto-scrolling / boss locks change them. */
    private array $headerLimits = [0, PHP_INT_MAX, 0, PHP_INT_MAX];
    /** Level header SR (scrolling / rings) and TL (time / lightning) flags. */
    private int $scrollFlags = 0;
    private int $timeFlags = 0;
    private float $frameClock = 0;
    private int $frames = 0;
    // Sky Base 2 wave (_3164): camera Y offset with a bang-bang acceleration of 2/256 px/frame²
    private float $wave = 0;
    private float $waveSpeed = 0;
    private float $waveAccel = -2 / 256;
    private float $appliedWave = 0;
    private int $viewWidth = self::SMS_WIDTH;

    /**
     * Level start (header bytes 5-12, SR, TL): camera limits and scrolling mode.
     * Auto-scrolling levels start with the camera locked at (SX - 3) * 32 (lockCameraHorizontal).
     */
    public function startLevel(\SonicGame\Level\Level $level): void
    {
        $mapW = $level->getMapWidth() * 32;
        $mapH = $level->getMapHeight() * 32;
        $this->limits = [
            $level->getMeta('LEFT', 0),
            $level->getMeta('RIGHT', max(0, $mapW - self::SMS_WIDTH)),
            $level->getMeta('TOP', 0),
            $level->getMeta('BOTTOM', max(0, $mapH - self::SMS_HEIGHT)),
        ];
        $this->headerRight = $this->limits[1];
        $this->headerTop = $this->limits[2];
        $this->headerLimits = $this->limits;
        $this->scrollFlags = $level->getMeta('SR');
        $this->timeFlags = $level->getMeta('TL');
        $this->frames = 0;
        $this->frameClock = 0;
        $this->wave = $this->waveSpeed = $this->appliedWave = 0;
        $this->waveAccel = -2 / 256;
        $this->appliedLook = 0;
        if ($this->scrollFlags & 0x08) {
            $x = max($this->limits[0], min($this->limits[1], max(0, $level->getCurrentPositionSoniceXinTile() - 3) * 32));
            $this->limits[0] = $this->limits[1] = $x;
        }
    }

    /** One original frame of the level-wide scrolling effects (main loop + _3164). */
    private function frameTick(): void
    {
        $this->frames++;
        // bit 3: the left edge moves 1px every other frame and the right edge follows (Bridge act 2),
        // until our (wider) view reaches the right end of the level
        if (($this->scrollFlags & 0x08) && ($this->frames & 1) && $this->limits[0] < $this->headerRight + self::SMS_WIDTH - $this->viewWidth) {
            $this->limits[0]++;
            $this->limits[1] = $this->limits[0];
        }
        // bit 4: the bottom moves up 1px every other frame
        if (($this->scrollFlags & 0x10) && ($this->frames & 1) && $this->limits[3] > $this->headerTop) {
            $this->limits[3]--;
        }
        // bit 6: up and down wave
        if ($this->scrollFlags & 0x40) {
            $this->waveSpeed = max(-2, min(2, $this->waveSpeed + $this->waveAccel));
            $this->wave += $this->waveSpeed;
            if ($this->wave < -32) {
                $this->waveAccel = 2 / 256;
            } elseif ($this->wave < 0 || $this->wave >= 32) {
                $this->waveAccel = -2 / 256;
            }
        }
    }

    /** End sign: the left limit follows the camera (never goes back), the right one is sign X - $70. */
    public function applySignLimits(int $left, int $right): void
    {
        $this->limits[1] = max($this->limits[0], min($this->limits[1], $right));
        $this->limits[0] = max($this->limits[0], min($left, $this->limits[1]));
    }

    /** Range of our camera position: the header limits widened/centred for a view bigger than 256x192. */
    public function getViewBounds(int $winW, int $winH): array
    {
        $this->viewWidth = $winW;
        [$left, $right, $top, $bottom] = $this->limits;
        $minX = $left;
        $maxX = $right + self::SMS_WIDTH - $winW;
        if ($this->scrollFlags & 0x08) {
            // auto-scrolling: our view starts at the original left edge, which pushes Sonic
            $maxX = $minX;
        } elseif ($maxX < $minX) {
            $minX = $maxX = (int)floor(($left + $maxX) / 2);
        }
        $minY = $top;
        $maxY = $bottom + self::SMS_HEIGHT - $winH;
        if ($maxY < $minY) {
            $minY = $maxY = (int)floor(($top + $maxY) / 2);
        }
        return [$minX, $maxX, $minY, $maxY];
    }

    /**
     * Sonic is kept between LEFT + 8 and RIGHT + 248 - 24 (doObjectCode_Sonic). When auto-scrolling, the
     * right one is the right edge of our wider view.
     */
    public function getPlayerLimits(): array
    {
        if ($this->scrollFlags & 0x08) {
            return [$this->limits[0] + 8, $this->limits[0] + $this->viewWidth - 32];
        }
        return [$this->limits[0] + 8, $this->limits[1] + 248 - 24];
    }

    /**
     * Sonic dies once his top goes below the bottom of the original screen (_543c: camera Y + 192).
     * The original camera can always reach BOTTOM, except in "no scroll down" levels where BOTTOM is the
     * current camera: our camera centre stands for the centre of the 192px screen.
     */
    /**
     * World area the original could ever show: [left, right, top, bottom] = the header camera limits plus the
     * 256x192 screen. Our wider view masks what is outside (tiles the SMS never displays).
     */
    public function getVisibleArea(): array
    {
        [$left, $right, $top, $bottom] = $this->headerLimits;
        return [$left, $right + self::SMS_WIDTH, $top, $bottom + self::SMS_HEIGHT];
    }

    /** Wave offset of the scroll flag bit 6 ($D2A1), also followed by the Sky Base flying platforms. */
    public function getWave(): float
    {
        return $this->wave;
    }

    public function getDeathLine(int $winH): float
    {
        // boss arenas (_7c8c) also set the level bottom to the locked camera: Sonic dies just under the screen
        if ($this->lock !== null) {
            return $this->lock[1] + self::SMS_HEIGHT;
        }
        if ($this->scrollFlags & 0x80) {
            return $this->y - $this->appliedWave + $winH / 2 - self::SMS_HEIGHT / 2 + self::SMS_HEIGHT;
        }
        return $this->limits[3] + self::SMS_HEIGHT;
    }

    /**
     * Where the original camera (top-left of its 256x192 screen) would be: it keeps Sonic between
     * X + $60..$88 and Y + $60..$70 (_2f66), inside the header limits. Used for the object activity windows.
     */
    public function getRomCamera(float $sonicX, float $sonicY): array
    {
        [$left, $right, $top, $bottom] = $this->limits;
        return [
            max($left, min($right, $sonicX - 116)),
            max($top, min($bottom, $sonicY - 104)),
        ];
    }

    public function getScrollFlags(): int
    {
        return $this->scrollFlags;
    }

	public function update($deltaTime = 1)
	{
		$this->cameraCallback?->call($this,$deltaTime);

	}
    public function stickTo(\SonicGame\Entities\Entity $entity,$oneShoot = false,$centerEntity = false,$deltaTime = 1)
    {
        $this->stickedEntity = &$entity;
        $camera = $this ;

        // TODO : center Entity par example pour le level 19 => (fin de sonic)
        $callable = function($delta) use ($entity,$camera,$centerEntity)
        {
			//dump($delta);
            if ($this->disableStick)
                return ;

            $camera->frameClock += $delta;
            while ($camera->frameClock >= 1 / 60) {
                $camera->frameClock -= 1 / 60;
                $camera->frameTick();
            }
            // TL bit 6: the screen is locked
            if (($camera->timeFlags & 0x40) && !$this->noSmooth) {
                return;
            }

            $cameraLerpSpeedY = 10;
            $cameraLerpSpeedX = 10;

            $sonicX = $entity->getX();
            $sonicY = $entity->getY() + 32 ;

            $winH = Window::getInstance()->getHeight();
            $winW = Window::getInstance()->getWidth();

            // the dead-zone logic works on the camera position without the look offset and the wave
            $cameraY = $camera->getY() - $camera->appliedLook - $camera->appliedWave;
            $cameraX = $camera->getX();

            $targetCameraY = $cameraY;
            $targetCameraX = $cameraX;

            $camDeadZoneUp = $winH * 0.60;
            $camDeadZoneDown = $winH - ($winH * 0.35);

            $camDeadZoneLeft = $winW * 0.20;
            $camDeadZoneRight = $winW - ($winW * 0.35);

            $sonicScreenY = $sonicY - $cameraY;
            $sonicScreenX = $sonicX - $cameraX;

            if ($sonicScreenY <= $camDeadZoneUp) {
                $targetCameraY = ($sonicY - $camDeadZoneUp);
            }
            elseif ($sonicScreenY >= $camDeadZoneDown) {
                $targetCameraY = ($sonicY - $camDeadZoneDown);
            }

            if ($sonicScreenX <= $camDeadZoneLeft) {
                $targetCameraX = ($sonicX - $camDeadZoneLeft);
            }
            elseif ($sonicScreenX >= $camDeadZoneRight) {
                $targetCameraX = ($sonicX - $camDeadZoneRight);
            }


            if ($camera->lock !== null) {
                // [x, y, right]: free between x and right (Sky Base 3 escape), else on one position
                if (!isset($camera->lock[2])) $targetCameraX = $camera->lock[0] + 128 - $winW / 2;
                $targetCameraY = $camera->lock[1] + 96 - $winH / 2;
            }

            // bit 7: the screen never scrolls down (Jungle act 2)
            if (($camera->scrollFlags & 0x80) && !$this->noSmooth) {
                $targetCameraY = min($targetCameraY, $cameraY);
            }

            // limits of the level header
            [$minCameraX, $maxCameraX, $minCameraY, $maxCameraY] = $camera->getViewBounds($winW, $winH);
            if ($camera->lock !== null) {
                $minCameraX = $minCameraY = PHP_INT_MIN;
                $maxCameraX = $maxCameraY = PHP_INT_MAX;
                if (isset($camera->lock[2])) {
                    $minCameraX = $camera->lock[0];
                    $maxCameraX = $camera->lock[2] + self::SMS_WIDTH - $winW;
                    if ($maxCameraX < $minCameraX) $minCameraX = $maxCameraX = (int)floor(($minCameraX + $maxCameraX) / 2);
                }
            }
            $targetCameraX = max($minCameraX, min($maxCameraX, $targetCameraX));
            $targetCameraY = max($minCameraY, min($maxCameraY, $targetCameraY));
            // whole pixels: the steps below are rounded up to 1px, a fractional target (zoomed out view with an
            // odd size, centred on a boss arena) would make the camera shake 1px back and forth around it
            $targetCameraX = floor($targetCameraX);
            $targetCameraY = floor($targetCameraY);

            // looking up / down shifts the view (applied on top of the normal target)
            $baseTargetY = $targetCameraY;
            $look = method_exists($entity, 'getLookOffset') ? $entity->getLookOffset() : 0.0;
            $looking = $look != 0.0 || $camera->appliedLook != 0.0;
            if ($looking) {
                $targetCameraY = floor(max($minCameraY, min($maxCameraY, $baseTargetY + $look)));
                $cameraY += $camera->appliedLook;
            }

//			 $this->noSmooth = true ; // TODO voir pk sonic "tremble"
            if ($this->noSmooth == true) {
				$deltaY = ($targetCameraY - $cameraY);
				$deltaX = ($targetCameraX - $cameraX);
            }
            else {
				// at most the whole way: a long frame (> 1/10 s) would go past the target, and further each frame
				$deltaY = ($targetCameraY - $cameraY) * min(1, $cameraLerpSpeedY * $delta);
				$deltaX = ($targetCameraX - $cameraX) * min(1, $cameraLerpSpeedX * $delta);


				if ($deltaX > 0)
					$deltaX = ceil($deltaX);
				else
					$deltaX = floor($deltaX);

				if ($deltaY > 0)
					$deltaY = ceil($deltaY);
				else
					$deltaY = floor($deltaY);

				// bit 5: smooth scrolling, 1px per original frame at most
				if ($camera->scrollFlags & 0x20) {
					$step = max(1, 60 * $delta);
					$deltaX = max(-$step, min($step, $deltaX));
					$deltaY = max(-$step, min($step, $deltaY));
				}
            }


			$finalY = $cameraY + $deltaY ;
			$finalX = $cameraX + $deltaX ;
            if ($camera->lock === null) {
                $finalX = max($minCameraX, min($maxCameraX, $finalX));
            }

            // the wave is added on top, then the limits apply again (_2f66)
            $wavedY = (int) $finalY;
            if ($camera->scrollFlags & 0x40) {
                $wavedY = (int) max($minCameraY, min($maxCameraY, $finalY + round($camera->wave)));
            }
            $camera->setXY((int) $finalX, $wavedY);
            $camera->appliedWave = $wavedY - (int) $finalY;
            $camera->appliedLook = $looking ? (int) $finalY - $baseTargetY : 0.0;

			$this->noSmooth = false ;


        };

		$this->cameraCallback = $callable;

    }

    public function setScene(Scene $scene)
    {
        $this->scene = $scene ;
        $this->scene->getPlayer()->emit('positionChanged', [ $this->scene->getPlayer()->getX(), $this->scene->getPlayer()->getY() ] );
    }
}
