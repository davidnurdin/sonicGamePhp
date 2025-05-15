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

    public function stickTo(\SonicGame\Entities\Entity $entity)
    {
        $this->stickedEntity = &$entity;
        $camera = $this ;

        // TODO : voir comment calculer le frameDuration et le $delta ... pour avoir un mouvement fluide quelque soit le framerate.
        $frameDuration = 1 / 120; // 60Hz

        GameLoop::addPeriodicTimer($frameDuration, function() use ($entity,$camera)
        {
            $cameraLerpSpeedY = 3.0;
            $cameraLerpSpeedX = 3.0;
            $delta = 0.01 ; // TODO le calculé grace a GameLoop

            $sonicX = $entity->getX();
            $sonicY = $entity->getY() + 32 ;

            $winH = Window::getInstance()->getHeight();
            $winW = Window::getInstance()->getWidth();

            $cameraY = $camera->getY();
            $cameraX = $camera->getX();

            $targetCameraY = (int) $cameraY;
            $targetCameraX = (int) $cameraX;

            $camDeadZoneUp = $winH * 0.40;
            $camDeadZoneDown = $winH - ($winH * 0.35);

            $camDeadZoneLeft = $winW * 0.25;
            $camDeadZoneRight = $winW - ($winW * 0.35);

            $sonicScreenY = $sonicY - $cameraY;
            $sonicScreenX = $sonicX - $cameraX;

            if ($sonicScreenY <= $camDeadZoneUp) {
                $targetCameraY = (int) ($sonicY - $camDeadZoneUp);
            }
            elseif ($sonicScreenY >= $camDeadZoneDown) {
                $targetCameraY = (int) ($sonicY - $camDeadZoneDown);
            }

            if ($sonicScreenX <= $camDeadZoneLeft) {
                $targetCameraX = (int) ($sonicX - $camDeadZoneLeft);
            }
            elseif ($sonicScreenX >= $camDeadZoneRight) {
                $targetCameraX = (int) ($sonicX - $camDeadZoneRight);
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



//            $finalY = $cameraY + ($targetCameraY - $cameraY) ;//  * min($cameraLerpSpeedY * $delta, 1.0) ;
//            $finalX = $cameraX + ($targetCameraX - $cameraX) ;//* min($cameraLerpSpeedX * $delta, 1.0) ;

            $finalY = $cameraY + ($targetCameraY - $cameraY) * min($cameraLerpSpeedY * $delta, 1.0) ;
            $finalX = $cameraX + ($targetCameraX - $cameraX) * min($cameraLerpSpeedX * $delta, 1.0) ;


            $camera->setXY($finalX, $finalY);



        }) ;


        $entity->on('positionChanged', function($sonicX, $sonicY) use ($camera,$entity) {


//

        });

    }

    public function setScene(Scene $scene)
    {
        $this->scene = $scene ;
        $this->scene->getPlayer()->emit('positionChanged', [ $this->scene->getPlayer()->getX(), $this->scene->getPlayer()->getY() ] );
    }
}
