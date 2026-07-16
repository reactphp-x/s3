<?php

namespace ReactphpX\S3;

use Aws\S3\S3Client;
use Aws\Signature\SignatureV4;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface as GuzzlePromiseInterface;
use Psr\Http\Message\StreamInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Promise\Stream;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

final class Client
{
    private S3Client $s3;
    private Poll $poll;
    private Browser $browser;
    private LoopInterface $loop;

    public function __construct(
        private readonly string $bucket,
        array $s3Options = [],
        ?LoopInterface $loop = null,
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->browser = new Browser(null, $this->loop);
        $this->s3 = new S3Client(array_merge([
            'http_handler' => HandlerStack::create(new HttpClientAdapter($this->browser)),
        ], $s3Options));
        $this->poll = new Poll($this->loop);
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function s3(): S3Client
    {
        return $this->s3;
    }

    /**
     * @param string|StreamInterface|ReadableStreamInterface $body
     */
    public function write(string $key, $body, array $options = []): PromiseInterface
    {
        if ($body instanceof ReadableStreamInterface) {
            $size = $options['ContentLength'] ?? null;
            unset($options['ContentLength']);

            $dest = $this->writeStream($key, is_int($size) ? $size : null, $options);
            $body->pipe($dest);

            return new Promise(function ($resolve, $reject) use ($dest, $body, $size) {
                $dest->on('close', function () use ($resolve, $size) {
                    $resolve($size);
                });
                $dest->on('error', $reject);
                $body->on('error', function (\Throwable $error) use ($reject, $dest) {
                    $dest->close();
                    $reject($error);
                });
            });
        }

        $params = array_merge([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => MimeType::fromKey($key),
        ], $options);

        return $this->execute(function () use ($params) {
            return $this->s3->putObjectAsync($params);
        })->then(static function ($result) use ($body) {
            if (is_string($body)) {
                return strlen($body);
            }

            return $result['@metadata']['headers']['content-length'] ?? null;
        });
    }

    public function read(string $key, int $offset = 0, ?int $maxlen = null): PromiseInterface
    {
        return Stream\buffer($this->readStream($key, $offset, $maxlen));
    }

    /**
     * @see https://github.com/reactphp/http#streaming-response
     */
    public function readStream(string $key, int $offset = 0, ?int $maxlen = null): ReadableStreamInterface
    {
        $params = $this->readParams($key, $offset, $maxlen);
        $params['@http'] = ['stream' => true];

        return Stream\unwrapReadable(
            $this->execute(function () use ($params) {
                return $this->s3->getObjectAsync($params);
            })->then(static fn ($result) => $result['Body'])
        );
    }

    /**
     * @see https://github.com/reactphp/http#streaming-request
     */
    public function writeStream(string $key, ?int $size = null, array $options = []): WritableStreamInterface
    {
        $stream = new UploadStream();

        $params = array_merge([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => new ReadableStreamBody($stream->source(), $size),
            'ContentType' => MimeType::fromKey($key),
            'ContentSHA256' => SignatureV4::UNSIGNED_PAYLOAD,
        ], $options);

        $params['@context'] = array_merge(
            ['request_checksum_calculation' => 'when_required'],
            $params['@context'] ?? [],
        );

        if ($size !== null && !isset($params['ContentLength'])) {
            $params['ContentLength'] = $size;
        }

        $this->execute(function () use ($params) {
            return $this->s3->putObjectAsync($params);
        })->then(
            static function () use ($stream): void {
                $stream->complete();
            },
            static function (\Throwable $error) use ($stream): void {
                $stream->fail($error);
            },
        );

        return $stream;
    }

    public function head(string $key): PromiseInterface
    {
        return $this->execute(function () use ($key) {
            return $this->s3->headObjectAsync([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        })->then(fn ($result) => $this->toObjectStat($key, $result->toArray()['@metadata'] ?? []));
    }

    public function exists(string $key): PromiseInterface
    {
        return $this->head($key)->then(
            static fn () => true,
            static function ($error) {
                if (self::isNotFound($error)) {
                    return false;
                }

                throw $error;
            }
        );
    }

    public function delete(string $key): PromiseInterface
    {
        return $this->execute(function () use ($key) {
            return $this->s3->deleteObjectAsync([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        })->then(static fn () => true);
    }

    /**
     * @param string[] $keys
     */
    public function deleteMany(array $keys): PromiseInterface
    {
        if ($keys === []) {
            return \React\Promise\resolve(true);
        }

        return $this->execute(function () use ($keys) {
            return $this->s3->deleteObjectsAsync([
                'Bucket' => $this->bucket,
                'Delete' => [
                    'Objects' => array_map(static fn (string $key) => ['Key' => $key], $keys),
                ],
            ]);
        })->then(static fn () => true);
    }

    public function list(string $prefix = '', string $delimiter = '/', ?string $continuationToken = null): PromiseInterface
    {
        $params = [
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
            'Delimiter' => $delimiter,
        ];

        if ($continuationToken !== null) {
            $params['ContinuationToken'] = $continuationToken;
        }

        return $this->execute(function () use ($params) {
            return $this->s3->listObjectsV2Async($params);
        })->then(fn ($result) => $this->toListResult($result->toArray(), $prefix));
    }

    public function presignedUrl(string $key, string $expires = '+1 hour', string $command = 'GetObject'): string
    {
        $cmd = $this->s3->getCommand($command, [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        return (string) $this->s3->createPresignedRequest($cmd, $expires)->getUri();
    }

    private function readParams(string $key, int $offset, ?int $maxlen): array
    {
        $params = [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ];

        if ($offset > 0 || $maxlen !== null) {
            $range = 'bytes=' . $offset . '-';
            if ($maxlen !== null) {
                $range .= ($offset + $maxlen - 1);
            }
            $params['Range'] = $range;
        }

        return $params;
    }

    private function execute(callable $callback): PromiseInterface
    {
        $this->poll->activate();

        return new Promise(function ($resolve, $reject) use ($callback) {
            try {
                /** @var GuzzlePromiseInterface $promise */
                $promise = $callback();
                $promise->then(
                    function ($result) use ($resolve) {
                        $this->deferPollDeactivate($result);
                        $resolve($result);
                    },
                    function ($error) use ($reject) {
                        $this->poll->deactivate();
                        $reject($error);
                    }
                );
            } catch (\Throwable $error) {
                $this->poll->deactivate();
                $reject($error);
            }
        });
    }

    private function deferPollDeactivate(mixed $result): void
    {
        $body = null;

        if (is_array($result) && isset($result['Body'])) {
            $body = $result['Body'];
        } elseif (is_object($result) && isset($result['Body'])) {
            $body = $result['Body'];
        }

        if ($body instanceof ReadableStreamInterface) {
            $deactivated = false;
            $deactivate = function () use (&$deactivated): void {
                if ($deactivated) {
                    return;
                }

                $deactivated = true;
                $this->poll->deactivate();
            };

            $body->on('close', $deactivate);
            $body->on('error', $deactivate);

            return;
        }

        $this->poll->deactivate();
    }

    private function toObjectStat(string $key, array $metadata): ObjectStat
    {
        $headers = array_change_key_case($metadata['headers'] ?? [], CASE_LOWER);
        $lastModified = isset($headers['last-modified'])
            ? new \DateTimeImmutable($headers['last-modified'])
            : null;

        return new ObjectStat(
            key: $key,
            size: isset($headers['content-length']) ? (int) $headers['content-length'] : null,
            lastModified: $lastModified,
            contentType: $headers['content-type'] ?? null,
            etag: isset($headers['etag']) ? trim($headers['etag'], '"') : null,
        );
    }

    private function toListResult(array $result, string $prefix): ListResult
    {
        $objects = [];
        $normalizedPrefix = rtrim($prefix, '/') . ($prefix === '' ? '' : '/');

        foreach ($result['Contents'] ?? [] as $object) {
            if ($object['Key'] === $normalizedPrefix || $object['Key'] === rtrim($prefix, '/')) {
                continue;
            }

            $objects[] = new ObjectStat(
                key: $object['Key'],
                size: isset($object['Size']) ? (int) $object['Size'] : null,
                lastModified: isset($object['LastModified'])
                    ? new \DateTimeImmutable($object['LastModified'])
                    : null,
                etag: isset($object['ETag']) ? trim($object['ETag'], '"') : null,
            );
        }

        $prefixes = [];
        foreach ($result['CommonPrefixes'] ?? [] as $entry) {
            $prefixes[] = rtrim($entry['Prefix'], '/');
        }

        return new ListResult(
            objects: $objects,
            prefixes: $prefixes,
            isTruncated: (bool) ($result['IsTruncated'] ?? false),
            nextContinuationToken: $result['NextContinuationToken'] ?? null,
        );
    }

    private static function isNotFound(\Throwable $error): bool
    {
        if ($error instanceof \Aws\S3\Exception\S3Exception) {
            return $error->getStatusCode() === 404;
        }

        return str_contains($error->getMessage(), '404');
    }
}
