<?php

namespace SonicGame\Entities;

use SonicGame\Utils\Vector;

class Player extends Entity
{
    public function moveUp()
    {
        $this->setY($this->getY() - 1);
    }
    public function moveDown()
    {
        $this->setY($this->getY() + 1);
    }
    public function moveLeft()
    {
        $this->setX($this->getX() - 1);
    }
    public function moveRight()
    {
        $this->setX($this->getX() + 1);
    }

}

