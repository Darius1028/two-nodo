<?php
declare(strict_types=1);

namespace App\Tests\Integration\Storage;

use App\Storage\S3ObjectStorage;
use App\Storage\StorageConfig;
use PHPUnit\Framework\TestCase;

final class S3ObjectStorageTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('RUN_MINIO_INTEGRATION') !== '1') {
            self::markTestSkipped('Defina RUN_MINIO_INTEGRATION=1 para probar MinIO real.');
        }
    }

    public function testRoundTripAgainstMinio(): void
    {
        $source = tempnam(sys_get_temp_dir(), 's3-source-');
        $download = tempnam(sys_get_temp_dir(), 's3-download-');
        self::assertNotFalse($source);
        self::assertNotFalse($download);
        file_put_contents($source, 'minio-round-trip-' . random_bytes(16));

        $storage = S3ObjectStorage::fromEnvironment();
        $bucket = StorageConfig::importBucket();
        $key = 'integration-tests/' . bin2hex(random_bytes(12)) . '.bin';

        try {
            $storage->checkBucket($bucket);
            $stored = $storage->putFile($bucket, $key, $source);
            $downloaded = $storage->downloadTo($bucket, $key, $download);
            self::assertSame($stored->sha256, $downloaded->sha256);
            self::assertSame(file_get_contents($source), file_get_contents($download));
        } finally {
            try {
                $storage->delete($bucket, $key);
            } catch (\Throwable) {
            }
            @unlink($source);
            @unlink($download);
        }
    }

    public function testVersionedAssetCanBeReadByExactVersion(): void
    {
        $first = tempnam(sys_get_temp_dir(), 's3-version-one-');
        $second = tempnam(sys_get_temp_dir(), 's3-version-two-');
        $download = tempnam(sys_get_temp_dir(), 's3-version-download-');
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        self::assertNotFalse($download);
        file_put_contents($first, 'asset-version-one-' . random_bytes(8));
        file_put_contents($second, 'asset-version-two-' . random_bytes(8));

        $storage = S3ObjectStorage::fromEnvironment();
        $bucket = StorageConfig::assetBucket();
        $key = 'integration-tests/' . bin2hex(random_bytes(12)) . '.bin';
        $versionOne = null;
        $versionTwo = null;

        try {
            $storage->checkBucket($bucket);
            $storedOne = $storage->putFile($bucket, $key, $first);
            $versionOne = $storedOne->versionId;
            $storedTwo = $storage->putFile($bucket, $key, $second);
            $versionTwo = $storedTwo->versionId;

            self::assertNotNull($versionOne);
            self::assertNotSame('', $versionOne);
            self::assertNotNull($versionTwo);
            self::assertNotSame($versionOne, $versionTwo);

            $storage->downloadTo($bucket, $key, $download, $versionOne);
            self::assertSame(file_get_contents($first), file_get_contents($download));
        } finally {
            foreach (array_filter([$versionOne, $versionTwo]) as $versionId) {
                try {
                    $storage->delete($bucket, $key, $versionId);
                } catch (\Throwable) {
                }
            }
            @unlink($first);
            @unlink($second);
            @unlink($download);
        }
    }
}
