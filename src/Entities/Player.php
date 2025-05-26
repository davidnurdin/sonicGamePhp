<?php

namespace SonicGame\Entities;

use SonicGame\Renderer\Sdl;
use SonicGame\Utils\Vector;

class Player extends Entity
{

    use Sprite ;

    protected array $animations = [
        'idleRight' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*9,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
            ]
        ],
        'idleLeft' => 'reverseIdleRight',
        'walkRight' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*0,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*1,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*2,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*3,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*4,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*5,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
            ]

        ],
        'walkLeft' => 'reverseWalkRight',
        'runRight' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*11,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*12,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*13,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*14,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
            ]
        ],
        'runLeft' => 'reverseRunRight',
        'rollRight' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*0,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*1,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*2,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*3,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
            ]
        ],
        'rollLeft' => 'reverseRollRight',
        'watchUp' => [

            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*10,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
            ]

        ] ,
        'watchDown' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*15,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
            ]
        ],
        'jump' => [

            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*4,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],

            ]
        ],
        'stopRunRight' => [

            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*5,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*6,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],

            ],
        ],
        'stopRunLeft' => 'reverseStopRunRight',

    ];


    public function moveUp(float $deltaTime = 1)
    {
//        $this->setY($this->getY() - 5* $deltaTime);
    }
    public function moveDown(float $deltaTime = 1)
    {
//        $this->setY($this->getY() + 5 * $deltaTime);
    }
    public function moveLeft(float $deltaTime = 1)
    {
        // walk
        $this->moveDirection('left', $deltaTime);

    }
    public function moveRight(float $deltaTime = 1)
    {

        // walk
        $this->moveDirection('right', $deltaTime);

    }

    public function moveDirection(string $direction, float $deltaTime = 1)
    {

        $this->setFriction(1);
        $factor = 1 ;
        if ($direction == 'left')
            $factor = -1;

        $this->setAcceleration($factor*1000, 0); // Accélération de 100 px/s² vers la droite
        $this->setFacing($direction);
        $this->setState('walk');

        if ($this->getSpeed() > 300) {
            $this->setAcceleration($factor*1500,0);
            $this->setState('run');
            if ($this->getSpeed() > 500)
            {
                // run
                $this->setVelocity( $factor*500, $this->vy); // Vitesse maximale de 100 px/s vers la droite
            }

        }

    }

    public function idle()
    {
        // TODO : voir pourquoi la friction ne fonctionne pas pareil a gauche  & droite
        var_dump($this->getSpeedX());
        $this->setAnimation('idle' . ucfirst($this->getFacing()));
//        $this->setAcceleration(-$this->getSpeedX(), 0); // Arrête l'accélération
        $this->setAcceleration(0,0);
//        $this->setVelocity(0, 0); // Arrête la vitesse
            $this->setFriction(0.95); // Réduit la friction pour ralentir le joueur
        $this->setState('idle'); // Met l'état à idle
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

    public function roll()
    {
        $this->setAnimation('rollRight');
        $this->update(0.03);
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
        $this->setAnimation('jump');
        $this->update(0.03);
	}

	public function initTexture(Sdl $sdl)
	{
		$this->sdl = $sdl;
		$this->tilesetName = 'sonic' ;
		$sdl->loadTexture($this->tilesetName, 'tileset/sprites/tileset-sonic.png',['r' => 0 , 'g' => '72' , 'b' => 0]);
	}

    public function watchUp()
    {
        $this->setAnimation('watchUp');
        $this->update(0.03);
    }
    public function watchDown()
    {
        $this->setAnimation('watchDown');
        $this->update(0.03);
    }



}

