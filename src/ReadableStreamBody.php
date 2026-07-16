<?php

namespace ReactphpX\S3;

use Evenement\EventEmitter;
use Psr\Http\Message\StreamInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

/**
 * Bridges a React readable stream into a PSR-7 body for AWS SDK and react/http.
 */
final class ReadableStreamBody extends EventEmitter implements StreamInterface, ReadableStreamInterface
{
    /** @var string[] */
    private array $chunks = [];
    private bool $ended = false;
    private bool $closed = false;

    public function __construct(
        private readonly ReadableStreamInterface $input,
        private readonly ?int $size = null,
    ) {
        $this->input->on('data', function (string $data): void {
            if ($this->listeners('data') !== []) {
                $this->emit('data', [$data]);
            } else {
                $this->chunks[] = $data;
            }
        });
        $this->input->on('end', function (): void {
            $this->ended = true;
            $this->flushEnd();
        });
        $this->input->on('error', function (\Throwable $error): void {
            $this->handleError($error);
        });
        $this->input->on('close', function (): void {
            if (!$this->ended) {
                $this->close();
            }
        });
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
        $this->input->pause();
    }

    public function resume(): void
    {
        $this->input->resume();
    }

    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->chunks = [];
        if ($this->input->isReadable()) {
            $this->input->close();
        }
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
        throw new \BadMethodCallException('Stream is not seekable');
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
        throw new \BadMethodCallException('Stream is not seekable');
    }

    public function rewind(): void
    {
        throw new \BadMethodCallException('Stream is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \BadMethodCallException('Stream is not writable');
    }

    public function read($length): string
    {
        throw new \BadMethodCallException('Use as a React readable stream');
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }

    private function handleError(\Throwable $error): void
    {
        $this->emit('error', [$error]);
        $this->close();
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
