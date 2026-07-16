<?php

namespace ReactphpX\S3;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class Poll
{
    private LoopInterface $loop;
    private int $workInProgress = 0;
    private ?TimerInterface $workInProgressTimer = null;
    private float $workInterval = 0.01;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function activate(): void
    {
        if ($this->workInProgress++ === 0) {
            $this->schedulePoll();
        }
    }

    private function schedulePoll(): void
    {
        $this->workInProgressTimer = $this->loop->addPeriodicTimer($this->workInterval, function () {
            $this->runQueue();
        });

        $this->loop->futureTick(function () {
            if ($this->workInProgress > 0) {
                $this->runQueue();
            }
        });
    }

    private function runQueue(): void
    {
        if (!\GuzzleHttp\Promise\Utils::queue()->isEmpty()) {
            \GuzzleHttp\Promise\Utils::queue()->run();
        }
    }

    public function deactivate(): void
    {
        if (--$this->workInProgress <= 0) {
            $this->loop->cancelTimer($this->workInProgressTimer);
            $this->workInProgressTimer = null;
            $this->workInProgress = 0;
        }
    }
}
