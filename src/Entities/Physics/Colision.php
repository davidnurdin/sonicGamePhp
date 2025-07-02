<?php

namespace SonicGame\Entities\Physics;
trait Colision
{

	protected bool $grounded = false;

	// --- Collision ---
	public function setGrounded(bool $grounded)
	{
		$this->grounded = $grounded;
	}

	public function isGrounded(): bool
	{
		return $this->grounded;
	}

	public function update(float $deltaTime)
	{
		
		if ($this->grounded) // si on touche le sol, on annule vitesse et acceleration
		{
			$this->ay = 0 ;
			$this->vy = 0;
		}

	}

	public function getCollisionRect(): array
	{

		return [
			'x' => $this->getX(),
			'y' => $this->getY(),
			'width' => $this->width,
			'height' => $this->height
		];
	}

}
