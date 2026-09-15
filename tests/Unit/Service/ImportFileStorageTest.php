<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Exception\StorageException;
use App\Service\ImportFileStorage;
use App\Storage\LocalObjectStorage;
use PHPUnit\Framework\TestCase;

final class ImportFileStorageTest extends TestCase
{
    private string $root;
    private mixed $previousBucket;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/academic-import-storage-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0770, true);
        $this->previousBucket = $_ENV['MINIO_IMPORT_BUCKET'] ?? null;
        $_ENV['MINIO_IMPORT_BUCKET'] = 'test-imports';
    }

    protected function tearDown(): void
    {
        if ($this->previousBucket === null) {
            unset($_ENV['MINIO_IMPORT_BUCKET']);
        } else {
            $_ENV['MINIO_IMPORT_BUCKET'] = $this->previousBucket;
        }
        $this->removeTree($this->root);
    }

    public function testStoredImportCanBeDownloadedAndVerified(): void
    {
        $source = $this->root . '/source.csv';
        file_put_contents($source, "Proceso,Curso\nP,C\n");
        $service = new ImportFileStorage(new LocalObjectStorage($this->root . '/objects'));

        $stored = $service->store($source, 'original.csv');
        $download = $service->downloadTemporary(
            $stored->bucket,
            $stored->key,
            $stored->versionId,
            $stored->size,
            $stored->sha256
        );

        try {
            self::assertSame(file_get_contents($source), file_get_contents($download));
        } finally {
            @unlink($download);
        }
    }

    public function testDownloadRejectsUnexpectedHash(): void
    {
        $source = $this->root . '/source.csv';
        file_put_contents($source, "a,b\n1,2\n");
        $service = new ImportFileStorage(new LocalObjectStorage($this->root . '/objects'));
        $stored = $service->store($source, 'original.csv');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('SHA-256');
        $service->downloadTemporary(
            $stored->bucket,
            $stored->key,
            null,
            $stored->size,
            str_repeat('0', 64)
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
