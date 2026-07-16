<?php

declare(strict_types=1);

namespace ReactphpX\S3\Tests;

use React\Promise\Promise;
use React\Promise\Stream;

final class ClientIntegrationTest extends IntegrationTestCase
{
    public function testWriteAndRead(): void
    {
        $key = $this->key('hello.txt');
        $payload = 'Hello OSS ' . uniqid('', true);

        $bytes = $this->await($this->client->write($key, $payload));
        $this->assertSame(strlen($payload), $bytes);

        $content = $this->await($this->client->read($key));
        $this->assertSame($payload, $content);
    }

    public function testWriteStreamAndReadStream(): void
    {
        $key = $this->key('stream.bin');
        $payload = 'helloworld';

        $stream = $this->client->writeStream($key, strlen($payload));
        $stream->write('hello');
        $stream->end('world');

        $this->await(new Promise(function ($resolve, $reject) use ($stream) {
            $stream->on('close', static function () use ($resolve): void {
                $resolve(null);
            });
            $stream->on('error', $reject);
        }));

        $body = $this->client->readStream($key);
        $content = $this->await(Stream\buffer($body));
        $this->assertSame($payload, $content);
    }

    public function testHeadExistsAndListUnderTestPrefix(): void
    {
        $key = $this->key('meta.json');
        $payload = '{"ok":true}';

        $this->await($this->client->write($key, $payload));

        $this->assertTrue($this->await($this->client->exists($key)));

        $stat = $this->await($this->client->head($key));
        $this->assertSame($key, $stat->key);
        $this->assertSame(strlen($payload), $stat->size);
        $this->assertSame('application/json', $stat->contentType);

        $result = $this->await($this->client->list($this->testPrefix));
        $keys = array_map(static fn ($object) => $object->key, $result->objects);
        $this->assertContains($key, $keys);
    }

    public function testReadWithRange(): void
    {
        $key = $this->key('range.txt');
        $payload = '0123456789';

        $this->await($this->client->write($key, $payload));

        $chunk = $this->await($this->client->read($key, 2, 4));
        $this->assertSame('2345', $chunk);
    }

    public function testPresignedUrl(): void
    {
        $key = $this->key('signed.txt');
        $this->await($this->client->write($key, 'signed'));

        $url = $this->client->presignedUrl($key, '+15 minutes');
        $this->assertStringContainsString($key, $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }
}
