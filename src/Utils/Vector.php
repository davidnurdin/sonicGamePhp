<?php

namespace SonicGame\Utils;

trait Vector
{
    public function __construct(protected float $x = 0, protected float $y = 0)
    {
    }

    public function setXY(float $x, float $y): void
    {
        $this->x = $x;
        $this->y = $y;
        if (is_callable([$this, 'emit'])) {
            $this->emit('positionChanged', [$this->x, $this->y]);
        }
    }

    public function getX(): int
    {
        return (int)round($this->x);
    }

    public function getY(): int
    {
        return (int)round($this->y);
    }

    public function setX(float $x): void
    {
        $this->x = $x;
        if (is_callable([$this, 'emit'])) {
            $this->emit('positionChanged', [$this->x, $this->y]);
        }
    }

    public function setY(float $y): void
    {
        $this->y = $y;
        if (is_callable([$this, 'emit'])) {
            $this->emit('positionChanged', [$this->x, $this->y]);
        }
    }

}
