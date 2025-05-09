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

    public function start($maxTicks = -1)
    {
        $tickCount = 0;

        if ($maxTicks > 0) {
            $this->loop->futureTick(function () use (&$tickCount, $maxTicks, &$loop) {
                $tick = function () use (&$tickCount, $maxTicks, &$tick) {
                    //                echo "Tick #{$tickCount}\n";
                    $tickCount++;
                    if ($tickCount < $maxTicks) {
                        Loop::futureTick($tick);
                    } else {
                        //                    echo "Max ticks reached. Stopping loop.\n";
                            $this->loop->stop();
                    }
                };
                $tick(); // premier appel
            });
        }


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
