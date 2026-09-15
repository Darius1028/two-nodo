<?php
declare(strict_types=1);

namespace App\Storage;

use Aws\S3\S3Client;

final class S3ClientFactory
{
    private function __construct()
    {
    }

    public static function create(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => StorageConfig::region(),
            'endpoint' => StorageConfig::endpoint(),
            'use_path_style_endpoint' => StorageConfig::pathStyle(),
            'credentials' => [
                'key' => StorageConfig::accessKey(),
                'secret' => StorageConfig::secretKey(),
            ],
            'retries' => 3,
            'http' => [
                'connect_timeout' => StorageConfig::connectTimeout(),
                'timeout' => StorageConfig::requestTimeout(),
                'verify' => StorageConfig::tlsVerify(),
            ],
        ]);
    }
}
