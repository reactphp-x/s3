# reactphp-x/s3

基于 ReactPHP 的异步 S3 客户端，兼容 AWS S3 及 OSS、MinIO 等 S3 协议存储。

底层使用 AWS SDK for PHP，HTTP 层通过 `react/http` 异步发送，适合在 ReactPHP 事件循环中直接使用。

## 安装

```bash
composer require reactphp-x/s3
```

## 快速开始

```php
<?php

require 'vendor/autoload.php';

use ReactphpX\S3\Client;

$client = new Client('my-bucket', [
    'version' => 'latest',
    'region' => 'us-east-1',
    'credentials' => [
        'key' => 'YOUR_KEY',
        'secret' => 'YOUR_SECRET',
    ],
]);

// 上传
$client->write('uploads/hello.txt', 'Hello World!')->then(function ($bytes) {
    echo "Uploaded {$bytes} bytes\n";
});

// 读取
$client->read('uploads/hello.txt')->then(function ($content) {
    echo $content;
});

// 获取元信息
$client->head('uploads/hello.txt')->then(function ($stat) {
    echo $stat->size, ' ', $stat->contentType;
});

// 判断是否存在
$client->exists('uploads/hello.txt')->then(function ($exists) {
    echo $exists ? 'yes' : 'no';
});

// 列出对象
$client->list('uploads/')->then(function ($result) {
    foreach ($result->objects as $object) {
        echo $object->key, "\n";
    }
    foreach ($result->prefixes as $prefix) {
        echo $prefix, "/\n";
    }
});

// 删除
$client->delete('uploads/hello.txt')->then(function () {
    echo "deleted\n";
});

// 流式上传
$stream = $client->writeStream('uploads/file.bin', 10);
$stream->write('hello');
$stream->end('world');

$stream->on('error', function ($error) {
    echo $error->getMessage(), "\n";
});

$stream->on('close', function () {
    echo "done\n";
});

// 流式下载
$body = $client->readStream('uploads/file.bin');
$body->on('data', function (string $chunk) {
    echo strlen($chunk), " bytes\n";
});
$body->on('error', function ($error) {
    echo $error->getMessage(), "\n";
});
$body->on('close', function () {
    echo "done\n";
});
```

流式实现遵循 [react/http streaming response](https://github.com/reactphp/http#streaming-response) 与 [streaming request](https://github.com/reactphp/http#streaming-request)：

- `readStream()` 通过 `Browser::requestStreaming()` 接收响应，body 同时实现 `ReadableStreamInterface`
- `writeStream()` 返回可写流，调用 `write()` / `end()` 推送数据；已知大小时传入 `$size` 设置 `Content-Length`，否则使用 chunked

## 兼容 OSS / MinIO

```php
$client = new Client('my-bucket', [
    'endpoint' => 'https://oss-cn-beijing.aliyuncs.com',
    'version' => '2006-03-01',
    'region' => 'cn-beijing',
    'use_path_style_endpoint' => false,
    'credentials' => [
        'key' => 'YOUR_KEY',
        'secret' => 'YOUR_SECRET',
    ],
]);
```

MinIO 示例：

```php
$client = new Client('my-bucket', [
    'endpoint' => 'http://127.0.0.1:9000',
    'version' => 'latest',
    'region' => 'us-east-1',
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key' => 'minioadmin',
        'secret' => 'minioadmin',
    ],
]);
```

## API

| 方法 | 说明 | 返回值 |
|------|------|--------|
| `write($key, $body, $options = [])` | 上传对象（支持字符串、PSR-7 流、React 流） | `Promise<int\|null>` 写入字节数 |
| `writeStream($key, $size = null, $options = [])` | 流式上传，返回可写流 | `WritableStreamInterface` |
| `read($key, $offset = 0, $maxlen = null)` | 读取对象，支持 Range | `Promise<string>` |
| `readStream($key, $offset = 0, $maxlen = null)` | 流式读取 | `ReadableStreamInterface` |
| `head($key)` | 获取对象元信息 | `Promise<ObjectStat>` |
| `exists($key)` | 判断对象是否存在 | `Promise<bool>` |
| `delete($key)` | 删除对象 | `Promise<true>` |
| `deleteMany($keys)` | 批量删除 | `Promise<true>` |
| `list($prefix = '', $delimiter = '/', $token = null)` | 列出对象 | `Promise<ListResult>` |
| `presignedUrl($key, $expires = '+1 hour')` | 生成预签名 URL | `string` |
| `s3()` | 获取底层 `S3Client` | `S3Client` |

## 高级用法

除 `readStream()`、`writeStream()` 和 `presignedUrl()` 外，其余方法返回 `React\Promise\PromiseInterface`，可链式组合：

```php
$client->write('a.txt', 'A')
    ->then(fn () => $client->write('b.txt', 'B'))
    ->then(fn () => $client->list(''))
    ->then(function ($result) {
        foreach ($result->objects as $object) {
            echo $object->key, "\n";
        }
    });
```

需要更底层控制时，可通过 `$client->s3()` 访问 AWS SDK 原生客户端，自行调用 `*Async` 方法并配合 Guzzle Promise 队列使用。

流式上传使用 `UNSIGNED-PAYLOAD` 签名，已知大小时请传入 `$size` 以便设置 `Content-Length`（与 react/http 行为一致）。

## License

MIT
