<?php

declare(strict_types=1);

namespace ReactphpX\S3\Tests;

use PHPUnit\Framework\TestCase;
use ReactphpX\S3\MimeType;

final class MimeTypeTest extends TestCase
{
    public function testFromKeyUsesKnownExtension(): void
    {
        $this->assertSame('text/plain', MimeType::fromKey('readme.txt'));
        $this->assertSame('image/jpeg', MimeType::fromKey('photo.JPG'));
    }

    public function testFromKeyFallsBackToOctetStream(): void
    {
        $this->assertSame('application/octet-stream', MimeType::fromKey('blob.unknownext'));
        $this->assertSame('application/octet-stream', MimeType::fromKey('no-extension'));
    }
}
