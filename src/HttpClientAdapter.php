<?php

namespace ReactphpX\S3;

use GuzzleHttp\Promise\Promise;
use Psr\Http\Message\RequestInterface;
use React\Http\Browser;
use React\Stream\ReadableStreamInterface;

/**
 * AWS SDK http_handler backed by react/http Browser.
 *
 * @see https://github.com/reactphp/http#streaming-response
 * @see https://github.com/reactphp/http#streaming-request
 */
final class HttpClientAdapter
{
    public function __construct(
        private readonly Browser $browser,
    ) {
    }

    public function __invoke(RequestInterface $request, array $options)
    {
        $request = $request->withHeader('Expect', '');

        $promise = new Promise();

        $method = $request->getMethod();
        $url = (string) $request->getUri();
        $headers = $request->getHeaders();
        $body = $request->getBody();

        $http = !empty($options['stream'])
            ? $this->browser->requestStreaming($method, $url, $headers, $this->normalizeBody($body))
            : $this->browser->request($method, $url, $headers, $this->normalizeBody($body));

        $http->then(
            function ($response) use ($promise, $options) {
                if (!empty($options['stream'])) {
                    $source = $response->getBody();
                    $buffered = new BufferingBodyStream(
                        $source instanceof \Psr\Http\Message\StreamInterface ? $source->getSize() : null,
                    );
                    if ($source instanceof \React\Stream\ReadableStreamInterface) {
                        $buffered->attach($source);
                    }
                    $response = $response->withBody($buffered);
                }

                $promise->resolve($response);
            },
            function ($error) use ($promise) {
                $promise->reject($error);
            }
        );

        return $promise;
    }

    /**
     * react/http accepts string|ReadableStreamInterface|StreamInterface.
     * Prefer passing ReadableStreamInterface through unchanged for streaming uploads.
     */
    private function normalizeBody($body): string|ReadableStreamInterface
    {
        if ($body instanceof ReadableStreamInterface) {
            return $body;
        }

        return (string) $body;
    }
}
