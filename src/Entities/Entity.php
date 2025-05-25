<?php

namespace SonicGame\Entities;

use Evenement\EventEmitter;
use SonicGame\Utils\Vector;

class Entity extends EventEmitter
{
    use Vector;

    // --- Dynamique --- Vitesses, Accélérations, Friction ---
    protected float $vx = 0.0;
    protected float $vy = 0.0;
    protected float $ax = 0.0;
    protected float $ay = 0.0;
    protected float $friction = 1.0;

    // --- Orientation et état ---
    protected string $facing = 'right'; // ou 'left'
    protected string $state = 'idle';



    // --- Vitesse ---
    public function setVelocity(float $vx, float $vy)
    {
        $this->vx = $vx;
        $this->vy = $vy;
    }

    public function getVelocity(): array
    {
        return [$this->vx, $this->vy];
    }

    // --- Accélération ---
    public function setAcceleration(float $ax, float $ay)
    {
        $this->ax = $ax;
        $this->ay = $ay;
    }

    public function getAcceleration()
    {
        return ['x' => $this->ax, 'y' => $this->ay];
    }

    // --- Friction ---
    public function setFriction(float $friction)
    {
        $this->friction = $friction;
    }

    // --- Facing ---
    public function setFacing(string $dir)
    {
        if (!in_array($dir, ['left', 'right'])) {
            throw new \InvalidArgumentException("Facing direction '$dir' is invalid.");
        }
        $this->facing = $dir;
    }

    public function getFacing(): string
    {
        return $this->facing;
    }

    // --- State ---
    public function setState(string $state)
    {
        $this->state = $state;
    }

    public function getState(): string
    {
        return $this->state;
    }

    // --- Update global ---
    public function update(float $deltaTime)
    {
        // Applique accélération
        $this->vx += $this->ax * $deltaTime;
        $this->vy += $this->ay * $deltaTime;

        if (abs($this->vx) < 0.01) {
            $this->vx = 0;
        }
        if (abs($this->vy) < 0.01) {
            $this->vy = 0;
        }

        // Applique friction
        $this->vx *= $this->friction;
        $this->vy *= $this->friction;

        // Met à jour la position
        $this->setX($this->getX() + $this->vx * $deltaTime);
        $this->setY($this->getY() + $this->vy * $deltaTime);
        $this->setAnimation($this->state . ucfirst($this->facing));
    }


    /**
     * Retourne la vitesse instantanée en X (pixels/s).
     */
    public function getSpeedX(): float
    {
        return $this->vx;
    }

    /**
     * Retourne la vitesse instantanée en Y (pixels/s).
     */
    public function getSpeedY(): float
    {
        return $this->vy;
    }

    /**
     * Retourne la vitesse scalaire (norme du vecteur vitesse).
     */
    public function getSpeed(): float
    {
        return sqrt($this->vx ** 2 + $this->vy ** 2);
    }

}
