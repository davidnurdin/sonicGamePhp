<?php

namespace SonicGame\Scene;

use SonicGame\Entities\Entity;
use SonicGame\Loop\GameLoop;
use SonicGame\Renderer\Window;
use SonicGame\Utils\Vector;

class Camera
{
    use Vector ;

    private ?Entity $stickedEntity = null;
    private ?Scene $scene = null;

    public bool $disableStick = false ;

    public function stickTo(\SonicGame\Entities\Entity $entity,$oneShoot = false,$centerEntity = false)
    {
        $this->stickedEntity = &$entity;
        $camera = $this ;

        // TODO : voir comment calculer le frameDuration et le $delta ... pour avoir un mouvement fluide quelque soit le framerate.
        $frameDuration = 1 / 120; // 60Hz

        $noSmooth = false ;

        if ($oneShoot)
            $noSmooth = true ;

        // TODO : center Entity par example pour le level 19 => (fin de sonic)
        $that = &$this ;
        $callable = function() use ($entity,$camera,$noSmooth,$centerEntity,$that)
        {
            if ($that->disableStick)
                return ;

            $cameraLerpSpeedY = 3.0;
            $cameraLerpSpeedX = 3.0;
            $delta = 0.01 ; // TODO le calculé grace a GameLoop

            $sonicX = $entity->getX();
            $sonicY = $entity->getY() + 32 ;

            $winH = Window::getInstance()->getHeight();
            $winW = Window::getInstance()->getWidth();

            $cameraY = $camera->getY();
            $cameraX = $camera->getX();

            $targetCameraY = $cameraY;
            $targetCameraX = $cameraX;

            $camDeadZoneUp = $winH * 0.40;
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


            if ($noSmooth == true) {
                $finalY = $cameraY + ($targetCameraY - $cameraY);//  * min($cameraLerpSpeedY * $delta, 1.0) ;
                $finalX = $cameraX + ($targetCameraX - $cameraX);//* min($cameraLerpSpeedX * $delta, 1.0) ;
            }
            else {
                $finalY = $cameraY + ($targetCameraY - $cameraY) * min($cameraLerpSpeedY * $delta, 1.0);
                $finalX = $cameraX + ($targetCameraX - $cameraX) * min($cameraLerpSpeedX * $delta, 1.0);
            }

            $camera->setXY((int) $finalX, (int) $finalY);



        };

        if ($oneShoot === false)
            GameLoop::addPeriodicTimer($frameDuration, $callable);
        else {
            GameLoop::nextTick($callable);
        }

    }

    public function setScene(Scene $scene)
    {
        $this->scene = $scene ;
        $this->scene->getPlayer()->emit('positionChanged', [ $this->scene->getPlayer()->getX(), $this->scene->getPlayer()->getY() ] );
    }
}
