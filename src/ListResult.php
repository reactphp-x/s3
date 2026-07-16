<?php

namespace ReactphpX\S3;

final class ListResult
{
    /**
     * @param ObjectStat[] $objects
     * @param string[] $prefixes
     */
    public function __construct(
        public readonly array $objects,
        public readonly array $prefixes,
        public readonly bool $isTruncated = false,
        public readonly ?string $nextContinuationToken = null,
    ) {
    }
}
