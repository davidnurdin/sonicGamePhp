<?php

namespace SonicGame\Loop;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

class GameLoop
{

    public function __construct(private ?LoopInterface $loop)
    {
        $this->init();
    }

    public function addPeriodicTimer(float|int $frameDuration, \Closure $closure): void
    {
        $this->loop->addPeriodicTimer($frameDuration, $closure);
    }

    public function start()
    {
        $this->loop->run();
    }

    public function init()
    {
        if (!$this->loop) {
            $this->loop = Loop::get(); // récupère la boucle unique de ReactPHP
        }
    }

    public function stop()
    {
        $this->loop->stop();
    }
}
