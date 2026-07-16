<?php

namespace ReactphpX\S3;

use Evenement\EventEmitter;
use Psr\Http\Message\StreamInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

/**
 * Captures response body data until a consumer is ready.
 *
 * Used to avoid losing early chunks when async promise callbacks attach late.
 */
final class BufferingBodyStream extends EventEmitter implements ReadableStreamInterface, StreamInterface
{
    /** @var string[] */
    private array $chunks = [];
    private bool $ended = false;
    private bool $closed = false;
    private ?\Throwable $error = null;
    private ?int $size;
    private bool $attached = false;

    public function __construct(
        ?int $size = null,
    ) {
        $this->size = $size;
    }

    public function attach(ReadableStreamInterface $input): void
    {
        if ($this->attached) {
            return;
        }

        $this->attached = true;

        if ($this->size === null && $input instanceof StreamInterface) {
            $this->size = $input->getSize();
        }

        $input->on('data', function (string $data): void {
            if ($this->listeners('data') !== []) {
                $this->emit('data', [$data]);
            } else {
                $this->chunks[] = $data;
            }
        });

        $input->on('end', function (): void {
            $this->ended = true;
            $this->flushEnd();
        });

        $input->on('error', function (\Throwable $error): void {
            $this->error = $error;
            $this->emit('error', [$error]);
            $this->close();
        });

        $input->on('close', function (): void {
            if (!$this->ended) {
                $this->close();
            }
        });

        if (!$input->isReadable()) {
            $this->ended = true;
            $this->flushEnd();
        }
    }

    public function on($event, callable $listener)
    {
        parent::on($event, $listener);

        if ($event === 'data' && $this->chunks !== []) {
            $chunks = $this->chunks;
            $this->chunks = [];

            foreach ($chunks as $chunk) {
                $this->emit('data', [$chunk]);
            }
        }

        if ($event === 'end') {
            $this->flushEnd();
        }

        return $this;
    }

    public function isReadable(): bool
    {
        return !$this->closed && (!$this->ended || $this->chunks !== []);
    }

    public function pause(): void
    {
    }

    public function resume(): void
    {
    }

    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return Util::pipe($this, $dest, $options);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->chunks = [];
        $this->emit('close');
        $this->removeAllListeners();
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function __toString(): string
    {
        return '';
    }

    public function detach()
    {
        return null;
    }

    public function tell(): int
    {
        throw new \BadMethodCallException();
    }

    public function eof(): bool
    {
        return !$this->isReadable();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new \BadMethodCallException();
    }

    public function rewind(): void
    {
        throw new \BadMethodCallException();
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \BadMethodCallException();
    }

    public function read($length): string
    {
        throw new \BadMethodCallException();
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }

    private function flushEnd(): void
    {
        if ($this->closed || !$this->ended || $this->chunks !== [] || $this->listeners('end') === []) {
            return;
        }

        $this->emit('end');
        $this->close();
    }
}
