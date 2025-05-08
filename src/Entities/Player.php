<?php

namespace SonicGame\Entities;

use SonicGame\Utils\Vector;

class Player extends Entity
{
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

}

