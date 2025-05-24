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

    public function move(string $dir)
    {
		if ($dir === 'up') {
			$this->moveUp();
		} elseif ($dir === 'down') {
			$this->moveDown();
		} elseif ($dir === 'left') {
			$this->moveLeft();
		} elseif ($dir === 'right') {
			$this->moveRight();
		} else {
			throw new \InvalidArgumentException("Direction '$dir' is not valid.");

		}
    }

	public function action($action)
	{
		if ($action === 'jump') {
			$this->jump();
		} elseif ($action === 'roll') {
			$this->roll();
		} else {
			throw new \InvalidArgumentException("Action '$action' is not valid.");
		}
	}
	public function jump()
	{
		dump('JUUUMP!!!');
	}
	public function roll()
	{
		dump('ROLLL!!!');
	}


}

