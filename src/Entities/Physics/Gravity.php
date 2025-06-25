<?php

namespace SonicGame\Entities\Physics;
trait Gravity
{

	// --- Gravité et collision ---
	protected float $gravity = 980.0; // pixels/s² (équivalent à 9.8m/s² si 1 pixel = 1cm)
	protected bool $applyGravity = true;
	protected float $maxFallSpeed = 600.0; // Vitesse de chute maximale


	// --- Gravité ---
	public function update(float $deltaTime)
	{
		// Applique la gravité si activée et pas au sol
		if (($this->applyGravity) && (!$this->isGrounded()))
		{
			$this->ay += $this->gravity ; // Accélération due à la gravité
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
