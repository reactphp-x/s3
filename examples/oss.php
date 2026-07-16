<?php

require __DIR__ . '/../vendor/autoload.php';

use ReactphpX\S3\Client;

$accessKeyId = '';
$accessKeySecret = '';
$bucket = 'xxxx';
$endpoint = 'https://oss-cn-beijing.aliyuncs.com';

$client = new Client($bucket, [
    'endpoint' => $endpoint,
    'version' => '2006-03-01',
    'region' => 'cn-beijing',
    'use_path_style_endpoint' => false,
    'credentials' => [
        'key' => $accessKeyId,
        'secret' => $accessKeySecret,
    ],
]);

$key = 'abc/example.txt';

$client->write($key, 'Hello OSS!')->then(function () use ($key) {
    echo "Uploaded to OSS: {$key}\n";
});

$client->read($key)->then(function ($content) {
    echo "Content: {$content}\n";
});

$client->list('abc/')->then(function ($result) {
    foreach ($result->objects as $object) {
        echo "Object: {$object->key}\n";
    }
});
