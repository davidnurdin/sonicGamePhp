<?php

namespace SonicGame\Loop;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

class GameLoop
{

    public function __construct(private ?LoopInterface $loop = null)
    {
        $this->init();
    }

    public function addPeriodicTimer(float|int $frameDuration, \Closure $closure): void
    {
        $this->loop->addPeriodicTimer($frameDuration, $closure);
    }

    public function run()
    {
        $this->loop->run();
    }

    public function init()
    {
        if (!$this->loop) {
            $this->loop = Loop::get(); // récupère la boucle unique de ReactPHP
        }
    }
}
