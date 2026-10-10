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

	protected int $width = 23;
	protected int $height = 32;

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
        $this->vx += $this->ax * $deltaTime;
        $this->vy += $this->ay * $deltaTime;

        $this->vx *= $this->friction;

        $this->x += $this->vx * $deltaTime;
        $this->y += $this->vy * $deltaTime;

        $animName = $this->state . ucfirst($this->facing);
        if (method_exists($this, 'hasAnimation') && !$this->hasAnimation($animName)) {
            $animName = $this->state;
        }
        $this->setAnimation($animName);
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
