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

            $cameraLerpSpeedY = 70;
            $cameraLerpSpeedX = 70;

            $sonicX = $entity->getX();
            $sonicY = $entity->getY() + 32 ;

            $winH = Window::getInstance()->getHeight();
            $winW = Window::getInstance()->getWidth();

            $cameraY = $camera->getY();
            $cameraX = $camera->getX();

            $targetCameraY = $cameraY;
            $targetCameraX = $cameraX;

            $camDeadZoneUp = $winH * 0.60;
            $camDeadZoneDown = $winH - ($winH * 0.35);

            $camDeadZoneLeft = $winW * 0.45;
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


            // si on dépasse les limite du niveau on recentre
            if ($targetCameraY < 0) {
                $targetCameraY = 0;
            }

            if ($targetCameraX < 0) {
                $targetCameraX = 0;
            }

            $mapHeight = $camera->scene->getCurrentLevel()?->getMapHeight();
            $mapWidth = $camera->scene->getCurrentLevel()?->getMapWidth();

            $maxCameraY = ($mapHeight * 32) - $winH;
            $maxCameraX = ($mapWidth * 32) - $winW;

            if ($targetCameraY > $maxCameraY) {
                $targetCameraY = $maxCameraY;
            }
            if ($targetCameraX > $maxCameraX) {
                $targetCameraX = $maxCameraX;
            }


            if ($this->noSmooth == true) {
                $finalY = $cameraY + ($targetCameraY - $cameraY);
                $finalX = $cameraX + ($targetCameraX - $cameraX);
            }
            else {
                $finalY = $cameraY + ($targetCameraY - $cameraY) * ($cameraLerpSpeedY * $delta);
                $finalX = $cameraX + ($targetCameraX - $cameraX) * ($cameraLerpSpeedX * $delta);
            }

            $camera->setXY((int) $finalX, (int) $finalY);



        };

		$this->cameraCallback = $callable;

    }

    public function setScene(Scene $scene)
    {
        $this->scene = $scene ;
        $this->scene->getPlayer()->emit('positionChanged', [ $this->scene->getPlayer()->getX(), $this->scene->getPlayer()->getY() ] );
    }
}
