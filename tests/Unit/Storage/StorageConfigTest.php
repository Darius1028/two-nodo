<?php
declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Exception\InvalidConfigurationException;
use App\Storage\StorageConfig;
use PHPUnit\Framework\TestCase;

final class StorageConfigTest extends TestCase
{
    private mixed $previousEndpoint;

    protected function setUp(): void
    {
        $this->previousEndpoint = $_ENV['MINIO_ENDPOINT'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousEndpoint === null) {
            unset($_ENV['MINIO_ENDPOINT']);
        } else {
            $_ENV['MINIO_ENDPOINT'] = $this->previousEndpoint;
        }
    }

    public function testAcceptsInternalHttpEndpointAndTrimsTrailingSlash(): void
    {
        $_ENV['MINIO_ENDPOINT'] = 'http://minio:9000/';

        self::assertSame('http://minio:9000', StorageConfig::endpoint());
    }

    public function testRejectsCredentialsEmbeddedInEndpoint(): void
    {
        $_ENV['MINIO_ENDPOINT'] = 'https://user:password@minio.example';

        $this->expectException(InvalidConfigurationException::class);
        StorageConfig::endpoint();
    }
}
