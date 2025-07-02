<?php

namespace SonicGame\Entities;

use SonicGame\Entities\Physics\Colision;
use SonicGame\Entities\Physics\Gravity;
use SonicGame\Renderer\Sdl;
use SonicGame\Utils\Vector;

class Player extends Entity
{


	use Sprite {
		Sprite::update as updateSprite; // Crée un alias interne
	}

	use Gravity {
		Gravity::update as updateGravity;
	}

	use Colision {
		Colision::update as updateColision;
	}


	public $isMovingFromInput = false ;


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

	public function update(float $deltaTime)
	{

		if (!$this->isMovingFromInput)
			$this->idle();

		$this->isMovingFromInput = false ;
		// call the trait Sprite update function
        //$this->updateColision($deltaTime);
		$this->updateGravity($deltaTime);
        $this->updateColision($deltaTime);
		$this->updateSprite($deltaTime);
	}

    public function moveDirection(string $direction, float $deltaTime = 1)
    {
		$this->isMovingFromInput = true ;

//		$deltaTime *= 100 ;
//		dump($deltaTime);
//		$deltaTime = 7 ; // debug wasm

        // get current speed
        $currentSpeed = $this->getSpeedX() ;

//        dump('Move : ' . $direction . ' - Current Speed : ' . $currentSpeed . ' / Delta Time : ' . $deltaTime);

        if ( ($this->getState() == 'run') || ($this->getState() == 'stopRun'))
        {
            // si on courrais à droite et qu'on va subitement a gauche on fait le stopWalk
            if ($currentSpeed > 0 && $direction == 'left') {
                    $this->setFacing('right');
                    $this->setState('stopRun');
                    $this->setFriction(0.95);
                    $this->setAcceleration(-1000 * $deltaTime, 0); // Accélération de 100 px/s² vers la gauche
                return;
            }

            // si on courrais à gauche et qu'on va subitement a droite on fait le stopWalk
            if ($currentSpeed < 0 && $direction == 'right') {
                    $this->setFacing('left');
                    $this->setState('stopRun');
                    $this->setFriction(0.95);
                    $this->setAcceleration(1000 * $deltaTime, 0); // Accélération de 100 px/s² vers la droite
                return;
            }
        }

        $this->setFriction(1);
        $factor = 1 ;
        if ($direction == 'left')
            $factor = -1;


		// set initial speed (from 0) (evite l'effet de patinage au démarrage)
		if ($direction == 'right') {
			if ($this->getVelocity()[0] < 100)
				$this->setVelocity($factor * 100 * $deltaTime, $this->vy);
		}
		elseif ($direction == 'left') {
			if ($this->getVelocity()[0] > -100)
				$this->setVelocity($factor * 100 * $deltaTime, $this->vy);
		}

        $this->setAcceleration($factor*1000*$deltaTime, 0); // Accélération de 100 px/s² vers la droite
        $this->setFacing($direction);
        $this->setState('walk');

        if ($this->getSpeed() > 300 * $deltaTime) {
            $this->setAcceleration($factor*1500 * $deltaTime,0);
            $this->setState('run');
            if ($this->getSpeed() > 500 * $deltaTime)
            {
                // run
                $this->setVelocity( $factor*500*$deltaTime, $this->vy); // Vitesse maximale de 100 px/s vers la droite
            }

        }

    }

    public function idle($deltaTime = 1)
    {
//		$deltaTime = 7 ; // debug wasm
        $currentSpeed = abs($this->getSpeedX()) ;
        $this->setFriction(0.95); // Réduit la friction pour ralentir le joueur
        $this->setAcceleration(0,0);


        if ($this->getState() === 'stopRun') {
            if ($currentSpeed < 150* $deltaTime)
                $this->setState('idle');
            return ;
        }



        if ($currentSpeed < 50* $deltaTime)
            $this->setState('idle');
        elseif ($currentSpeed <= 300* $deltaTime)
            $this->setState('walk');


    }

    public function move(string $dir,float $deltaTime = 1)
    {
		if ($dir === 'up') {
			$this->moveUp($deltaTime);
		} elseif ($dir === 'down') {
			$this->moveDown($deltaTime);
		} elseif ($dir === 'left') {
			$this->moveLeft($deltaTime);
		} elseif ($dir === 'right') {
			$this->moveRight($deltaTime);
		} else {
			throw new \InvalidArgumentException("Direction '$dir' is not valid.");

		}
    }

    public function roll()
    {
//        $this->setAnimation('rollRight');
//        $this->update(0.03);
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

		$this->setVelocity($this->vx, -400); // Force de saut vers le haut
//		$this->setGrounded(false);
		$this->setState('jump');

//        $this->setAnimation('jump');
//        $this->update(0.03);
	}

	public function initTexture(Sdl $sdl)
	{
		$this->sdl = $sdl;
		$this->tilesetName = 'sonic' ;
		$sdl->loadTexture($this->tilesetName, 'tileset/sprites/tileset-sonic.png',['r' => 0 , 'g' => '72' , 'b' => 0]);
	}

    public function watchUp()
    {
//        $this->setAnimation('watchUp');
//        $this->update(0.03);
    }
    public function watchDown()
    {
//        $this->setAnimation('watchDown');
//        $this->update(0.03);
    }



}

