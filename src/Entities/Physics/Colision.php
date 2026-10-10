<?php

namespace SonicGame\Entities\Physics;
trait Colision
{

	protected bool $grounded = false;
	protected float $groundedY = 0;
	protected bool $inWater = false;

	public function setGrounded(bool $grounded, float $groundY = null)
	{
		$this->grounded = $grounded;
		if ($grounded && $groundY !== null)
		{
			$this->groundedY = $groundY;
		}
	}

	public function isGrounded(): bool
	{
		return $this->grounded;
	}

	public function update(float $deltaTime)
	{
		if ($this->grounded)
		{
			$this->ay = 0;
			$this->vy = 0;
		}
	}

	public function setInWater(bool $inWater)
	{
		$this->inWater = $inWater;
	}

	public function isInWater(): bool
	{
		return $this->inWater;
	}

	public function getCollisionRect(): array
	{
		return [
			'x' => $this->x,
			'y' => $this->y,
			'width' => $this->width,
			'height' => $this->height
		];
	}

}
