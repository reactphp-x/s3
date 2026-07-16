<?php

namespace ReactphpX\S3;

final class ObjectStat
{
    public function __construct(
        public readonly string $key,
        public readonly ?int $size = null,
        public readonly ?\DateTimeInterface $lastModified = null,
        public readonly ?string $contentType = null,
        public readonly ?string $etag = null,
    ) {
    }
}
