<?php

namespace SonicGame\Entities;

use SonicGame\Renderer\Sdl;

trait Sprite
{
	protected string $currentAnimation = 'idle';
	protected int $frameIndex = 0;
	protected float $frameTimer = 0;
	protected float $frameDuration = 0.1; // 100ms par frame
	protected bool $loop = true;
	protected $tilesetName ;

	public function __construct(private Sdl $sdl)
	{
        if (!property_exists($this,'animations') || (count($this->animations) === 0)) {
            throw new \InvalidArgumentException("Animations array must be defined in the class using the Sprite trait.");
        }

		parent::__construct(0,0);
		$this->setAnimation('idleRight');

        // traite le tableau des animations afin de trouver tt les valeurs qui commence par "reverse"
        foreach ($this->animations as $name => $frames) {
            if (is_string($frames) && strpos($frames, 'reverse') === 0) {
                // utilise substr pour obtenir le nom de l'animation sans le préfixe "reverse" et en enlevant la premiere majuscule
                $this->animations[$name] = $this->animations[strtolower(substr($frames, 7,1)) . substr($frames, 8)];
                // ajoute un flag pour savoir qu'on va faire un flip dans chacune des frames
                $this->animations[$name]['flags']['flip'] = true ;
            }
        }
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
        // applique la physique
        $srcRect = $this->getRectAnimation();
		$sonicTexture = $this->getTexture();
        $animation = $this->getCurrentAnimation();
        if (isset($animation['flags']['flip']) && $animation['flags']['flip']) {
            // Si le frame a le flag flip, on doit faire un flip horizontal
            $flip = \SDL_FLIP_HORIZONTAL;
        } else {
            $flip = \SDL_FLIP_NONE;
        }

		// with api native sdl
		\SDL_RenderCopyEx(
			$this->sdl->getRenderer()->getRenderer(),
			$sonicTexture['texture'],
			$srcRect,
			$destRect,
			0,
			null,
            $flip
		);
	}

	public function getAnimation(): string
	{
		return $this->currentAnimation;
	}

	public function update(float $deltaTime)
	{
        // call the parent
        parent::update($deltaTime);

		$this->frameTimer += $deltaTime;
		if ($this->frameTimer >= $this->frameDuration) {
			$this->frameTimer -= $this->frameDuration;
			$this->frameIndex++;

			if ($this->frameIndex >= count($this->animations[$this->currentAnimation]['coords'])) {
				if ($this->loop) {
					$this->frameIndex = 0;
				} else {
					$this->frameIndex = count($this->animations[$this->currentAnimation]['coords']) - 1;
				}
			}
		}
	}

    public function getCurrentAnimation()
    {
        return $this->animations[$this->currentAnimation] ;
    }
	public function getCurrentFrame(): array
	{
		return $this->getCurrentAnimation()['coords'][$this->frameIndex];
	}

	public function getTexture()
	{
		return  $this->sdl->getTextures($this->tilesetName)[0] ;
	}

}
