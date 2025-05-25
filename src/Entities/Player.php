<?php

namespace SonicGame\Entities;

use SonicGame\Renderer\Sdl;
use SonicGame\Utils\Vector;

class Player extends Sprite
{

    public function moveUp(float $deltaTime = 1)
    {
        $this->setY($this->getY() - 5* $deltaTime);
    }
    public function moveDown(float $deltaTime = 1)
    {
        $this->setY($this->getY() + 5 * $deltaTime);
    }
    public function moveLeft(float $deltaTime = 1)
    {
        $this->setX($this->getX() - 5 * $deltaTime);
    }
    public function moveRight(float $deltaTime = 1)
    {
        $this->setX($this->getX() + 5 * $deltaTime);
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

	public function initTexture(Sdl $sdl)
	{
		$this->sdl = $sdl;
		$this->tilesetName = 'sonic' ;
		$sdl->loadTexture($this->tilesetName, 'tileset/sprites/tileset-sonic.png',['r' => 0 , 'g' => '72' , 'b' => 0]);
	}



}

