<?php

namespace SonicGame\Utils;

trait Vector
{
    public function __construct(protected int $x = 0, protected int $y = 0)
    {
    }

    public function setXY(int $x, int $y): void
    {
        $this->x = $x;
        $this->y = $y;
        if (is_callable([$this, 'emit'])) {
            $this->emit('positionChanged', [$this->x, $this->y]);
        }
    }

    public function getX(): int
    {
        return $this->x;
    }

    public function getY(): int
    {
        return $this->y;
    }

    public function setX(int $x): void
    {
        $this->x = $x;
        if (is_callable([$this, 'emit'])) {
            $this->emit('positionChanged', [$this->x, $this->y]);
        }
    }

    public function setY(int $y): void
    {
        $this->y = $y;
        if (is_callable([$this, 'emit'])) {
            $this->emit('positionChanged', [$this->x, $this->y]);
        }
    }


}
