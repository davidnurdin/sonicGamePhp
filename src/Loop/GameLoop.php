<?php

namespace SonicGame\Loop;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

class GameLoop
{

    private array $eachTicks = [] ;

    public function __construct(private ?LoopInterface $loop)
    {
        $this->init();
    }

    public static function nextTick(\Closure $closure): void
    {
        Loop::futureTick($closure);

    }

    public static function addTimer(float|int $frameDuration, \Closure $closure): void
    {
        Loop::addTimer($frameDuration, $closure);
    }

    public static function addPeriodicTimer(float|int $frameDuration, \Closure $closure): void
    {
        Loop::addPeriodicTimer($frameDuration, $closure);
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

    public function deleteEachTick(string $eachTickId)
    {
        if (isset($this->eachTicks[$eachTickId])) {
            unset($this->eachTicks[$eachTickId]);
        }
    }

    public function pauseEachTick(string $eachTickId)
    {
        if (isset($this->eachTicks[$eachTickId])) {
            $this->eachTicks[$eachTickId] = false;
        }
    }

    public function resumeEachTick(string $eachTickId)
    {
        if (isset($this->eachTicks[$eachTickId])) {
            $this->eachTicks[$eachTickId] = true;
        }
    }

    public function eachTick(\Closure $closure)
    {
        $eachTickId = uniqid('eachTick_', true);
        $this->eachTicks[$eachTickId] = true ;

        $loop = Loop::get();
        $loop->futureTick($tickFunction = function () use (&$tickFunction, $loop,$closure,$eachTickId) {
            $closure();
            // Re-planifie pour le tick suivant
            if ($this->eachTicks[$eachTickId])
                $loop->futureTick($tickFunction);
        });

        return $eachTickId ;
    }
}
