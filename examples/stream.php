<?php

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Stream\WritableResourceStream;
use ReactphpX\S3\Client;

$loop = Loop::get();
$client = new Client('my-bucket', [
    'version' => 'latest',
    'region' => 'us-east-1',
    'credentials' => [
        'key' => 'YOUR_KEY',
        'secret' => 'YOUR_SECRET',
    ],
], $loop);

$key = 'folder/image.jpg';

// Streaming upload: write to the returned stream
$stream = $client->writeStream($key, 10);

$stream->write('hello');
$stream->end('world');

$stream->on('error', function ($error) {
    echo 'Error: ' . $error->getMessage() . PHP_EOL;
});

$stream->on('close', function () {
    echo '[CLOSED]' . PHP_EOL;
});

// Streaming download
$localFile = '/tmp/large.bin';
$body = $client->readStream($key);
$destination = new WritableResourceStream(fopen($localFile . '.copy', 'wb'), $loop);
$body->pipe($destination);

$body->on('error', function ($error) {
    echo 'Download failed: ' . $error->getMessage() . "\n";
});

$destination->on('close', function () {
    echo "Download finished\n";
});
