<?php

namespace ReactphpX\S3;

use Evenement\EventEmitter;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\Stream\WritableStreamInterface;

/**
 * Writable upload handle that closes when the remote upload completes.
 */
final class UploadStream extends EventEmitter implements WritableStreamInterface
{
    private bool $writable = true;
    private bool $closed = false;

    private ThroughStream $source;

    public function __construct()
    {
        $this->source = new ThroughStream();
        $this->source->on('drain', function (): void {
            $this->emit('drain');
        });
    }

    public function isWritable(): bool
    {
        return $this->writable && !$this->closed;
    }

    public function write($data): bool
    {
        if (!$this->writable) {
            return false;
        }

        return $this->source->write($data);
    }

    public function end($data = null): void
    {
        if (!$this->writable) {
            return;
        }

        if ($data !== null) {
            $this->source->write($data);
        }

        $this->writable = false;
        $this->source->end();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->writable = false;
        $this->closed = true;
        $this->source->close();

        $this->emit('close');
        $this->removeAllListeners();
    }

    public function source(): ReadableStreamInterface
    {
        return $this->source;
    }

    public function complete(): void
    {
        $this->close();
    }

    public function fail(\Throwable $error): void
    {
        if ($this->closed) {
            return;
        }

        $this->emit('error', [$error]);
        $this->close();
    }
}
