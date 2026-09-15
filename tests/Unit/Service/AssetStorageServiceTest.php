<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AssetStorageService;
use App\Service\ConfigService;
use App\Storage\LocalObjectStorage;
use PHPUnit\Framework\TestCase;

final class AssetStorageServiceTest extends TestCase
{
    private string $root;
    private string $configPath;
    private string $configBackup;
    private mixed $previousConfigStorage;
    private mixed $previousBucket;
    private ?string $cachedAsset = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/academic-asset-storage-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0770, true);
        $this->configPath = dirname(__DIR__, 3) . '/config/config.json';
        $this->configBackup = (string) file_get_contents($this->configPath);
        $this->previousConfigStorage = $_ENV['CONFIG_STORAGE'] ?? null;
        $this->previousBucket = $_ENV['MINIO_ASSET_BUCKET'] ?? null;
        $_ENV['CONFIG_STORAGE'] = 'file';
        $_ENV['MINIO_ASSET_BUCKET'] = 'test-assets';
        ConfigService::resetCache();
    }

    protected function tearDown(): void
    {
        file_put_contents($this->configPath, $this->configBackup);
        ConfigService::resetCache();
        $this->restoreEnv('CONFIG_STORAGE', $this->previousConfigStorage);
        $this->restoreEnv('MINIO_ASSET_BUCKET', $this->previousBucket);
        if ($this->cachedAsset !== null) {
            @unlink($this->cachedAsset);
            @unlink($this->cachedAsset . '.lock');
        }
        $this->removeTree($this->root);
    }

    public function testJpegUploadIsNormalizedAndResolvedAsPng(): void
    {
        $jpeg = $this->root . '/source.jpg';
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 80, 140));
        imagejpeg($image, $jpeg, 90);
        imagedestroy($image);

        $service = new AssetStorageService(new LocalObjectStorage($this->root . '/objects'));
        $stored = $service->upload('letterhead', $jpeg);
        self::assertSame('image/png', $stored->contentType);
        self::assertStringEndsWith('.png', $stored->key);

        $this->cachedAsset = $service->resolveLocalPath('letterhead');
        self::assertNotNull($this->cachedAsset);
        self::assertSame("\x89PNG\r\n\x1a\n", file_get_contents($this->cachedAsset, false, null, 0, 8));
        self::assertSame($stored->sha256, hash_file('sha256', $this->cachedAsset));
    }

    private function restoreEnv(string $name, mixed $value): void
    {
        if ($value === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $value;
        }
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
