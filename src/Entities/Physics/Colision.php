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
//		dump($this->grounded);
//		dump('apply colision grounded?');
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
