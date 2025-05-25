<?php

namespace SonicGame\Entities;

use SonicGame\Renderer\Sdl;

class Sprite extends Entity
{
	protected string $currentAnimation = 'idle';
	protected array $animations = [
		'idle' => [
			[
				'x' => 3 * 23*10,
				'y' => 3,
				'w' => 23,
				'h' => 32,
			],
		]
	];
	protected int $frameIndex = 0;
	protected float $frameTimer = 0;
	protected float $frameDuration = 0.1; // 100ms par frame
	protected bool $loop = true;
	protected $tilesetName ;

	public function __construct(private Sdl $sdl)
	{
		parent::__construct(0,0);
		$this->setAnimation('idle');
	}

	public function setAnimation(string $animation)
	{
		if (!array_key_exists($animation, $this->animations)) {
			throw new \InvalidArgumentException("Animation '$animation' is not valid.");
		}
		if ($this->currentAnimation !== $animation) {
			$this->currentAnimation = $animation;
			$this->frameIndex = 0;
			$this->frameTimer = 0;
		}
	}

	public function getRectAnimation()
	{
		$srcRect = new \SDL_Rect;
		$animation = $this->getCurrentFrame();
		$srcRect->x = $animation['x'];
		$srcRect->y = $animation['y'];
		$srcRect->w = $animation['w'];
		$srcRect->h = $animation['h'];

		return $srcRect ;
	}

	public function draw(\SDL_Rect $destRect)
	{
		$srcRect = $this->getRectAnimation();
		$sonicTexture = $this->getTexture();

		// with api native sdl
		\SDL_RenderCopyEx(
			$this->sdl->getRenderer()->getRenderer(),
			$sonicTexture['texture'],
			$srcRect,
			$destRect,
			0,
			null,
			\SDL_FLIP_NONE
		);
	}

	public function getAnimation(): string
	{
		return $this->currentAnimation;
	}

	public function update(float $deltaTime)
	{
		$this->frameTimer += $deltaTime;
		if ($this->frameTimer >= $this->frameDuration) {
			$this->frameTimer -= $this->frameDuration;
			$this->frameIndex++;

			if ($this->frameIndex >= count($this->animations[$this->currentAnimation])) {
				if ($this->loop) {
					$this->frameIndex = 0;
				} else {
					$this->frameIndex = count($this->animations[$this->currentAnimation]) - 1;
				}
			}
		}
	}

	public function getCurrentFrame(): string
	{
		return $this->animations[$this->currentAnimation][$this->frameIndex];
	}

	public function getTexture()
	{
		return  $this->sdl->getTextures($this->tilesetName)[0] ;
	}

}
