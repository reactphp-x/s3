<?php

require __DIR__ . '/../vendor/autoload.php';

use ReactphpX\S3\Client;

$bucket = 'xxxx';
$client = new Client($bucket, [
    'endpoint' => 'xxxx',
    'version' => 'latest',
    'region' => 'us-east-1',
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key' => 'xxx',
        'secret' => 'xxxx',
    ],
]);

$key = 'uploads/example.txt';

$client->write($key, 'Hello World!')->then(function ($bytes) use ($key) {
    echo "Uploaded {$bytes} bytes to {$key}\n";
}, function ($error) {
    echo 'Upload failed: ' . $error->getMessage() . "\n";
});

$client->read($key)->then(function ($content) {
    echo "Content: {$content}\n";
}, function ($error) {
    echo 'Read failed: ' . $error->getMessage() . "\n";
});

$client->head($key)->then(function ($stat) {
    echo "Size: {$stat->size}, Type: {$stat->contentType}\n";
}, function ($error) {
    echo 'Head failed: ' . $error->getMessage() . "\n";
});

$client->list('uploads/')->then(function ($result) {
    foreach ($result->objects as $object) {
        echo "Object: {$object->key} ({$object->size} bytes)\n";
    }
    foreach ($result->prefixes as $prefix) {
        echo "Prefix: {$prefix}/\n";
    }
}, function ($error) {
    echo 'List failed: ' . $error->getMessage() . "\n";
});

// $client->delete($key)->then(function () use ($key) {
//     echo "Deleted {$key}\n";
// });
