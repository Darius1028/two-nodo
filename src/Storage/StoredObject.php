<?php
declare(strict_types=1);

namespace App\Storage;

readonly class StoredObject
{
    public function __construct(
        public string $bucket,
        public string $key,
        public int $size,
        public string $sha256,
        public string $etag = '',
        public ?string $versionId = null,
        public string $contentType = 'application/octet-stream'
    ) {
    }
}
