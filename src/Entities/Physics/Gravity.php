<?php

namespace SonicGame\Entities\Physics;
trait Gravity
{

	protected float $gravity = 1400.0;
	protected bool $applyGravity = true;
	protected float $maxFallSpeed = 960.0;

	public function update(float $deltaTime)
	{
		if (($this->applyGravity) && (!$this->isGrounded()))
		{
			$this->ay = $this->gravity;

			if ($this->vy > $this->maxFallSpeed) {
				$this->vy = $this->maxFallSpeed;
				$this->ay = 0;
			}
		}
	}

	public function setGravity(float $gravity)
	{
		$this->gravity = $gravity;
	}

	public function getGravity(): float
	{
		return $this->gravity;
	}

	public function setApplyGravity(bool $apply)
	{
		$this->applyGravity = $apply;
	}

}
