<?php

declare(strict_types=1);

namespace ReactphpX\S3\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use ReactphpX\S3\BufferingBodyStream;
use ReactphpX\S3\ReadableStreamBody;

final class StreamBodyTest extends TestCase
{
    public function testBufferingBodyReplaysCompletionToLateListeners(): void
    {
        $source = new ThroughStream();
        $body = new BufferingBodyStream();
        $body->attach($source);

        $this->assertLateCompletion($source, $body);
    }

    public function testReadableBodyReplaysCompletionToLateListeners(): void
    {
        $source = new ThroughStream();
        $body = new ReadableStreamBody($source, 10);

        $this->assertLateCompletion($source, $body);
    }

    private function assertLateCompletion(ThroughStream $source, ReadableStreamInterface $body): void
    {
        $source->write('hello');
        $source->end('world');

        $content = '';
        $ended = false;
        $closed = false;

        $body->on('data', static function (string $chunk) use (&$content): void {
            $content .= $chunk;
        });
        $body->once('end', static function () use (&$ended): void {
            $ended = true;
        });
        $body->on('close', static function () use (&$closed): void {
            $closed = true;
        });

        Loop::run();

        self::assertSame('helloworld', $content);
        self::assertTrue($ended);
        self::assertTrue($closed);
    }
}
