<?php
declare(strict_types=1);

namespace App\Storage;

interface ObjectStorageInterface
{
    public function checkBucket(string $bucket): void;

    /**
     * @param array<string, string> $metadata
     */
    public function putFile(
        string $bucket,
        string $key,
        string $localPath,
        string $contentType = 'application/octet-stream',
        array $metadata = []
    ): StoredObject;

    public function downloadTo(
        string $bucket,
        string $key,
        string $localPath,
        ?string $versionId = null
    ): StoredObject;

    public function stat(string $bucket, string $key, ?string $versionId = null): StoredObject;

    public function delete(string $bucket, string $key, ?string $versionId = null): void;
}
