<?php
declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Exception\StorageException;
use App\Storage\LocalObjectStorage;
use PHPUnit\Framework\TestCase;

final class LocalObjectStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/academic-storage-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testRoundTripAndDeletePreserveIntegrity(): void
    {
        $source = $this->root . '/source.csv';
        $download = $this->root . '/download.csv';
        file_put_contents($source, "a,b\n1,2\n");
        $storage = new LocalObjectStorage($this->root . '/objects');

        $stored = $storage->putFile('test-bucket', 'imports/one.csv', $source, 'text/csv');
        self::assertSame(hash_file('sha256', $source), $stored->sha256);
        self::assertSame(filesize($source), $stored->size);

        $storage->downloadTo('test-bucket', 'imports/one.csv', $download);
        self::assertSame(file_get_contents($source), file_get_contents($download));
        self::assertSame($stored->sha256, $storage->stat('test-bucket', 'imports/one.csv')->sha256);

        $storage->delete('test-bucket', 'imports/one.csv');
        $this->expectException(StorageException::class);
        $storage->stat('test-bucket', 'imports/one.csv');
    }

    public function testRejectsTraversalKeys(): void
    {
        $source = $this->root . '/source.txt';
        file_put_contents($source, 'x');

        $this->expectException(StorageException::class);
        (new LocalObjectStorage($this->root . '/objects'))
            ->putFile('test-bucket', '../outside.txt', $source);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
