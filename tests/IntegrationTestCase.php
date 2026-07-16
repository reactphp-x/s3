<?php

declare(strict_types=1);

namespace ReactphpX\S3\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Promise\Stream;
use ReactphpX\S3\Client;

abstract class IntegrationTestCase extends TestCase
{
    protected LoopInterface $loop;

    protected Client $client;

    /** @var string[] keys created during the test, deleted in tearDown */
    protected array $createdKeys = [];

    protected string $testPrefix;

    protected function setUp(): void
    {
        $bucket = getenv('AWS_BUCKET') ?: '';
        $key = getenv('AWS_ACCESS_KEY_ID') ?: '';
        $secret = getenv('AWS_SECRET_ACCESS_KEY') ?: '';

        if ($bucket === '' || $key === '' || $secret === '') {
            $this->markTestSkipped('Set AWS_BUCKET, AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY to run integration tests.');
        }

        $this->loop = Loop::get();
        $this->testPrefix = 'reactphp-x-s3-tests/' . bin2hex(random_bytes(8)) . '/';

        $this->client = new Client($bucket, [
            'version' => '2006-03-01',
            'region' => getenv('AWS_DEFAULT_REGION') ?: 'us-east-1',
            'endpoint' => getenv('AWS_ENDPOINT') ?: null,
            'use_path_style_endpoint' => filter_var(
                getenv('AWS_USE_PATH_STYLE_ENDPOINT') ?: 'true',
                FILTER_VALIDATE_BOOL,
            ),
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ], $this->loop);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdKeys as $key) {
            try {
                $this->await($this->client->delete($key));
            } catch (\Throwable) {
                // best-effort cleanup for objects created in this test only
            }
        }
    }

    protected function key(string $name): string
    {
        $key = $this->testPrefix . ltrim($name, '/');
        $this->createdKeys[] = $key;

        return $key;
    }

    protected function await(PromiseInterface $promise): mixed
    {
        $result = null;
        $error = null;

        $promise->then(
            function ($value) use (&$result) {
                $result = $value;
                $this->loop->stop();
            },
            function ($reason) use (&$error) {
                $error = $reason;
                $this->loop->stop();
            }
        );

        $this->loop->run();

        if ($error !== null) {
            if ($error instanceof \Throwable) {
                throw $error;
            }

            throw new \RuntimeException((string) $error);
        }

        return $result;
    }

    protected function awaitStream(string $content): string
    {
        return $this->await(Stream\buffer($content));
    }
}
