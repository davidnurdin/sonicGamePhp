<?php

namespace SonicGame\Entities;

use SonicGame\Utils\Vector;

class Player
{
    use Vector;

    public function moveUp()
    {
        $this->y -= 1;
    }
    public function moveDown()
    {
        $this->y += 1;
    }
    public function moveLeft()
    {
        $this->x -= 1;
    }
    public function moveRight()
    {
        $this->x += 1;
    }

    public function getX()
    {
        return $this->x;
    }

    public function getY()
    {
        return $this->y;
    }


}

