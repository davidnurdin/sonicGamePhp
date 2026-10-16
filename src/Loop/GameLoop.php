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

    /** start(): ticks of the loop left before it stops. */
    private int $ticksLeft = 0;
    private ?\Closure $countTick = null;

    public function start($maxTicks = -1)
    {
        if ($maxTicks > 0) {
            // one callback made once: a closure re-planning itself (use (&$tick)) is a reference cycle,
            // one per frame in the browser, left to the garbage collector (memory growing, then a long collection)
            $this->ticksLeft = $maxTicks;
            $this->countTick ??= function (): void {
                if (--$this->ticksLeft > 0) {
                    Loop::futureTick($this->countTick);
                } else {
                    $this->loop->stop();
                }
            };
            $this->loop->futureTick($this->countTick);
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
