<?php

namespace SonicGame\Scene;

use SonicGame\Entities\Entity;
use SonicGame\Utils\Vector;

class Camera
{
    use Vector ;

    private ?Entity $stickedEntity = null;
    public function stickTo(\SonicGame\Entities\Entity $entity)
    {
        $this->stickedEntity = &$entity;
        $camera = $this ;
        $entity->on('positionChanged', function($x, $y) use ($camera) {
            $camera->setX($x);
            $camera->setY($y);
        });

    }
}
